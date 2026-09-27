<?php

declare(strict_types=1);

namespace App\Model;

/** One line of a month's side annotation column (PrintableCalendarPdfBuilder). */
final readonly class PrintableCalendarAnnotation
{
    public function __construct(
        /** Day number, or "D1-D2" when it covers a consecutive run within the month. */
        public string $label,
        public string $color,
        public string $description,
    ) {}
}
