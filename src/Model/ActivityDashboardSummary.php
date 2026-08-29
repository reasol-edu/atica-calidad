<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The dashboard's "my activities" widget data — not persisted, built on the fly by
 * ActivityDashboardSummaryBuilder. Counts cover every obligation applicable to the teacher
 * (including completed ones); $items only lists the ones still needing attention (pending or
 * overdue), capped and sorted overdue-first, then soonest-deadline-first.
 */
final readonly class ActivityDashboardSummary
{
    /** @param list<ActivityDashboardItem> $items */
    public function __construct(
        public int $total,
        public int $completed,
        public int $pending,
        public int $overdue,
        public array $items,
    ) {}

    public function completionPercentage(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) round($this->completed / $this->total * 100);
    }
}
