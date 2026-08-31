<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\DocumentRevisionRepository;
use App\Repository\FolderRepository;

/**
 * Document revisions awaiting review that are *personally* a given teacher's own responsibility —
 * mirrors ActivityDashboardSummaryBuilder's role for activity deadlines, so the notification bell
 * can combine both into one queue. Deliberately narrower than
 * DocumentTreeAccessChecker::canReviewFolder(): an admin/quality manager who doesn't hold any
 * review profile themselves is entitled to review anything, but forTeacher()/hasReviewAccess() only
 * count what a review profile actually assigns them — the bell and the dashboard's personal widget
 * would otherwise flood an admin with every pending revision in the centre regardless of whether
 * it's theirs to act on. See allPendingForCentre() for the separate, admin-only "everything" view.
 */
final class PendingReviewFinder
{
    public function __construct(
        private readonly DocumentRevisionRepository $revisions,
        private readonly DocumentTreeAccessChecker $access,
        private readonly FolderRepository $folders,
    ) {}

    /** @return list<DocumentRevision> oldest pending first, personally the teacher's own to review */
    public function forTeacher(Teacher $teacher, EducationalCentre $centre): array
    {
        $reviewable = [];
        foreach ($this->revisions->findPendingReviewByCentre($centre) as $revision) {
            if ($this->access->holdsReviewProfile($teacher, $revision->getDocument()->getFolder())) {
                $reviewable[] = $revision;
            }
        }

        return $reviewable;
    }

    /**
     * Whether the teacher personally holds a review profile on at least one folder in the centre,
     * regardless of whether anything is currently pending — distinguishes "nothing applies to me"
     * from "I'm done" for the dashboard widget, since forTeacher() alone returning an empty list
     * can mean either.
     */
    public function hasReviewAccess(Teacher $teacher, EducationalCentre $centre): bool
    {
        foreach ($this->folders->findAllByCentre($centre) as $folder) {
            if ($this->access->holdsReviewProfile($teacher, $folder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every revision pending review in the centre, regardless of who personally holds the review
     * profile — feeds the admin/quality-manager-only "Todas las revisiones pendientes" dashboard
     * section, never the notification bell.
     *
     * @return list<DocumentRevision> oldest pending first
     */
    public function allPendingForCentre(EducationalCentre $centre): array
    {
        return $this->revisions->findPendingReviewByCentre($centre);
    }
}
