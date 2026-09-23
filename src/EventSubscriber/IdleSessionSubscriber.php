<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Teacher;
use App\Service\AppSettingsInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Logs a teacher out after a period without any request (setting security.idle_timeout_minutes,
 * global; 0 turns it off) — a staff-room computer left with a session open shouldn't stay usable
 * by whoever sits down next. Every request counts as activity, Live Component updates included.
 * The logout goes through Security::logout(), so it's recorded like any other; the login page then
 * explains why ("?sesion=caducada").
 */
final class IdleSessionSubscriber implements EventSubscriberInterface
{
    public const string SETTING = 'security.idle_timeout_minutes';

    private const string SESSION_KEY = 'security.last_activity';

    /** Used when the setting isn't defined (e.g. not migrated yet). */
    private const int DEFAULT_MINUTES = 120;

    public function __construct(
        private readonly Security $security,
        private readonly AppSettingsInterface $settings,
        private readonly ClockInterface $clock,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8) has authenticated the request.
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession() || !$this->security->getUser() instanceof Teacher) {
            return;
        }

        $session = $request->getSession();
        $now     = $this->clock->now()->getTimestamp();
        $last    = $session->get(self::SESSION_KEY);
        $minutes = $this->timeoutMinutes();

        if ($minutes > 0 && is_int($last) && $now - $last > $minutes * 60) {
            $this->security->logout(false);
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login', ['sesion' => 'caducada'])));

            return;
        }

        $session->set(self::SESSION_KEY, $now);
    }

    private function timeoutMinutes(): int
    {
        $minutes = $this->settings->getGlobal(self::SETTING);

        return is_int($minutes) && $minutes >= 0 ? $minutes : self::DEFAULT_MINUTES;
    }
}
