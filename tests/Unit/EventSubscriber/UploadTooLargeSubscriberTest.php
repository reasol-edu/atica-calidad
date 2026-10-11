<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\UploadTooLargeSubscriber;
use App\Service\AppSettingsInterface;
use App\Service\UploadLimits;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UploadTooLargeSubscriberTest extends TestCase
{
    private function subscriber(): UploadTooLargeSubscriber
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Demasiado grande');

        $limits = new class($this->createStub(AppSettingsInterface::class), $translator) extends UploadLimits {
            protected function iniValue(string $directive): string
            {
                return $directive === 'post_max_size' ? '8M' : '';
            }
        };

        return new UploadTooLargeSubscriber($limits);
    }

    private function event(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
    }

    private function post(string $contentType, int $length, string $referer = ''): Request
    {
        $request = Request::create('/arbol-documental/carpetas/x/subir', 'POST', server: [
            'CONTENT_TYPE'        => $contentType,
            'HTTP_CONTENT_LENGTH' => (string) $length,
            'HTTP_REFERER'        => $referer,
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    public function testAnOversizedUploadIsSentBackWithAFlashMessage(): void
    {
        $request = $this->post('multipart/form-data; boundary=x', 9 * 1024 * 1024, 'http://localhost/arbol-documental/carpetas/x?tab=1');
        $event   = $this->event($request);

        $this->subscriber()->onRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/arbol-documental/carpetas/x?tab=1', $response->getTargetUrl());
        self::assertSame(['Demasiado grande'], $request->getSession()->getFlashBag()->get('error'));
    }

    public function testAForeignRefererFallsBackToTheHomePage(): void
    {
        $event = $this->event($this->post('multipart/form-data; boundary=x', 9 * 1024 * 1024, 'https://evil.example/path'));

        $this->subscriber()->onRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/', $response->getTargetUrl());
    }

    public function testAnUploadWithinTheLimitPassesThrough(): void
    {
        $event = $this->event($this->post('multipart/form-data; boundary=x', 1024 * 1024));

        $this->subscriber()->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testNonMultipartRequestsAreIgnored(): void
    {
        $event = $this->event($this->post('application/x-www-form-urlencoded', 9 * 1024 * 1024));

        $this->subscriber()->onRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testSubRequestsAreIgnored(): void
    {
        $event = $this->event($this->post('multipart/form-data; boundary=x', 9 * 1024 * 1024), HttpKernelInterface::SUB_REQUEST);

        $this->subscriber()->onRequest($event);

        self::assertNull($event->getResponse());
    }
}
