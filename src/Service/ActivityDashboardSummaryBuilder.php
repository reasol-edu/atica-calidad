<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityDashboardStatus;
use App\Model\ActivityDashboardSummary;
use App\Repository\ActivityRepository;

/**
 * Builds the dashboard's "my activities" widget: every obligation applicable to a teacher by
 * upload profile (narrower than DocumentTreeAccessChecker::isActivityRelevantToTeacher(), which
 * also counts managers/reviewers with no upload slot of their own), with its completed/pending/
 * overdue status.
 */
final class ActivityDashboardSummaryBuilder
{
    private const int MAX_ITEMS = 8;

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ActivityCompletionChecker $completion,
        private readonly ActivityDeadlineChecker $deadline,
    ) {}

    public function build(Teacher $teacher, EducationalCentre $centre): ActivityDashboardSummary
    {
        $total = 0;
        $completed = 0;
        $pending = 0;
        $overdue = 0;
        $needsAttention = [];

        foreach ($this->activities->findAllByCentre($centre) as $activity) {
            foreach ($this->ownersFor($teacher, $activity) as $owner) {
                ++$total;

                if ($this->completion->isCompletedFor($activity, $owner['profile'], $owner['listItem'], $owner['teacher'])) {
                    ++$completed;
                    continue;
                }

                $isOverdue = $this->deadline->isOverdue($activity);
                if ($isOverdue) {
                    ++$overdue;
                } else {
                    ++$pending;
                }

                $needsAttention[] = new ActivityDashboardItem(
                    $activity,
                    $isOverdue ? ActivityDashboardStatus::Overdue : ActivityDashboardStatus::Pending,
                    $this->categoryPath($activity->getCategory()),
                    $owner['label'],
                    $this->deadline->currentCycleEndDate($activity),
                );
            }
        }

        usort($needsAttention, static function (ActivityDashboardItem $a, ActivityDashboardItem $b): int {
            if ($a->status !== $b->status) {
                return $a->status === ActivityDashboardStatus::Overdue ? -1 : 1;
            }

            return $a->deadline <=> $b->deadline;
        });

        return new ActivityDashboardSummary($total, $completed, $pending, $overdue, array_slice($needsAttention, 0, self::MAX_ITEMS));
    }

    /**
     * Every obligation $teacher personally owns for $activity. A no-folder activity applies to
     * every teacher individually; a folder-backed one only if $teacher actually holds an upload
     * slot — ByProfile scope can yield more than one owner row (e.g. head of two departments).
     *
     * @return list<array{profile: ?SpecificProfile, listItem: ?ListItem, teacher: ?Teacher, label: ?string}>
     */
    private function ownersFor(Teacher $teacher, Activity $activity): array
    {
        if (!$activity->requiresSubmissions()) {
            return [['profile' => null, 'listItem' => null, 'teacher' => $teacher, 'label' => null]];
        }

        if ($this->completion->hasIndividualCompletionOwner($activity)) {
            if ($this->completion->getMyOwnedSlots($teacher, $activity) === []) {
                return [];
            }

            return [['profile' => null, 'listItem' => null, 'teacher' => $teacher, 'label' => null]];
        }

        return array_map(
            static fn (array $owner): array => [
                'profile'  => $owner['profile'],
                'listItem' => $owner['listItem'],
                'teacher'  => null,
                'label'    => $owner['profile']->getName() . ($owner['listItem'] !== null ? ' ' . $owner['listItem']->getName() : ''),
            ],
            $this->completion->getMyOwnedCompletionOwners($teacher, $activity),
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
