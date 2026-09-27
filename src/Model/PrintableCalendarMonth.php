<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One month of a PrintableCalendar's grid (PrintableCalendarPdfBuilder): full Monday-Sunday weeks,
 * with `null` for the padding cells before day 1 or after the last day. `annotations` renders to
 * the month's left when it's first in its row of two, or to its right when it's second.
 */
final readonly class PrintableCalendarMonth
{
    /**
     * @param list<list<?PrintableCalendarDay>> $weeks
     * @param list<PrintableCalendarAnnotation> $annotations
     */
    public function __construct(
        public int $year,
        public int $month,
        public array $weeks,
        public array $annotations,
    ) {}
}
