<?php

declare(strict_types=1);

namespace App\Search\Extractor;

/**
 * Word, PowerPoint and Excel documents of the Office Open XML family (.docx, .pptx, .xlsx): ZIP packages of XML parts.
 * What is read: the body, notes, headers and footers of a text document; every slide and its speaker notes; the shared strings
 * (every text cell) of a workbook, or its sheets when it keeps them inline. The old binary formats (.doc, .xls, .ppt) are not
 * readable without external programs and are left to the "unsupported" status.
 */
final class OpenXmlExtractor implements TextExtractorInterface
{
    private const array EXTENSIONS = ['docx', 'dotx', 'docm', 'pptx', 'ppsx', 'potx', 'pptm', 'xlsx', 'xltx', 'xlsm'];
    private const string MIME_PREFIX = 'application/vnd.openxmlformats-officedocument.';

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
            $parts = $this->partsToRead($zip);
            if ($parts === []) {
                throw new ExtractionException('No es un documento de Office reconocible.');
            }

            $text = '';
            foreach ($parts as $part) {
                $xml = ZipXml::read($zip, $part);
                if ($xml === null) {
                    continue;
                }
                // w:t, a:t (slides, text boxes) and t (shared strings) are the runs of text; paragraphs, rows and cells are where words end.
                $text .= ' ' . ZipXml::text($xml, ['t'], ['p', 'si', 'tr', 'tc', 'br', 'tab'], $maxChars - mb_strlen($text));
                if (mb_strlen($text) >= $maxChars) {
                    break;
                }
            }

            return $text;
        } finally {
            $zip->close();
        }
    }

    /** @return list<string> */
    private function partsToRead(\ZipArchive $zip): array
    {
        if ($zip->locateName('word/document.xml') !== false) {
            return ['word/document.xml', ...ZipXml::names($zip, '#^word/(footnotes|endnotes|header\d*|footer\d*)\.xml$#')];
        }

        if ($zip->locateName('ppt/presentation.xml') !== false) {
            return [...ZipXml::names($zip, '#^ppt/slides/slide\d+\.xml$#'), ...ZipXml::names($zip, '#^ppt/notesSlides/notesSlide\d+\.xml$#')];
        }

        if ($zip->locateName('xl/workbook.xml') !== false) {
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                return ['xl/sharedStrings.xml'];
            }

            return ZipXml::names($zip, '#^xl/worksheets/sheet\d+\.xml$#');
        }

        return [];
    }
}
