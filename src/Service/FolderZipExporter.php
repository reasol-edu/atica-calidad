<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\Folder;
use App\Repository\DocumentFileRepository;
use App\Repository\DocumentRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Packs a whole folder into a ZIP: one file per document, taken from its active revision.
 *
 * When the folder is organised by upload profile (Folder::isGroupByProfile()), each profile —
 * or subprofile — becomes a subdirectory named after it, mirroring the on-screen grouping in
 * SectionBrowserComponent::getFolderDocumentGroups(); documents with no upload profile, and
 * every document of a folder that isn't grouped, sit at the archive root. Entry names follow the
 * same convention as a single-revision download (see ActivitySubmissionFilenameBuilder) — for an
 * Individual-scope activity in particular, this is what tells several teachers' own submissions
 * apart inside the ZIP, rather than leaving them all named identically after their shared
 * profile. Directory and file names are stripped of characters that could break a ZIP entry or
 * escape the archive.
 *
 * Documents whose only revisions are pending review or rejected have no active revision and are
 * left out — there is nothing published to hand over. The transient section-search filter is
 * ignored on purpose: a download is always the full folder.
 *
 * An activity's folder, though, only holds the academic year selected on screen (see
 * ActivityFolderCycleFilter; the current one by default). When that spans more than one academic
 * year, each year becomes a top-level directory ("2025-2026/"), with the profile directories
 * inside it; and the ZIP's own name is led by the academic year (or range of years) it holds.
 *
 * File contents are never loaded here: the file ids and original filenames come from one plain
 * query, and each entry streams its own content to disk through DocumentFileRepository::
 * writeContentTo() when AttachmentZipExporter asks for it — one file in memory at a time.
 */
final class FolderZipExporter
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly AttachmentZipExporter $zipExporter,
        private readonly ActivitySubmissionFilenameBuilder $submissionFilename,
        private readonly ActivityFolderCycleFilter $cycleFilter,
        private readonly DocumentFileRepository $documentFiles,
    ) {}

    /** @param string $cycleSelection see ActivityFolderCycleFilter: "" (current academic year), ActivityFolderCycleFilter::ALL or a cycle key */
    public function export(Folder $folder, string $cycleSelection = ''): BinaryFileResponse
    {
        $groupByProfile = $folder->isGroupByProfile();
        $currentCycle   = $this->cycleFilter->currentCycle($folder);

        $activeFiles = $this->documents->findActiveFilesInFolder($folder);

        $published = [];
        $cycles    = [];
        foreach ($this->cycleFilter->filter($folder, $this->documents->findByFolder($folder), $cycleSelection) as $document) {
            $file = $activeFiles[$document->getId()->toRfc4122()] ?? null;
            if ($file === null) {
                continue;
            }
            $published[] = [$document, $file];
            if ($currentCycle !== null) {
                $cycles[$document->getActivityCycleYear() ?? $currentCycle] = true;
            }
        }
        $byAcademicYear = count($cycles) > 1;

        $entries = [];
        foreach ($published as [$document, $file]) {
            $prefix = $byAcademicYear && $currentCycle !== null
                ? $this->sanitizeSegment(ActivityDeadlineChecker::academicYearLabel($document->getActivityCycleYear() ?? $currentCycle)) . '/'
                : '';
            if ($groupByProfile) {
                $prefix .= $this->profileDirectory($document);
            }

            $fileId    = $file['fileId'];
            $entries[] = [
                'name'  => $prefix . $this->entryFilename($document, $file['originalFilename']),
                'write' => fn (string $path) => $this->documentFiles->writeContentTo($fileId, $path),
            ];
        }

        $cycleKeys = array_keys($cycles);
        if ($cycleKeys === [] && $currentCycle !== null) {
            $cycleKeys = [$this->cycleFilter->selectedCycle($folder, $cycleSelection) ?? $currentCycle];
        }

        return $this->zipExporter->createResponse($this->zipFilename($folder, $cycleKeys), $entries);
    }

    /**
     * The document's display name (see ActivitySubmissionFilenameBuilder — activity prefix/title
     * and, for an Individual-scope submission, the uploader's own name), keeping the extension of
     * the file that was actually uploaded. Each part is sanitized on its own before joining, same
     * as the single name entryFilename() used to sanitize as a whole.
     */
    private function entryFilename(Document $document, string $originalFilename): string
    {
        $parts = array_map(
            fn (string $part): string => $this->sanitizeSegment($this->flattenPathSeparators($part)),
            $this->submissionFilename->nameParts($document),
        );
        $stem      = implode(' - ', $parts);
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        return $extension === '' ? $stem : $stem . '.' . $this->sanitizeSegment($extension);
    }

    /**
     * A document's own name can itself carry a " › " path separator when an activity names its
     * submissions after a deep list element (see
     * ActivitySubmissionSlotBuilder::submissionName()) — fine on screen and in a single
     * revision's own Content-Disposition (UTF-8-aware), but a ZIP entry name is better kept to
     * characters every extraction tool treats the same way, so it collapses to "_" here instead.
     */
    private function flattenPathSeparators(string $name): string
    {
        return preg_replace('/\s*›\s*/u', '_', $name) ?? $name;
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

    /**
     * The folder's name — for an activity's folder, led by the academic year its submissions are
     * from ("2026-2027 - Programaciones.zip"), or the range of years when there are several
     * ("2024-2025 a 2026-2027 - Programaciones.zip").
     *
     * @param list<int> $cycles the activity cycles the ZIP holds (empty for a plain folder)
     */
    private function zipFilename(Folder $folder, array $cycles): string
    {
        $name = $this->sanitizeSegment($folder->getName());
        if ($cycles === []) {
            return $name . '.zip';
        }

        $first = ActivityDeadlineChecker::academicYearLabel(min($cycles));
        $last  = ActivityDeadlineChecker::academicYearLabel(max($cycles));
        $years = $first === $last ? $first : $first . ' a ' . $last;

        return $this->sanitizeSegment($years) . ' - ' . $name . '.zip';
    }
}
