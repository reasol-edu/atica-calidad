<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AttachmentDownloadResponder
{
    public function respond(string $content, string $mimeType, string $filename): Response
    {
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
