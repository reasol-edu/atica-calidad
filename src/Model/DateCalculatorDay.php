<?php

declare(strict_types=1);

namespace App\Model;

/** One day cell of the date calculator's monthly grid (DateCalculatorService). */
final readonly class DateCalculatorDay
{
    public function __construct(
        public \DateTimeImmutable $date,
        public float $hours,
        /** Already formatted (e.g. "4,5"), null when $hours is 0 (nothing to show in the cell). */
        public ?string $hoursLabel,
    ) {}
}
