<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Model\ActivityDeadlineSummary;
use App\Repository\ListItemRepository;

/** Summarises an activity's per-element deadline overrides (see Activity::getDeadlineOverride()) for display and reporting. */
final class ActivityDeadlineSummaryBuilder
{
    public function __construct(
        private readonly ListItemRepository $listItems,
        private readonly ActivityDeadlineChecker $deadline,
    ) {}

    public function for(Activity $activity): ActivityDeadlineSummary
    {
        $root = $activity->getListItem();
        if ($root === null || $activity->getListItemDeadlines()->isEmpty()) {
            return new ActivityDeadlineSummary(0, false, null, null);
        }

        $leaves = $this->listItems->findLeafDescendants($root);
        $starts = $ends = [];
        foreach ($leaves as $leaf) {
            if ($activity->getDeadlineOverride($leaf) !== null) {
                $starts[] = $this->deadline->currentCycleStartDate($activity, $leaf);
                $ends[]   = $this->deadline->currentCycleEndDate($activity, $leaf);
            }
        }

        if ($starts === [] || $ends === []) {
            return new ActivityDeadlineSummary(0, false, null, null);
        }

        return new ActivityDeadlineSummary(count($starts), count($starts) === count($leaves), min($starts), max($ends));
    }
}
