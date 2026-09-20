<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AttachmentDownloadResponder;
use PHPUnit\Framework\TestCase;

final class AttachmentDownloadResponderTest extends TestCase
{
    /**
     * Regression: ResponseHeaderBag::makeDisposition() rejects a filename containing "/" or "\"
     * outright — an uncaught InvalidArgumentException, surfaced as a 500 — and a document's own
     * name is free text that can legitimately contain either (e.g. "Tutor/a", the name of an
     * Individual-scope activity's profile).
     */
    public function testStripsPathSeparatorsSoTheResponseNeverThrows(): void
    {
        $response = (new AttachmentDownloadResponder())->respond('contenido', 'text/plain', 'Tutor/a.txt');

        $disposition = $response->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringNotContainsString('/', $disposition);
        self::assertStringContainsString('Tutor_a.txt', $disposition);
    }

    public function testStripsBackslashesToo(): void
    {
        $response = (new AttachmentDownloadResponder())->respond('contenido', 'text/plain', 'Informe\\2026.txt');

        $disposition = $response->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringNotContainsString('\\', $disposition);
        self::assertStringContainsString('Informe_2026.txt', $disposition);
    }

    public function testAFilenameWithoutPathSeparatorsIsUnaffected(): void
    {
        $response = (new AttachmentDownloadResponder())->respond('contenido', 'text/plain', 'Programación.txt');

        $disposition = $response->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringContainsString("utf-8''Programaci%C3%B3n.txt", $disposition);
    }
}
