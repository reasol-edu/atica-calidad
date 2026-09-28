<?php

declare(strict_types=1);

namespace App\Model;

/** One month block of the date calculator's monthly grid (DateCalculatorService). */
final readonly class DateCalculatorMonth
{
    /** @param list<DateCalculatorWeek> $weeks */
    public function __construct(
        public int $year,
        public int $month,
        public array $weeks,
        public float $totalHours,
        public string $totalHoursLabel,
        public int $workingDays,
    ) {}
}
