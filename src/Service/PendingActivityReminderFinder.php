<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityDashboardStatus;
use App\Repository\ActivityRepository;

/**
 * The activities a teacher has pending completion for, split for the daily reminder email into
 * "due soon" (cycle already open, deadline within the configured warning window, not yet overdue)
 * and "overdue" (deadline already passed) — an activity whose cycle hasn't started yet
 * (ActivityDeadlineChecker::hasStarted()) is excluded from both, however close its default
 * yearly date might look on the calendar. Unlike ActivityDashboardSummaryBuilder (built for a
 * capped UI widget), both lists here are uncapped — a digest email should mention everything.
 */
final class PendingActivityReminderFinder
{
    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ActivityCompletionChecker $completion,
        private readonly ActivityDeadlineChecker $deadline,
    ) {}

    /** @return array{dueSoon: list<ActivityDashboardItem>, overdue: list<ActivityDashboardItem>} */
    public function forTeacher(Teacher $teacher, EducationalCentre $centre, int $warningDays): array
    {
        $dueSoon = [];
        $overdue = [];

        foreach ($this->activities->findAllByCentre($centre) as $activity) {
            if (!$this->deadline->hasStarted($activity)) {
                continue;
            }

            foreach ($this->completion->getMyOwnedObligations($teacher, $activity) as $owner) {
                if ($this->completion->isCompletedFor($activity, $owner['profile'], $owner['listItem'], $owner['teacher'])) {
                    continue;
                }

                if ($this->deadline->isOverdue($activity)) {
                    $overdue[] = $this->item($activity, ActivityDashboardStatus::Overdue, $owner['label']);

                    continue;
                }

                if ($this->deadline->daysUntilDeadline($activity) <= $warningDays) {
                    $dueSoon[] = $this->item($activity, ActivityDashboardStatus::Pending, $owner['label']);
                }
            }
        }

        $byDeadline = static fn (ActivityDashboardItem $a, ActivityDashboardItem $b): int => $a->deadline <=> $b->deadline;
        usort($dueSoon, $byDeadline);
        usort($overdue, $byDeadline);

        return ['dueSoon' => $dueSoon, 'overdue' => $overdue];
    }

    private function item(Activity $activity, ActivityDashboardStatus $status, ?string $ownerLabel): ActivityDashboardItem
    {
        return new ActivityDashboardItem(
            $activity,
            $status,
            $this->categoryPath($activity->getCategory()),
            $ownerLabel,
            $this->deadline->currentCycleEndDate($activity),
        );
    }

    private function categoryPath(ActivityCategory $category): string
    {
        $trail = [];
        for ($c = $category; $c !== null; $c = $c->getParent()) {
            array_unshift($trail, $c->getName());
        }

        return implode(' › ', $trail);
    }
}
