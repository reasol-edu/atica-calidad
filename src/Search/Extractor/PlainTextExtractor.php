<?php

declare(strict_types=1);

namespace App\Search\Extractor;

/**
 * Plain text, Markdown, logs and CSV. Spanish files in the wild are UTF-8 or Windows-1252 (an Excel CSV, an old Notepad file):
 * anything that is not valid UTF-8 is read as Windows-1252.
 */
final class PlainTextExtractor implements TextExtractorInterface
{
    private const array EXTENSIONS = ['txt', 'md', 'markdown', 'log', 'csv', 'text'];
    private const array MIME_TYPES = ['text/plain', 'text/markdown', 'text/csv', 'text/x-markdown'];

    public function supports(string $mimeType, string $filename): bool
    {
        return \in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::EXTENSIONS, true)
            || \in_array(strtolower($mimeType), self::MIME_TYPES, true);
    }

    public function maxFileBytes(): int
    {
        return 40 * 1024 * 1024;
    }

    public function extract(string $path, int $maxChars): string
    {
        // Four bytes per character is the most UTF-8 spends, so this always covers $maxChars.
        $raw = file_get_contents($path, false, null, 0, max(1, $maxChars * 4));
        if ($raw === false) {
            throw new ExtractionException('No se pudo leer el fichero.');
        }

        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        // A cut in the middle of a multibyte character (the read stops at a byte count) is not a reason to call the file non-UTF-8.
        if (!mb_check_encoding($raw, 'UTF-8') && !mb_check_encoding(substr($raw, 0, -3), 'UTF-8')) {
            return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return $raw;
    }
}
