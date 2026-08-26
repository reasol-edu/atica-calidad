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
     * makeDisposition() exige un nombre de reserva ASCII: el nombre original
     * del adjunto proviene del archivo subido por el usuario y puede
     * contener acentos u otros caracteres no ASCII.
     */
    private function asciiFilenameFallback(string $filename): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        $ascii = preg_replace('/[^A-Za-z0-9 ._-]/', '', $ascii === false ? $filename : $ascii);

        return $ascii === '' || $ascii === null ? 'adjunto' : $ascii;
    }
}
