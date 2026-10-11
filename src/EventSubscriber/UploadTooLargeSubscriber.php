<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\UploadLimits;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * When the body of a request with files exceeds post_max_size, PHP discards it whole (POST and
 * files, CSRF token included) with no error of the application's own: a controller would only see
 * an empty form and answer a 403 unrelated to the size of the files. This listener spots it from
 * Content-Length, before it reaches any controller, for every upload (documents, submissions,
 * PDF templates, imports…), and sends the user back to the previous page with a message.
 *
 * It runs after the firewall (priority 8): only identified users get here.
 */
final class UploadTooLargeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UploadLimits $limits,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 7]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()
            || !$request->isMethod('POST')
            || !str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/form-data')
            || !$this->limits->isRequestTooLarge($request)
        ) {
            return;
        }

        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', $this->limits->requestTooLargeMessage($request));
            }
        }

        $event->setResponse(new RedirectResponse($this->backTarget($request), 303));
    }

    /** Path of the page the form was sent from (same site only) or the home page. */
    private function backTarget(Request $request): string
    {
        $referer = (string) $request->headers->get('Referer');
        $parts   = parse_url($referer);

        if (\is_array($parts) && ($parts['host'] ?? null) === $request->getHost() && isset($parts['path'])) {
            return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        return $request->getBasePath() . '/';
    }
}
