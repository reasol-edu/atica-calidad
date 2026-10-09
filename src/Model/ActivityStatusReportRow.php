<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;

/** One activity in the "activity status" report (see ActivityStatusReportBuilder), for its current occurrence. */
final readonly class ActivityStatusReportRow
{
    public function __construct(
        public string $categoryPath,
        public string $title,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $deadline,
        /** True when completed by submitting documents (it has a folder); false for a manual one. */
        public bool $withSubmissions,
        /** Submissions expected, or teachers it applies to (manual). */
        public int $expected,
        /** Submissions sent, whatever their state (equals $done for a manual one). */
        public int $delivered,
        /** Submissions accepted, or teachers who marked it completed. */
        public int $done,
        public int $inReview,
        public int $rejected,
        /** Expected submissions (or teachers, for a manual one) still pending past their own deadline. */
        public int $overdue = 0,
        /** How many list elements have a deadline of their own (they can differ from $deadline). */
        public int $elementsWithOwnDeadline = 0,
        /** The activity itself, for the tracking panel to link to it (and to its pending list). */
        public ?Activity $activity = null,
    ) {}

    /** Expected submissions (or teachers) neither done nor waiting for a review: who still has to act. */
    public function pending(): int
    {
        return max(0, $this->expected - $this->done - $this->inReview);
    }

    /** Whole days from $today to the deadline: 0 today, negative once past. */
    public function daysLeft(\DateTimeImmutable $today): int
    {
        return (int) $today->setTime(0, 0)->diff($this->deadline->setTime(0, 0))->format('%r%a');
    }

    public function donePercentage(): int
    {
        return $this->expected === 0 ? 0 : (int) round($this->done / $this->expected * 100);
    }
}
