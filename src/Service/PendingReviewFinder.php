<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\DocumentRevisionRepository;

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
}
