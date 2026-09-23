<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;

/**
 * The documents a teacher should be reminded to review: those whose next review date has passed or
 * falls within $warningDays, in folders the teacher is in charge of — holding one of the folder's
 * responsible profiles, or, for a folder with no responsible profile at all, being one of the
 * centre's quality managers (so no document is left without anyone to remind). Same scope as the
 * document master list: no obsolete folders, no activity folders.
 */
final class DocumentNextReviewReminderFinder
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentTreeAccessChecker $access,
        private readonly DocumentReviewSchedule $schedule,
    ) {}

    /** @return list<Document> soonest review first */
    public function forTeacher(Teacher $teacher, EducationalCentre $centre, int $warningDays): array
    {
        $until = $this->schedule->today()->modify('+' . max(0, $warningDays) . ' days');

        return array_values(array_filter(
            $this->documents->findWithNextReviewUpTo($centre, $until),
            fn (Document $document): bool => $this->isInChargeOf($teacher, $document->getFolder(), $centre),
        ));
    }

    private function isInChargeOf(Teacher $teacher, Folder $folder, EducationalCentre $centre): bool
    {
        return $folder->getResponsibleProfiles()->isEmpty()
            ? $centre->getQualityManagers()->contains($teacher)
            : $this->access->holdsResponsibleProfile($teacher, $folder);
    }
}
