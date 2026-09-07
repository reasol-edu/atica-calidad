<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\ActivityLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\Firewall\SwitchUserListener;

/**
 * Feeds the activity log on kernel.terminate (after the response is sent):
 *  - every write request (POST/PUT/PATCH/DELETE) and every sensitive read (download/export/report)
 *    gets one generic `http.request` entry, unless a controller already recorded an explicit one;
 *  - every Live Component action gets a `component.<Name>.<action>` entry (this is how folder /
 *    list / section CRUD, folder navigation and document opening — all LiveActions — are audited),
 *    minus a few purely transient UI actions;
 *  - login, logout and impersonation are recorded from the security events.
 *
 * All entries are buffered by ActivityLogger and flushed here.
 */
final class ActivityLogSubscriber
{
    /** Route-name prefixes that never produce an entry. */
    private const EXCLUDED_ROUTE_PREFIXES = ['_profiler', '_wdt', '_error', 'app_login', 'app_logout'];

    /** Substrings that mark a GET route as a sensitive read worth recording. */
    private const SENSITIVE_ROUTE_PATTERNS = ['descargar', 'download', 'export', 'informe', 'report', 'zip'];

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const LIVE_COMPONENT_ROUTE = 'ux_live_component';

    /**
     * Live Component action names that carry no audit value (open/close a confirm box, page a
     * list, restore state from the URL, re-run a filter). Matched against the lower-cased action
     * name by prefix, plus a few exact names.
     */
    private const TRANSIENT_ACTION_PREFIXES = ['cancel', 'start', 'ask', 'sync', 'updated'];
    private const TRANSIENT_ACTION_NAMES    = ['setpage', 'resetpage', 'clearfilters', 'quickrange', 'sortby'];

    public function __construct(
        private readonly ActivityLogger $activityLogger,
        private readonly TokenStorageInterface $tokenStorage,
    ) {}

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $request = $event->getRequest();
            $route   = $request->attributes->getString('_route');
            $status  = $event->getResponse()->getStatusCode();

            $alreadyExplicit = $this->activityLogger->hasExplicitEntries()
                || $request->attributes->getBoolean('_activity_log_explicit');

            if ($alreadyExplicit || $route === '' || $this->isExcludedRoute($route)) {
                return;
            }

            if ($route === self::LIVE_COMPONENT_ROUTE) {
                $this->recordLiveComponentAction($request, $status);

                return;
            }

            $isWrite     = \in_array($request->getMethod(), self::WRITE_METHODS, true);
            $isSensitive = !$isWrite && $this->isSensitiveRoute($route);

            if ($isWrite || $isSensitive) {
                $this->activityLogger->recordFromRequest($request, 'http.request', [
                    'method' => $request->getMethod(),
                    'path'   => $request->getPathInfo(),
                    'status' => $status,
                ]);
            }
        } catch (\Throwable) {
            // Never let auditing break the request lifecycle.
        } finally {
            $this->activityLogger->flush();
        }
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $this->activityLogger->recordForToken($event->getAuthenticatedToken(), 'session.login', $event->getRequest());
    }

    #[AsEventListener]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $username = $event->getPassport()?->getBadge(UserBadge::class)?->getUserIdentifier();

        $this->activityLogger->recordForToken(
            null,
            'session.login_failed',
            $event->getRequest(),
            $username !== null && $username !== '' ? ['username' => $username] : [],
        );
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $this->activityLogger->recordForToken($event->getToken(), 'session.logout', $event->getRequest());
    }

    #[AsEventListener]
    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $request = $event->getRequest();
        $exiting = $request->query->getString('_switch_user') === SwitchUserListener::EXIT_VALUE;

        // On start, storage already holds the new SwitchUserToken; on stop, it still holds the
        // impersonated user's SwitchUserToken. resolveUsers() derives active vs real from either.
        $this->activityLogger->recordForToken(
            $this->tokenStorage->getToken(),
            $exiting ? 'session.impersonate_stop' : 'session.impersonate_start',
            $request,
            ['target' => $event->getTargetUser()->getUserIdentifier()],
        );
    }

    private function recordLiveComponentAction(Request $request, int $status): void
    {
        $component = $request->attributes->getString('_live_component');
        $action    = $request->attributes->getString('_live_action');

        // No `_live_action` means the initial render / default action — not a user action.
        if ($component === '' || $action === '' || $action === 'get') {
            return;
        }

        $lower = strtolower($action);
        foreach (self::TRANSIENT_ACTION_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return;
            }
        }
        if (\in_array($lower, self::TRANSIENT_ACTION_NAMES, true)) {
            return;
        }

        $this->activityLogger->recordFromRequest($request, "component.{$component}.{$action}", [
            'component' => $component,
            'action'    => $action,
            'status'    => $status,
        ]);
    }

    private function isExcludedRoute(string $route): bool
    {
        foreach (self::EXCLUDED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isSensitiveRoute(string $route): bool
    {
        foreach (self::SENSITIVE_ROUTE_PATTERNS as $pattern) {
            if (str_contains($route, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
