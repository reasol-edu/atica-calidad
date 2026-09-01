<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Teacher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Lower priority than TenantContextSubscriber (4) and KioskModeSubscriber (5)
 * so it runs last among kernel.request listeners: since Symfony doesn't stop
 * propagation when setResponse() is called, the mandatory password change
 * redirect must take precedence over any other.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 1)]
final class ForcePasswordChangeSubscriber
{
    private const ALLOWED_ROUTES = [
        'app_force_password_change',
        'app_login',
        'app_logout',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (\in_array($route, self::ALLOWED_ROUTES, strict: true)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Teacher) {
            return;
        }

        if ($user->isExternal() || !$user->isForcePasswordChange()) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_force_password_change')
        ));
    }
}
