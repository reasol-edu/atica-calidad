<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Upload limits: the ones set in the application (global settings "uploads.*") capped by PHP's
 * (upload_max_filesize, post_max_size, max_file_uploads), which the server enforces above whatever
 * the application says.
 *
 * PHP silently drops what exceeds its limits (the file arrives with an error code, or the whole
 * request body disappears, CSRF token included): this service works out the real maximums and turns
 * each incident into a clear message for the user.
 */
class UploadLimits
{
    public const DEFAULT_FILE_MB  = 20;
    public const DEFAULT_TOTAL_MB = 100;

    /** Room for everything in a multipart request that is not a file (fields, headers and boundaries). */
    private const MULTIPART_OVERHEAD = 256 * 1024;

    private const MIB = 1024 * 1024;

    public function __construct(
        private readonly AppSettingsInterface $settings,
        private readonly TranslatorInterface $translator,
    ) {}

    /** Real maximum size of one file, in bytes. */
    public function maxFileBytes(): int
    {
        $limit = $this->configuredBytes('uploads.max_file_size_mb', self::DEFAULT_FILE_MB);

        $iniFile = $this->iniBytes('upload_max_filesize');
        if ($iniFile > 0) {
            $limit = min($limit, $iniFile);
        }

        $postMax = $this->iniBytes('post_max_size');
        if ($postMax > 0) {
            $limit = min($limit, max(1, $postMax - self::MULTIPART_OVERHEAD));
        }

        return $limit;
    }

    /** Real maximum size of all the files in one request, in bytes (never smaller than one file). */
    public function maxTotalBytes(): int
    {
        $limit = $this->configuredBytes('uploads.max_total_size_mb', self::DEFAULT_TOTAL_MB);

        $postMax = $this->iniBytes('post_max_size');
        if ($postMax > 0) {
            $limit = min($limit, max(1, $postMax - self::MULTIPART_OVERHEAD));
        }

        return max($limit, $this->maxFileBytes());
    }

    /**
     * Most files per request. PHP drops, without saying so, the ones beyond max_file_uploads, so
     * receiving exactly that many might already mean others were lost: the safe limit is one less.
     */
    public function maxFiles(): int
    {
        $ini = (int) $this->iniValue('max_file_uploads');

        return $ini > 0 ? max(1, $ini - 1) : 1000;
    }

    /** Does the request body exceed post_max_size (PHP discards it whole, CSRF token included)? */
    public function isRequestTooLarge(Request $request): bool
    {
        $contentLength = $request->headers->get('Content-Length');
        $postMax       = $this->iniBytes('post_max_size');

        return $contentLength !== null && $postMax > 0 && (int) $contentLength > $postMax;
    }

    public function requestTooLargeMessage(Request $request): string
    {
        return $this->translator->trans('error.request_too_large', [
            '%size%' => $this->formatSize((int) $request->headers->get('Content-Length')),
            '%max%'  => $this->formatSize($this->maxTotalBytes()),
        ], 'uploads');
    }

    /**
     * Checks the files of a multiple field. None is ignored silently: each incomplete, rejected or
     * oversized file produces a message, and so do the total and the count.
     *
     * @param iterable<mixed> $files usually $request->files->all('field')
     *
     * @return list<string> translated messages (empty when everything is fine)
     */
    public function validate(iterable $files): array
    {
        $errors = [];
        $total  = 0;
        $count  = 0;

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || $file->getError() === \UPLOAD_ERR_NO_FILE) {
                continue;
            }

            ++$count;

            $problem = $this->fileProblem($file);
            if ($problem !== null) {
                $errors[] = $problem;
                continue;
            }

            $total += (int) $file->getSize();
        }

        if ($count > $this->maxFiles()) {
            $errors[] = $this->translator->trans('error.too_many_files', ['%max%' => $this->maxFiles()], 'uploads');
        }

        if ($total > $this->maxTotalBytes()) {
            $errors[] = $this->translator->trans('error.total_too_large', [
                '%total%' => $this->formatSize($total),
                '%max%'   => $this->formatSize($this->maxTotalBytes()),
            ], 'uploads');
        }

        return $errors;
    }

    /**
     * Message about one file (null when it is fine or none was chosen). For single-file forms and,
     * internally, for each file of a multiple field.
     */
    public function fileProblem(mixed $file): ?string
    {
        if (!$file instanceof UploadedFile || $file->getError() === \UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $name = $file->getClientOriginalName();

        if (!$file->isValid()) {
            return match ($file->getError()) {
                \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => $this->tooLarge($name),
                \UPLOAD_ERR_PARTIAL                         => $this->translator->trans('error.partial', ['%filename%' => $name], 'uploads'),
                default                                     => $this->translator->trans('error.not_saved', ['%filename%' => $name], 'uploads'),
            };
        }

        if ((int) $file->getSize() > $this->maxFileBytes()) {
            return $this->tooLarge($name);
        }

        return null;
    }

    /** «20 MB», «1,5 MB» or «300 KB». */
    public function formatSize(int $bytes): string
    {
        if ($bytes >= self::MIB) {
            return ($bytes % self::MIB === 0 ? (string) intdiv($bytes, self::MIB) : number_format($bytes / self::MIB, 1, ',', '')) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }

    private function tooLarge(string $filename): string
    {
        return $this->translator->trans('error.file_too_large', [
            '%filename%' => $filename,
            '%max%'      => $this->formatSize($this->maxFileBytes()),
        ], 'uploads');
    }

    private function configuredBytes(string $key, int $defaultMb): int
    {
        $value = $this->settings->getGlobal($key);
        $mb    = \is_int($value) && $value > 0 ? $value : $defaultMb;

        return $mb * self::MIB;
    }

    /** Current value of a PHP directive (separate so tests can replace it: they cannot be changed at runtime). */
    protected function iniValue(string $directive): string
    {
        return (string) ini_get($directive);
    }

    /** Value in bytes of a PHP size directive (0 = unlimited or undefined). */
    private function iniBytes(string $directive): int
    {
        $value = trim($this->iniValue($directive));
        if ($value === '' || (int) $value <= 0) {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
