<?php

declare(strict_types=1);

namespace App\Search\Extractor;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * PDF files that carry their text (exported from a word processor, a form, most official documents). A scan is a picture: it
 * yields no text and the indexer records it as such. Pure PHP (smalot/pdfparser), so it needs nothing installed on the server;
 * the price is memory and time on big files, which is why it only runs in the worker and has a lower size ceiling.
 */
final class PdfExtractor implements TextExtractorInterface
{
    /** Pages read; the rest of a longer file is not searched. */
    private const int MAX_PAGES = 400;

    public function supports(string $mimeType, string $filename): bool
    {
        return strtolower($mimeType) === 'application/pdf' || strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf';
    }

    public function maxFileBytes(): int
    {
        return 25 * 1024 * 1024;
    }

    public function extract(string $path, int $maxChars): string
    {
        $config = new Config();
        // Pictures inside the file are not needed and are most of its size in memory.
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        try {
            $document = (new Parser([], $config))->parseFile($path);

            return $document->getText(self::MAX_PAGES);
        } catch (\Throwable $e) {
            // The parser throws plain \Exception for an encrypted file, a broken cross-reference table, a missing xref…: all "unreadable" here.
            throw new ExtractionException(mb_strimwidth('El PDF no se puede leer: ' . $e->getMessage(), 0, 240, '…'), 0, $e);
        }
    }
}
