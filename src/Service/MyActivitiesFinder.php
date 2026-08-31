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
 * Every activity obligation a teacher owns in a centre, one item per owner row — unlike
 * ActivityDashboardSummaryBuilder (built for the dashboard's capped "needs attention" widget),
 * this includes completed obligations too (with ActivityDashboardStatus::Completed) and is never
 * capped, since the "Mis actividades" tab is meant to show everything. Callers filter/sort/group
 * the returned list themselves.
 */
final class MyActivitiesFinder
{
    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ActivityCompletionChecker $completion,
        private readonly ActivityDeadlineChecker $deadline,
    ) {}

    /** @return list<ActivityDashboardItem> */
    public function forTeacher(Teacher $teacher, EducationalCentre $centre): array
    {
        $items = [];

        foreach ($this->activities->findAllByCentre($centre) as $activity) {
            foreach ($this->completion->getMyOwnedObligations($teacher, $activity) as $owner) {
                $completed = $this->completion->isCompletedFor($activity, $owner['profile'], $owner['listItem'], $owner['teacher']);

                $status = match (true) {
                    $completed                          => ActivityDashboardStatus::Completed,
                    $this->deadline->isOverdue($activity) => ActivityDashboardStatus::Overdue,
                    default                              => ActivityDashboardStatus::Pending,
                };

                $items[] = new ActivityDashboardItem(
                    $activity,
                    $status,
                    $this->categoryPath($activity->getCategory()),
                    $owner['label'],
                    $this->deadline->currentCycleEndDate($activity),
                );
            }
        }

        return $items;
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
