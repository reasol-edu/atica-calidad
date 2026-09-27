<?php

declare(strict_types=1);

namespace App\Model;

/** One day cell of a PrintableCalendar's month grid (PrintableCalendarPdfBuilder). */
final readonly class PrintableCalendarDay
{
    public function __construct(
        public \DateTimeImmutable $date,
        public ?string $backgroundColor,
        /** Already formatted (e.g. "4,5"), for a day an hours-based period assigned. */
        public ?string $hoursLabel,
    ) {}
}
