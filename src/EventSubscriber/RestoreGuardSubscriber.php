<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Backup\RestoreState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * Protects the application from a backup restore:
 *
 * 1. While one is running, EVERY request gets a 503 page (administrators too): tables are being emptied
 *    and refilled, and any read or write would give half results. It runs **before the firewall** (which
 *    reloads the user from the `teacher` table) and only looks at a file: no database, no session. It is
 *    independent of the maintenance mode setting (MaintenanceModeSubscriber), which lives in the database.
 * 2. When a restore ends, the sessions created before it are closed: the users in the backup may not be the
 *    same (or have other permissions). A session's creation time is renewed on login, so whoever signs
 *    in after the restore is not affected.
 */
final class RestoreGuardSubscriber implements EventSubscriberInterface
{
    /** Static files: the 503 page is standalone, but a browser may ask for the icon or CSS it already knows. */
    private const OPEN_PREFIXES = ['/assets/', '/static/', '/_wdt', '/_profiler'];

    public function __construct(
        private readonly RestoreState $state,
        private readonly Environment $twig,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // The security firewall is at 8; the session listener, at 128.
        return [KernelEvents::REQUEST => [
            ['blockWhileRestoring', 256],
            ['closeStaleSessions', 9],
        ]];
    }

    public function blockWhileRestoring(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $this->isOpenPath($request) || !$this->state->isRunning()) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('restore/in_progress.html.twig'),
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Retry-After' => '15', 'Cache-Control' => 'no-store'],
        ));
    }

    public function closeStaleSessions(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }

        $restoredAt = $this->state->restoredAt();
        if ($restoredAt === null) {
            return;
        }

        $session = $request->getSession();
        // Before the session starts its metadata is empty and `getCreated()` is 0: it would look older than any
        // restore and close even new sessions (only seen with PHP's native storage, not with the tests').
        if (!$session->isStarted()) {
            $session->start();
        }
        // `getCreated()` is when PHP created (or regenerated, on login) the session: compared with time(), not the mocked clock.
        if ($session->getMetadataBag()->getCreated() < $restoredAt) {
            $session->invalidate();
            $event->setResponse(new RedirectResponse('/login'));
        }
    }

    private function isOpenPath(Request $request): bool
    {
        foreach (self::OPEN_PREFIXES as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return true;
            }
        }

        return false;
    }
}
