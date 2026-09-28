<?php

declare(strict_types=1);

namespace App\Model;

/** One calendar week row of the date calculator's monthly grid (DateCalculatorService). */
final readonly class DateCalculatorWeek
{
    /** @param list<?DateCalculatorDay> $days exactly 7, Monday to Sunday; null pads days outside the month */
    public function __construct(
        public array $days,
        public float $totalHours,
        public string $totalHoursLabel,
    ) {}
}
