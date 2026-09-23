<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityObligationStatus;
use App\Repository\TeacherRepository;

/**
 * Who still has something to do for an activity right now — the recipients of "Recordar a
 * pendientes" — from the same statuses every screen shows (ActivityObligationFinder): the
 * teachers of the centre's active year holding at least one obligation in the "todo" group (open,
 * rejected, late or overdue). Not included: whoever is only waiting for a review, hasn't opened
 * yet, is closed or done.
 */
final class ActivityPendingOwnersFinder
{
    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly ActivityObligationFinder $obligations,
    ) {}

    /** @return list<array{teacher: Teacher, items: non-empty-list<ActivityDashboardItem>}> by name */
    public function find(Activity $activity): array
    {
        $year = $activity->getCategory()->getEducationalCentre()->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        $pending = [];
        foreach ($this->teachers->findByAcademicYearOrderedByName($year) as $teacher) {
            $items = array_values(array_filter(
                $this->obligations->forActivity($teacher, $activity),
                static fn (ActivityDashboardItem $i): bool => $i->status->group() === ActivityObligationStatus::GROUP_TODO,
            ));
            if ($items !== []) {
                $pending[] = ['teacher' => $teacher, 'items' => $items];
            }
        }

        return $pending;
    }
}
