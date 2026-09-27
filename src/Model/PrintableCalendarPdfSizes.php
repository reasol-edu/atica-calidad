<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Font sizes (in pt) for one PrintableCalendar's PDF, already scaled from its orientation's
 * defaults by its fontSizeScale — see PrintableCalendarPdfBuilder::sizesFor(). The template only
 * reads these, it never computes a size itself.
 */
final readonly class PrintableCalendarPdfSizes
{
    public function __construct(
        /** Only used for the in-content title shown when the calendar's own running header is hidden. */
        public float $h1,
        public float $description,
        public float $journey,
        public float $monthCaption,
        public float $weekdayHeader,
        public float $dayCell,
        public float $hoursLabel,
        public float $annotation,
    ) {}
}
