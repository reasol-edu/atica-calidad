<?php

declare(strict_types=1);

namespace App\Model;

/**
 * How many of a list-backed activity's elements (leaves) have a deadline of their own, for the
 * current occurrence — see ActivityDeadlineSummaryBuilder. When every element has one
 * ($coversAll), the activity's own general deadline is not used by anything, so $firstStart and
 * $lastEnd (the earliest opening and latest closing among them) are what to show instead.
 */
final readonly class ActivityDeadlineSummary
{
    public function __construct(
        public int $ownCount,
        public bool $coversAll,
        public ?\DateTimeImmutable $firstStart,
        public ?\DateTimeImmutable $lastEnd,
    ) {}

    public function hasOwnDeadlines(): bool
    {
        return $this->ownCount > 0;
    }
}
