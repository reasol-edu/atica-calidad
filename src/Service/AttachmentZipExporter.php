<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Builds a ZIP file on disk and hands it back as an attachment response, without ever holding the
 * entries' contents in memory together: each entry writes its own content into a scratch file,
 * which ZipArchive::addFile() only reads when the archive is closed — so memory stays at roughly
 * one entry, however large the whole archive gets. Scratch files live in their own temporary
 * directory, removed once the archive is written (or on failure); the ZIP itself is deleted by
 * BinaryFileResponse once sent — Symfony keeps going after a client aborts the download too.
 *
 * Entries are stored uncompressed unless their extension marks them as plain text: PDFs, office
 * documents and images are compressed formats already, so deflating them again only costs CPU.
 *
 * Ported from GestConv+ (its own src/Service/AttachmentZipExporter.php), which built the archive
 * from in-memory strings; the ASCII Content-Disposition fallback is also a local change —
 * folder/profile names here routinely carry accents, and makeDisposition() rejects a non-ASCII
 * fallback name. An entry's name may contain "/" to place the file inside a subdirectory.
 */
class AttachmentZipExporter
{
    /** Extensions worth deflating: text formats. Everything else is stored as is. */
    private const array COMPRESSIBLE_EXTENSIONS = ['txt', 'csv', 'tsv', 'xml', 'html', 'htm', 'json', 'md', 'svg', 'rtf', 'ics', 'log', 'sql'];

    /**
     * @param iterable<array{name: string, write: callable(string): void}> $entries each entry's
     *        `write` receives a path and puts the entry's content in that file
     */
    public function createResponse(string $zipFilename, iterable $entries): BinaryFileResponse
    {
        $id       = uniqid('atica_zip_', true);
        $zipPath  = sys_get_temp_dir() . '/' . $id . '.zip';
        $workDir  = sys_get_temp_dir() . '/' . $id;
        if (!mkdir($workDir, 0700) && !is_dir($workDir)) {
            throw new \RuntimeException(\sprintf('Cannot create the temporary directory "%s".', $workDir));
        }

        $parts  = [];
        $zip    = new \ZipArchive();
        $opened = false;
        try {
            $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true;
            if (!$opened) {
                throw new \RuntimeException(\sprintf('Cannot create the ZIP file "%s".', $zipPath));
            }

            $used = [];
            foreach ($entries as $entry) {
                $name    = $this->uniqueName($entry['name'], $used);
                $part    = $workDir . '/' . \count($parts);
                $parts[] = $part;
                ($entry['write'])($part);

                $zip->addFile($part, $name);
                $zip->setCompressionName($name, $this->isCompressible($name) ? \ZipArchive::CM_DEFLATE : \ZipArchive::CM_STORE);
            }

            // Only now are the scratch files actually read into the archive.
            $closed = $zip->close();
            $opened = false;
            if (!$closed) {
                throw new \RuntimeException(\sprintf('Cannot write the ZIP file "%s".', $zipPath));
            }

            if (!file_exists($zipPath)) {
                // ZipArchive writes nothing when it closes an archive with no entries.
                file_put_contents($zipPath, "PK\x05\x06" . str_repeat("\x00", 18));
            }
        } catch (\Throwable $e) {
            if ($opened) {
                // Drop what was added, so closing (explicitly here, or by the destructor later)
                // doesn't try to read scratch files about to be deleted.
                $zip->unchangeAll();
                $zip->close();
            }
            if (is_file($zipPath)) {
                unlink($zipPath);
            }

            throw $e;
        } finally {
            foreach ($parts as $part) {
                if (is_file($part)) {
                    unlink($part);
                }
            }
            rmdir($workDir);
        }

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $zipFilename,
            $this->asciiFilenameFallback($zipFilename),
        );
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    private function isCompressible(string $name): bool
    {
        return \in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::COMPRESSIBLE_EXTENSIONS, true);
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
