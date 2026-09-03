<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\Folder;
use App\Repository\DocumentRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Packs a whole folder into a ZIP: one file per document, taken from its active revision.
 *
 * When the folder is organised by upload profile (Folder::isGroupByProfile()), each profile —
 * or subprofile — becomes a subdirectory named after it, mirroring the on-screen grouping in
 * SectionBrowserComponent::getFolderDocumentGroups(); documents with no upload profile, and
 * every document of a folder that isn't grouped, sit at the archive root. Directory and file
 * names are stripped of characters that could break a ZIP entry or escape the archive.
 *
 * Documents whose only revisions are pending review or rejected have no active revision and are
 * left out — there is nothing published to hand over. The transient section-search filter is
 * ignored on purpose: a download is always the full folder.
 */
final class FolderZipExporter
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly AttachmentZipExporter $zipExporter,
    ) {}

    public function export(Folder $folder): BinaryFileResponse
    {
        $groupByProfile = $folder->isGroupByProfile();

        $entries = [];
        foreach ($this->documents->findByFolder($folder) as $document) {
            $revision = $document->getActiveRevision();
            if ($revision === null) {
                continue;
            }

            $file   = $revision->getFile();
            $prefix = $groupByProfile ? $this->profileDirectory($document) : '';

            $entries[] = [
                'name'    => $prefix . $this->entryFilename($document, $file->getOriginalFilename()),
                'content' => $file->getContent(),
            ];
        }

        return $this->zipExporter->createResponse($this->zipFilename($folder), $entries);
    }

    /** The document's own name, keeping the extension of the file that was actually uploaded. */
    private function entryFilename(Document $document, string $originalFilename): string
    {
        $stem      = $this->sanitizeSegment($document->getName());
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        return $extension === '' ? $stem : $stem . '.' . $this->sanitizeSegment($extension);
    }

    /**
     * "" when the document carries no upload profile — it belongs at the archive root, forming
     * the same unlabelled group it does on screen.
     */
    private function profileDirectory(Document $document): string
    {
        $profile = $document->getUploadProfile();
        if ($profile === null) {
            return '';
        }

        $label      = $profile->getName();
        $subprofile = $document->getUploadListItem();
        if ($subprofile !== null) {
            $label .= ' ' . $subprofile->getName();
        }

        return $this->sanitizeSegment($label) . '/';
    }

    /**
     * Replaces every character that would let a name escape its directory or break a ZIP entry —
     * path separators, the Windows-reserved set and control characters — with "_", collapses
     * runs of whitespace, and trims leading/trailing dots and spaces so "..", "." or a blank
     * name can never survive. Never returns an empty string.
     */
    private function sanitizeSegment(string $name): string
    {
        $safe = preg_replace('#[/\\\\:*?"<>|\x00-\x1F]+#', '_', $name) ?? '';
        $safe = preg_replace('/\s+/', ' ', $safe) ?? $safe;
        $safe = trim($safe, " .\t\n\r\0\x0B");

        return $safe === '' ? '_' : $safe;
    }

    private function zipFilename(Folder $folder): string
    {
        return $this->sanitizeSegment($folder->getName()) . '.zip';
    }
}
