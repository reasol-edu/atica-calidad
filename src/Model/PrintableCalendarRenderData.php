<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\PrintableCalendar;

/** Everything PrintableCalendarPdfBuilder computes for one PrintableCalendar's PDF — the Twig
 * template (templates/utilities/pdf/calendar.html.twig) only lays it out, it computes nothing. */
final readonly class PrintableCalendarRenderData
{
    /**
     * @param list<PrintableCalendarMonth>       $months
     * @param list<PrintableCalendarJourneyLine> $journeySummaries
     */
    public function __construct(
        public PrintableCalendar $calendar,
        public \DateTimeImmutable $effectiveStart,
        public \DateTimeImmutable $effectiveEnd,
        public array $months,
        public array $journeySummaries,
        public PrintableCalendarPdfSizes $sizes,
    ) {}
}
