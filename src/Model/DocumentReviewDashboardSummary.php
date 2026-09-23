<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The dashboard's "pending review" widget data — not persisted, built on the fly by
 * DashboardPendingReviewComponent. $hasAccess is false for a teacher with no review access to any
 * folder in the centre, in which case the widget renders nothing at all; $total counts every
 * revision awaiting review, $groups the lines actually shown (capped): one per activity with its
 * submissions together, or one per document elsewhere (see PendingReviewFinder::group()).
 */
final readonly class DocumentReviewDashboardSummary
{
    /** @param list<PendingReviewGroup> $groups */
    public function __construct(
        public bool $hasAccess,
        public int $total,
        public array $groups,
    ) {}

    /** How many revisions the shown groups hold — less than $total when some groups were left out. */
    public function shownCount(): int
    {
        return array_sum(array_map(static fn (PendingReviewGroup $g): int => $g->count(), $this->groups));
    }
}
