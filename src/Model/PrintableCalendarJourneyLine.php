<?php

declare(strict_types=1);

namespace App\Model;

/** The "jornadas" summary shown above the calendar for one period with showJourneySummary set. */
final readonly class PrintableCalendarJourneyLine
{
    /** @param list<string> $hoursLines already-translated ("47 jornadas de 8 horas", "y 1 de 4 horas"...); empty for a plain date-range period */
    public function __construct(
        public string $description,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public array $hoursLines,
    ) {}
}
