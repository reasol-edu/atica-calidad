<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\DocumentRevisionRepository;
use App\Repository\FolderRepository;

/**
 * Document revisions awaiting review that a given teacher can actually act on — mirrors
 * ActivityDashboardSummaryBuilder's role for activity deadlines, so the notification bell can
 * combine both into one queue. Reviewer eligibility is per folder (DocumentTreeAccessChecker),
 * so the centre-wide pending list is filtered folder by folder rather than in one query.
 */
final class PendingReviewFinder
{
    public function __construct(
        private readonly DocumentRevisionRepository $revisions,
        private readonly DocumentTreeAccessChecker $access,
        private readonly FolderRepository $folders,
    ) {}

    /** @return list<DocumentRevision> oldest pending first */
    public function forTeacher(Teacher $teacher, EducationalCentre $centre): array
    {
        $reviewable = [];
        foreach ($this->revisions->findPendingReviewByCentre($centre) as $revision) {
            if ($this->access->canReviewFolder($teacher, $revision->getDocument()->getFolder())) {
                $reviewable[] = $revision;
            }
        }

        return $reviewable;
    }

    /**
     * Whether the teacher can review at least one folder in the centre, regardless of whether
     * anything is currently pending — distinguishes "nothing applies to me" from "I'm done" for the
     * dashboard widget, since forTeacher() alone returning an empty list can mean either.
     */
    public function hasReviewAccess(Teacher $teacher, EducationalCentre $centre): bool
    {
        foreach ($this->folders->findAllByCentre($centre) as $folder) {
            if ($this->access->canReviewFolder($teacher, $folder)) {
                return true;
            }
        }

        return false;
    }
}
