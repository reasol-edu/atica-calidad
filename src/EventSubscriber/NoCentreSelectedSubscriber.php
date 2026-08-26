<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\ValueResolver\NoCentreSelectedException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: KernelEvents::EXCEPTION, method: 'onKernelException')]
final class NoCentreSelectedSubscriber
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof NoCentreSelectedException) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_select_centre')
        ));
    }
}
