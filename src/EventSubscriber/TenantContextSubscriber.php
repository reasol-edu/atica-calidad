<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Teacher;
use App\Repository\EducationalCentreRepository;
use App\Service\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 4)]
final class TenantContextSubscriber
{
    private const EXCLUDED_ROUTES = [
        'app_login',
        'app_logout',
        'app_select_centre',
        'app_select_centre_choose',
        'app_profile',
        'app_profile_settings',
        'app_profile_verify_email',
        'app_force_password_change',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EducationalCentreRepository $centres,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (\in_array($route, self::EXCLUDED_ROUTES, strict: true)) {
            return;
        }

        // Admin section and centre-hub routes resolve their centre from the
        // URL, not from the session, so they do not require centre selection
        if (\is_string($route) && (\str_starts_with($route, 'app_admin') || \str_starts_with($route, 'app_centre'))) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof Teacher) {
            return;
        }

        if ($this->tenantContext->isSelected()) {
            // Guard against stale sessions where the stored UUID no longer resolves
            if ($this->tenantContext->getSelectedCentre() === null) {
                $this->tenantContext->clear();
            } else {
                return;
            }
        }

        $centres = $this->centres->findAccessibleByTeacher($user);

        if (\count($centres) === 1) {
            $this->tenantContext->selectCentre($centres[0]);
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_select_centre')
        ));
    }
}
