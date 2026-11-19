<?php

declare(strict_types=1);

namespace App\Search\Extractor;

/**
 * LibreOffice / OpenOffice documents (.odt, .ods, .odp and their templates): a ZIP package whose content.xml holds the text of
 * the whole document, spreadsheet cells and slides included.
 */
final class OpenDocumentExtractor implements TextExtractorInterface
{
    private const array EXTENSIONS = ['odt', 'ott', 'ods', 'ots', 'odp', 'otp'];
    private const string MIME_PREFIX = 'application/vnd.oasis.opendocument.';

    public function supports(string $mimeType, string $filename): bool
    {
        return \in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::EXTENSIONS, true)
            || str_starts_with(strtolower($mimeType), self::MIME_PREFIX);
    }

    public function maxFileBytes(): int
    {
        return 60 * 1024 * 1024;
    }

    public function extract(string $path, int $maxChars): string
    {
        $zip = ZipXml::open($path);

        try {
            $xml = ZipXml::read($zip, 'content.xml');
            if ($xml === null) {
                throw new ExtractionException('No es un documento OpenDocument reconocible.');
            }

            // Every text node of the body; paragraphs, headings, cells and list items are where words end, and text:s / text:tab are the runs of spaces.
            return ZipXml::text($xml, null, ['p', 'h', 'table-cell', 'list-item', 's', 'tab', 'line-break'], $maxChars);
        } finally {
            $zip->close();
        }
    }
}
