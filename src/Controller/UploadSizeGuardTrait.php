<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Cuando el cuerpo de un envío con ficheros adjuntos supera post_max_size,
 * PHP descarta el cuerpo entero (POST y ficheros, incluido el token CSRF)
 * sin registrar ningún error de validación propio de la aplicación. Sin esta
 * comprobación, eso se traduce en un 403 confuso ajeno al tamaño de los
 * adjuntos en vez de un aviso claro de que el envío era demasiado grande.
 */
trait UploadSizeGuardTrait
{
    private function isUploadTooLarge(Request $request): bool
    {
        $contentLength = $request->headers->get('Content-Length');
        $postMaxSize   = self::parseIniSize((string) ini_get('post_max_size'));

        return $contentLength !== null && $postMaxSize > 0 && (int) $contentLength > $postMaxSize;
    }

    private static function parseIniSize(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
