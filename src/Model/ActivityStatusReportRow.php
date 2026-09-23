<?php

declare(strict_types=1);

namespace App\Model;

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
    ) {}

    public function donePercentage(): int
    {
        return $this->expected === 0 ? 0 : (int) round($this->done / $this->expected * 100);
    }
}
