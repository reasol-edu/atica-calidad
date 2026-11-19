<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AttachmentDownloadResponder
{
    /**
     * Types a browser can show on its own without running anything of the uploaded file: a PDF in its viewer and the
     * raster images. Never SVG or HTML (they carry script), whatever the user uploaded them as.
     */
    private const array PREVIEWABLE = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    public function isPreviewable(string $mimeType): bool
    {
        return \in_array(strtolower($mimeType), self::PREVIEWABLE, true);
    }

    /** @param bool $inline Show it in the browser instead of downloading it; honoured only for a previewable type. */
    public function respond(string $content, string $mimeType, string $filename, bool $inline = false): Response
    {
        $filename = $this->sanitizeFilename($filename);

        $response = new Response($content);
        $response->headers->set('Content-Type', $mimeType);
        // No depender solo del servidor web: sin esto un navegador podría "adivinar" otro tipo
        // (p. ej. HTML) para un fichero subido por un usuario.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                $inline && $this->isPreviewable($mimeType) ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
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
