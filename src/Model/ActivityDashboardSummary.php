<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The dashboard's "next steps" widget data — not persisted, built on the fly by
 * ActivityDashboardSummaryBuilder from the teacher's obligations (see ActivityObligationFinder).
 * The counts cover every obligation; $nextSteps only the most urgent ones the teacher can act on
 * right now; $nextUpcoming the next one to open, to show when there's nothing left to do.
 */
final readonly class ActivityDashboardSummary
{
    /** @param list<ActivityDashboardItem> $nextSteps */
    public function __construct(
        public int $total,
        /** Actionable right now (ActivityObligationStatus::GROUP_TODO). */
        public int $todo,
        /** Of $todo, the ones past their deadline. */
        public int $overdue,
        public int $inReview,
        public int $completed,
        public array $nextSteps,
        public ?ActivityDashboardItem $nextUpcoming,
    ) {}

    public function completionPercentage(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) round($this->completed / $this->total * 100);
    }
}
