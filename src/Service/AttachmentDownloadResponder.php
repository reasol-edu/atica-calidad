<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AttachmentDownloadResponder
{
    public function respond(string $content, string $mimeType, string $filename): Response
    {
        $filename = $this->sanitizeFilename($filename);

        $response = new Response($content);
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $filename,
                $this->asciiFilenameFallback($filename),
            ),
        );

        return $response;
    }

    /**
     * makeDisposition() rejects outright a filename containing "/" or "\" (Content-Disposition is
     * itself a path-injection vector, per RFC 6266) — an uncaught InvalidArgumentException, not a
     * validation error the caller could handle. The filename passed in here is usually a document's
     * own name, which is free text and can legitimately contain either character (e.g. a submission
     * named after the "Tutor/a" profile it belongs to): strip them before they ever reach
     * makeDisposition(), rather than trust every caller to have sanitized its own filename first.
     */
    private function sanitizeFilename(string $filename): string
    {
        return str_replace(['/', '\\'], '_', $filename);
    }

    /**
     * makeDisposition() requires an ASCII fallback name: the attachment's
     * original name comes from the file uploaded by the user and may
     * contain accents or other non-ASCII characters.
     */
    private function asciiFilenameFallback(string $filename): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        $ascii = preg_replace('/[^A-Za-z0-9 ._-]/', '', $ascii === false ? $filename : $ascii);

        return $ascii === '' || $ascii === null ? 'adjunto' : $ascii;
    }
}
