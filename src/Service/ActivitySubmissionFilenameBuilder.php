<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;

/**
 * The parts of the display name for a document that's an activity's own submission — shared by
 * FolderController::download() (the Content-Disposition of a single-revision download) and
 * FolderZipExporter (the entry names inside a whole-folder ZIP), so the two can never drift apart
 * the way they once did (a ZIP of an Individual-scope activity used to name every teacher's
 * submission identically, e.g. several "Tutor/a.pdf" entries told apart only by ZipArchive's own
 * "(2)", "(3)"… de-duplication — see AttachmentZipExporter::uniqueName()).
 *
 * Each caller joins the parts with its own separator and applies its own sanitization (a
 * Content-Disposition header and a ZIP entry name don't forbid quite the same characters).
 */
final class ActivitySubmissionFilenameBuilder
{
    public function __construct(
        private readonly ActivityDeadlineChecker $deadline,
    ) {}

    /**
     * The activity's own "submission prefix" setting, set to exactly this, means "no prefix at
     * all" — not even the title — rather than a literal one-character prefix.
     */
    private const NO_PREFIX_SENTINEL = '-';

    /**
     * The document's own name, led by the academic year it was submitted for ("2026-2027" — the
     * same activity collects one submission per year, usually under the very same name), then by
     * the activity's "submission prefix" setting if it has one (its title otherwise, unless the
     * prefix is exactly "-", meaning no prefix at all) and,
     * for an Individual-scope activity (so this document belongs to one specific teacher, not
     * shared by everyone holding a profile), trailed by that teacher's name. A plain
     * document-tree file (no activity behind its folder) is just its own name, on its own.
     *
     * @return string[]
     */
    public function nameParts(Document $document): array
    {
        $activity = $document->getFolder()->getActivity();
        if ($activity === null) {
            return [$document->getName()];
        }

        // Every document in an activity's folder is stamped when created (see
        // DocumentActivityCycleListener); the current occurrence is only a safety net.
        $parts  = [ActivityDeadlineChecker::academicYearLabel($document->getActivityCycleYear() ?? $this->deadline->currentCycleKey($activity))];
        $prefix = $activity->getSubmissionPrefix();
        if ($prefix !== self::NO_PREFIX_SENTINEL) {
            $parts[] = $prefix !== null && $prefix !== '' ? $prefix : $activity->getTitle();
        }
        $parts[] = $document->getName();

        if ($activity->getSubmissionScope() === ActivitySubmissionScope::Individual) {
            $uploader = $document->getFirstRevision()?->getUploadedBy();
            if ($uploader !== null) {
                $parts[] = $uploader->getName()->getLastName() . ', ' . $uploader->getName()->getFirstName();
            }
        }

        return $parts;
    }
}
