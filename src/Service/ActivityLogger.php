<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\ActivityLog;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Writes the activity log (security audit trail). Entries are buffered in memory during the
 * request and persisted in one go from ActivityLogSubscriber on kernel.terminate — after the
 * response is sent — so recording never adds latency to a user action.
 *
 * Recording is gated twice: by the APP_LOG deployment kill-switch, and by the per-centre
 * `audit.log_enabled` setting (its global value applies to centre-less events). None of the
 * public methods ever throws: an audit entry must never break the action it is auditing.
 */
final class ActivityLogger
{
    private const EXPLICIT_REQUEST_ATTR = '_activity_log_explicit';

    /** @var list<ActivityLog> */
    private array $buffer = [];

    private bool $explicit = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestStack $requestStack,
        private readonly TenantContextInterface $tenantContext,
        private readonly AppSettingsInterface $settings,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'bool:APP_LOG')] private readonly bool $masterEnabled,
    ) {}

    /**
     * Records a domain action performed by the current user. Resolves the effective/real user
     * from the security token, the centre from the tenant context (unless one is given), and the
     * client IP from the current request.
     *
     * @param array<string, mixed> $data
     */
    public function record(string $actionType, array $data = [], ?EducationalCentre $centre = null): void
    {
        if (!$this->masterEnabled) {
            return;
        }

        try {
            $request = $this->requestStack->getCurrentRequest();

            [$activeUser, $realUser] = $this->resolveUsers($this->tokenStorage->getToken());
            $centre ??= $this->resolveCentre();

            if (!$this->isEnabledFor($centre)) {
                return;
            }

            $this->buffer[] = new ActivityLog(
                createdAt:         $this->clock->now(),
                ip:                $request?->getClientIp() ?? '0.0.0.0',
                actionType:        $actionType,
                activeUser:        $activeUser,
                realUser:          $realUser,
                educationalCentre: $centre,
                academicYear:      $this->resolveYear($centre),
                data:              $data === [] ? null : $data,
            );

            $this->explicit = true;
            $request?->attributes->set(self::EXPLICIT_REQUEST_ATTR, true);
        } catch (\Throwable $e) {
            $this->logger->error('activity log: record() failed', ['exception' => $e]);
        }
    }

    /**
     * Records the generic "one line per request" entry (method, route, status). Used by
     * ActivityLogSubscriber when no explicit entry was recorded for the request.
     *
     * @param array<string, mixed> $data
     */
    public function recordFromRequest(Request $request, string $actionType, array $data = []): void
    {
        if (!$this->masterEnabled) {
            return;
        }

        try {
            [$activeUser, $realUser] = $this->resolveUsers($this->tokenStorage->getToken());
            $centre                  = $this->resolveCentre();

            if (!$this->isEnabledFor($centre)) {
                return;
            }

            $status = $data['status'] ?? null;

            $this->buffer[] = new ActivityLog(
                createdAt:         $this->clock->now(),
                ip:                $request->getClientIp() ?? '0.0.0.0',
                actionType:        $actionType,
                activeUser:        $activeUser,
                realUser:          $realUser,
                educationalCentre: $centre,
                academicYear:      $this->resolveYear($centre),
                route:             $request->attributes->getString('_route') ?: null,
                method:            $request->getMethod(),
                statusCode:        is_int($status) ? $status : null,
                data:              $data === [] ? null : $data,
            );
        } catch (\Throwable $e) {
            $this->logger->error('activity log: recordFromRequest() failed', ['exception' => $e]);
        }
    }

    /**
     * Records a session event (login, logout, impersonation) using the token carried by the
     * security event rather than the one in storage, which may not be set yet.
     *
     * @param array<string, mixed> $data
     */
    public function recordForToken(?TokenInterface $token, string $actionType, Request $request, array $data = []): void
    {
        if (!$this->masterEnabled) {
            return;
        }

        try {
            [$activeUser, $realUser] = $this->resolveUsers($token);

            // Session events are centre-less; gate them on the global setting value.
            if (!$this->isEnabledFor(null)) {
                return;
            }

            $this->buffer[] = new ActivityLog(
                createdAt:  $this->clock->now(),
                ip:         $request->getClientIp() ?? '0.0.0.0',
                actionType: $actionType,
                activeUser: $activeUser,
                realUser:   $realUser,
                data:       $data === [] ? null : $data,
            );

            $this->explicit = true;
            $request->attributes->set(self::EXPLICIT_REQUEST_ATTR, true);
        } catch (\Throwable $e) {
            $this->logger->error('activity log: recordForToken() failed', ['exception' => $e]);
        }
    }

    /** Persists everything buffered during this request. Called once from kernel.terminate. */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        try {
            foreach ($this->buffer as $entry) {
                $this->em->persist($entry);
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('activity log: flush() failed', ['exception' => $e]);
        } finally {
            $this->buffer   = [];
            $this->explicit = false;
        }
    }

    public function hasBufferedEntries(): bool
    {
        return $this->buffer !== [];
    }

    public function hasExplicitEntries(): bool
    {
        return $this->explicit;
    }

    /** The selected centre, or null when there is no request/session to read it from. */
    private function resolveCentre(): ?EducationalCentre
    {
        if (!$this->requestStack->getCurrentRequest()?->hasSession()) {
            return null;
        }

        return $this->tenantContext->getSelectedCentre();
    }

    private function resolveYear(?EducationalCentre $centre): ?AcademicYear
    {
        if ($centre === null || !$this->requestStack->getCurrentRequest()?->hasSession()) {
            return $centre?->getActiveAcademicYear();
        }

        return $this->tenantContext->getViewYear($centre);
    }

    /**
     * @return array{0: ?Teacher, 1: ?Teacher} [effective user, real user behind an impersonation]
     */
    private function resolveUsers(?TokenInterface $token): array
    {
        if ($token === null) {
            return [null, null];
        }

        $activeUser = $token->getUser();
        $activeUser = $activeUser instanceof Teacher ? $activeUser : null;

        $realUser = null;
        if ($token instanceof SwitchUserToken) {
            $original = $token->getOriginalToken()->getUser();
            $realUser = $original instanceof Teacher ? $original : null;
        }

        return [$activeUser, $realUser];
    }

    private function isEnabledFor(?EducationalCentre $centre): bool
    {
        $value = $centre !== null
            ? $this->settings->getForCentre('audit.log_enabled', $centre)
            : $this->settings->getGlobal('audit.log_enabled');

        return $value !== false;
    }
}
