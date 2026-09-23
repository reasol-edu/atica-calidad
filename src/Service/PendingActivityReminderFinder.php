<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityObligationStatus;

/**
 * What the daily pending-activity reminder email lists for one teacher, from the same statuses
 * every screen shows (ActivityObligationFinder):
 *
 * - "overdue": past the deadline and still doable (ActivityObligationStatus::isOverdue()).
 * - "dueSoon": open — or rejected and to be submitted again — with the deadline within
 *   $warningDays.
 *
 * Never listed: what's waiting for someone else's approval (the teacher already did their part),
 * what hasn't opened yet, what's closed (nothing left to do) and what's completed.
 */
final class PendingActivityReminderFinder
{
    public function __construct(
        private readonly ActivityObligationFinder $obligations,
    ) {}

    /** @return array{dueSoon: list<ActivityDashboardItem>, overdue: list<ActivityDashboardItem>} */
    public function forTeacher(Teacher $teacher, EducationalCentre $centre, int $warningDays): array
    {
        $dueSoon = [];
        $overdue = [];

        foreach ($this->obligations->forTeacher($teacher, $centre) as $item) {
            if ($item->status->isOverdue()) {
                $overdue[] = $item;
            } elseif (in_array($item->status, [ActivityObligationStatus::Open, ActivityObligationStatus::Rejected], true) && $item->daysLeft <= $warningDays) {
                $dueSoon[] = $item;
            }
        }

        $byDeadline = static fn (ActivityDashboardItem $a, ActivityDashboardItem $b): int => $a->deadline <=> $b->deadline;
        usort($dueSoon, $byDeadline);
        usort($overdue, $byDeadline);

        return ['dueSoon' => $dueSoon, 'overdue' => $overdue];
    }
}
