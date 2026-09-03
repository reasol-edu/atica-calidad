<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Builds a ZIP file on disk from in-memory entries and hands it back as an attachment response.
 *
 * Ported almost verbatim from GestConv+ (its own src/Service/AttachmentZipExporter.php); the only
 * deliberate change is the ASCII Content-Disposition fallback — folder/profile names here
 * routinely carry accents, and makeDisposition() rejects a non-ASCII fallback name. An entry's
 * name may contain "/" to place the file inside a subdirectory of the archive.
 */
class AttachmentZipExporter
{
    /**
     * @param list<array{name: string, content: string}> $entries
     */
    public function createResponse(string $zipFilename, array $entries): BinaryFileResponse
    {
        $tempPath = sys_get_temp_dir() . '/' . uniqid('atica_zip_', true) . '.zip';

        $zip = new \ZipArchive();
        $zip->open($tempPath, \ZipArchive::CREATE);

        $used = [];
        foreach ($entries as $entry) {
            $zip->addFromString($this->uniqueName($entry['name'], $used), $entry['content']);
        }

        $zip->close();

        if (!file_exists($tempPath)) {
            // ZipArchive writes nothing when it closes an archive with no entries.
            file_put_contents($tempPath, "PK\x05\x06" . str_repeat("\x00", 18));
        }

        $response = new BinaryFileResponse($tempPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $zipFilename,
            $this->asciiFilenameFallback($zipFilename),
        );
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * Keeps every entry name unique within the archive: a repeated name gets " (2)", " (3)"…
     * appended to its stem, preserving both the directory prefix and the extension.
     *
     * @param array<string, int> $used
     */
    private function uniqueName(string $name, array &$used): string
    {
        if (!isset($used[$name])) {
            $used[$name] = 1;

            return $name;
        }

        $slash     = strrpos($name, '/');
        $directory = $slash === false ? '' : substr($name, 0, $slash + 1);
        $basename  = $slash === false ? $name : substr($name, $slash + 1);

        $dot       = strrpos($basename, '.');
        $stem      = $dot === false ? $basename : substr($basename, 0, $dot);
        $extension = $dot === false ? '' : substr($basename, $dot);

        do {
            $candidate = sprintf('%s%s (%d)%s', $directory, $stem, ++$used[$name], $extension);
        } while (isset($used[$candidate]));

        $used[$candidate] = 1;

        return $candidate;
    }

    /**
     * makeDisposition() requires an ASCII fallback name — same approach as
     * AttachmentDownloadResponder: the readable name comes from user-entered folder/profile text
     * and may contain accents or other non-ASCII characters.
     */
    private function asciiFilenameFallback(string $filename): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $filename);
        $ascii = preg_replace('/[^A-Za-z0-9 ._-]/', '', $ascii === false ? $filename : $ascii);

        return $ascii === '' || $ascii === null ? 'descarga.zip' : $ascii;
    }
}
