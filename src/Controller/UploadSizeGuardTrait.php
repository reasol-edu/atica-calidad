<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * When the body of a submission with file attachments exceeds post_max_size,
 * PHP discards the entire body (POST and files, including the CSRF token)
 * without logging any validation error of its own. Without this check,
 * that results in a confusing 403 unrelated to the attachment size instead
 * of a clear notice that the submission was too large.
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
