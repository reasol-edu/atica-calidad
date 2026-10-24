<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\MaintenanceMode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * In maintenance mode (setting maintenance.enabled) only global administrators get through: every
 * other request is answered with a 503 page. What stays reachable is only what an administrator
 * needs to get in and what the page itself needs to render — the login and logout, the compiled
 * assets and static files, and the Symfony debug tools. Everything else is blocked on purpose,
 * including the Live Component updates and the public calendar feed.
 *
 * An administrator "acting as" another teacher keeps access (the impersonated user is not an
 * administrator, the impersonator is). Anonymous visitors are not handled here: the firewall sends
 * them to the login page first, which explains the situation.
 */
final class MaintenanceModeSubscriber implements EventSubscriberInterface
{
    /** Exact paths that stay open: the way in (and out) for administrators. */
    private const array OPEN_PATHS = ['/login', '/logout'];

    /** Path prefixes that stay open: what the pages load, and the Symfony debug tools. */
    private const array OPEN_PREFIXES = ['/assets/', '/static/', '/_wdt', '/_profiler'];

    public function __construct(
        private readonly MaintenanceMode $maintenance,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8) has authenticated the request, before the idle-session check (6).
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $this->isOpenPath($request) || !$this->maintenance->isActive()) {
            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN') || $this->security->isGranted('IS_IMPERSONATOR')) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('maintenance/index.html.twig', ['signed_in' => $this->security->getUser() !== null]),
            Response::HTTP_SERVICE_UNAVAILABLE,
        ));
    }

    private function isOpenPath(Request $request): bool
    {
        $path = $request->getPathInfo();
        if (in_array($path, self::OPEN_PATHS, true)) {
            return true;
        }

        foreach (self::OPEN_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
