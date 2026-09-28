<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Indicator;
use App\Entity\NonWorkingDay;
use App\Entity\PrintableCalendar;
use App\Entity\PrintableCalendarPeriod;
use App\Entity\PrintableCalendarPeriodMode;
use App\Model\PrintableCalendarAnnotation;
use App\Model\PrintableCalendarDay;
use App\Model\PrintableCalendarJourneyLine;
use App\Model\PrintableCalendarMonth;
use App\Model\PrintableCalendarPdfSizes;
use App\Model\PrintableCalendarRenderData;
use App\Repository\NonWorkingDayRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a PrintableCalendar into everything its PDF template needs to lay out (no calculation left
 * for Twig): the effective date range, up to 12 months of day-by-day colours and hour labels, each
 * month's side annotations, and the "jornadas" summary for periods that ask for one. See
 * skills/database.md and the class docblocks on PrintableCalendar/PrintableCalendarPeriod for the
 * data model, and the approved plan (Utilidades › Generador de calendarios) for the algorithm.
 *
 * @phpstan-type PeriodDay array{date: \DateTimeImmutable, hours: ?float, quota: ?float}
 */
final class PrintableCalendarPdfBuilder
{
    private const int MAX_MONTHS = 12;

    /** A month box always renders this many week rows, padding with blank ones, so every month — 4, 5 or 6 calendar weeks — lines up at the same height. */
    private const int WEEKS_PER_MONTH_BOX = 6;

    /**
     * Default font sizes (pt, at fontSizeScale 100) per orientation — portrait's are smaller, per
     * the user's request, since a portrait page is narrower. PrintableCalendarPdfSizes' properties
     * name each key, so `new PrintableCalendarPdfSizes(...$scaled)` below relies on these matching.
     */
    private const array DEFAULT_SIZES = [
        'portrait' => ['h1' => 13.0, 'description' => 7.5, 'journey' => 7.0, 'monthCaption' => 8.0, 'weekdayHeader' => 6.0, 'dayCell' => 6.5, 'hoursLabel' => 5.0, 'annotation' => 6.0],
        'landscape' => ['h1' => 15.0, 'description' => 8.5, 'journey' => 8.0, 'monthCaption' => 9.0, 'weekdayHeader' => 6.5, 'dayCell' => 7.5, 'hoursLabel' => 5.5, 'annotation' => 6.5],
    ];

    public function __construct(
        private readonly NonWorkingDayChecker $nonWorkingDays,
        private readonly NonWorkingDayRepository $nonWorkingDayRepository,
        private readonly WeekdayHoursWalker $dayWalker,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {}

    public function build(PrintableCalendar $calendar): PrintableCalendarRenderData
    {
        $year = $calendar->getAcademicYear();

        /** @var array<string, list<PeriodDay>> $periodDays period id => its days */
        $periodDays = [];
        foreach ($calendar->getPeriods() as $period) {
            $periodDays[$period->getId()->toRfc4122()] = $period->getMode() === PrintableCalendarPeriodMode::DateRange
                ? $this->dateRangeDays($period)
                : $this->hourDays($period, $year);
        }

        [$start, $end] = $this->effectiveRange($calendar, $periodDays);

        $journeySummaries = [];
        foreach ($calendar->getPeriods() as $period) {
            if (!$period->isShowJourneySummary()) {
                continue;
            }
            $line = $this->journeySummary($period, $periodDays[$period->getId()->toRfc4122()]);
            if ($line !== null) {
                $journeySummaries[] = $line;
            }
        }

        $months = [];
        $cursor    = $start->modify('first day of this month');
        $lastMonth = $end->modify('first day of this month');
        $count     = 0;
        while ($cursor <= $lastMonth && $count < self::MAX_MONTHS) {
            $months[] = $this->buildMonth($cursor, $calendar, $periodDays, $year);
            $cursor   = $cursor->modify('first day of next month');
            ++$count;
        }

        return new PrintableCalendarRenderData($calendar, $start, $end, $months, $journeySummaries, $this->sizesFor($calendar));
    }

    private function sizesFor(PrintableCalendar $calendar): PrintableCalendarPdfSizes
    {
        $base  = self::DEFAULT_SIZES[$calendar->getOrientation()->value];
        $scale = $calendar->getFontSizeScale() / 100.0;

        /** @var array<string, float> $scaled */
        $scaled = array_map(static fn (float $pt): float => round($pt * $scale, 2), $base);

        return new PrintableCalendarPdfSizes(...$scaled);
    }

    /** @return list<PeriodDay> */
    private function dateRangeDays(PrintableCalendarPeriod $period): array
    {
        $start = $period->getStartDate();
        $end   = $period->getEndDate();
        if ($start === null || $end === null || $end < $start) {
            return [];
        }

        $days   = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $days[] = ['date' => $cursor, 'hours' => null, 'quota' => null];
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    /**
     * Walks day by day from the known end towards the unknown one, skipping weekends and the
     * academic year's non-working days, assigning each remaining weekday's configured quota (or
     * whatever is left of totalHours, if less) until it's exhausted — WeekdayHoursWalker, shared
     * with the date calculator (Utilidades › Calculadora de fechas).
     *
     * @return list<PeriodDay>
     */
    private function hourDays(PrintableCalendarPeriod $period, AcademicYear $year): array
    {
        $remaining = $period->getTotalHours();
        $forward   = $period->getMode() === PrintableCalendarPeriodMode::StartWithHours;
        $cursor    = $forward ? $period->getStartDate() : $period->getEndDate();
        if ($cursor === null || $remaining === null || $remaining <= 0.0) {
            return [];
        }

        return $this->dayWalker->walk($year, $cursor, $forward, $period->weekdayHours(), $remaining)['days'];
    }

    /**
     * @param array<string, list<PeriodDay>> $periodDays
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function effectiveRange(PrintableCalendar $calendar, array $periodDays): array
    {
        $dates = [];
        foreach ($periodDays as $days) {
            foreach ($days as $day) {
                $dates[] = $day['date'];
            }
        }
        foreach ($calendar->getDates() as $date) {
            $dates[] = $date->getDate();
        }

        if ($dates !== []) {
            $min = min($dates);
            $max = max($dates);
        } else {
            $min = $max = $this->clock->now();
        }

        return [$calendar->getStartDate() ?? $min, $calendar->getEndDate() ?? $max];
    }

    /** @param array<string, list<PeriodDay>> $periodDays */
    private function buildMonth(\DateTimeImmutable $monthStart, PrintableCalendar $calendar, array $periodDays, AcademicYear $year): PrintableCalendarMonth
    {
        $monthEnd = $monthStart->modify('last day of this month');

        /** @var array<string, array{color: string, hours: ?float}> $paint ISO date => paint, lowest to highest priority as it's built */
        $paint  = [];
        $cursor = $monthStart;
        while ($cursor <= $monthEnd) {
            $iso = $cursor->format('Y-m-d');
            if ($this->nonWorkingDays->isWeekend($cursor)) {
                $paint[$iso] = ['color' => $calendar->getWeekendColor() ?? PrintableCalendar::DEFAULT_WEEKEND_COLOR, 'hours' => null];
            }
            if ($this->nonWorkingDays->descriptionFor($year, $cursor) !== null) {
                $paint[$iso] = ['color' => $calendar->getNonWorkingDayColor() ?? PrintableCalendar::DEFAULT_NON_WORKING_DAY_COLOR, 'hours' => null];
            }
            $cursor = $cursor->modify('+1 day');
        }
        foreach ($calendar->getPeriods() as $period) {
            foreach ($periodDays[$period->getId()->toRfc4122()] as $day) {
                if ($day['date'] < $monthStart || $day['date'] > $monthEnd) {
                    continue;
                }
                $paint[$day['date']->format('Y-m-d')] = ['color' => $period->getColor(), 'hours' => $day['hours']];
            }
        }
        foreach ($calendar->getDates() as $date) {
            if ($date->getDate() < $monthStart || $date->getDate() > $monthEnd) {
                continue;
            }
            $iso         = $date->getDate()->format('Y-m-d');
            $paint[$iso] = ['color' => $date->getColor(), 'hours' => $paint[$iso]['hours'] ?? null];
        }

        $weeks        = [];
        $firstWeekday = (int) $monthStart->format('N');
        $cursor       = $monthStart->modify('-' . ($firstWeekday - 1) . ' days');
        do {
            $week = [];
            for ($i = 0; $i < 7; ++$i) {
                if ((int) $cursor->format('n') !== (int) $monthStart->format('n')) {
                    $week[] = null;
                } else {
                    $iso       = $cursor->format('Y-m-d');
                    $entry     = $paint[$iso] ?? null;
                    $showHours = $calendar->isShowHours() && $entry !== null && $entry['hours'] !== null;
                    $week[]    = new PrintableCalendarDay($cursor, $entry['color'] ?? null, $showHours ? Indicator::number($entry['hours']) : null);
                }
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        } while ($cursor <= $monthEnd);

        // Every month box is exactly 6 weeks tall, even one that only needs 4 or 5 — otherwise
        // side-by-side months (and the ones below them) would render at different heights.
        while (\count($weeks) < self::WEEKS_PER_MONTH_BOX) {
            $weeks[] = array_fill(0, 7, null);
        }

        return new PrintableCalendarMonth(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
            $weeks,
            $this->buildAnnotations($monthStart, $monthEnd, $calendar, $periodDays, $year),
        );
    }

    /**
     * @param array<string, list<PeriodDay>> $periodDays
     *
     * @return list<PrintableCalendarAnnotation>
     */
    private function buildAnnotations(\DateTimeImmutable $monthStart, \DateTimeImmutable $monthEnd, PrintableCalendar $calendar, array $periodDays, AcademicYear $year): array
    {
        /** @var list<array{sort: \DateTimeImmutable, item: PrintableCalendarAnnotation}> $entries */
        $entries = [];

        // Individual dates: one line each, never merged — even two sharing the same date.
        foreach ($calendar->getDates() as $date) {
            if ($date->getDate() < $monthStart || $date->getDate() > $monthEnd) {
                continue;
            }
            $entries[] = ['sort' => $date->getDate(), 'item' => new PrintableCalendarAnnotation((string) (int) $date->getDate()->format('j'), $date->getColor(), $date->getDescription())];
        }

        // Periods: only their overall start and end day get a margin entry — never one per week
        // or one per month it passes through — labelled "Inicio: …" / "Fin: …" so the two chips
        // (often far apart, sometimes in the same month) are never mistaken for one another. A
        // single-day period gets one plain entry, with no "Inicio"/"Fin" to distinguish.
        foreach ($calendar->getPeriods() as $period) {
            $days = $periodDays[$period->getId()->toRfc4122()];
            if ($days === []) {
                continue;
            }
            $start = $days[0]['date'];
            $end   = $days[array_key_last($days)]['date'];
            if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
                if ($start >= $monthStart && $start <= $monthEnd) {
                    $entries[] = ['sort' => $start, 'item' => new PrintableCalendarAnnotation($this->rangeLabel($start, $start), $period->getColor(), $period->getDescription())];
                }

                continue;
            }
            if ($start >= $monthStart && $start <= $monthEnd) {
                $entries[] = ['sort' => $start, 'item' => new PrintableCalendarAnnotation($this->rangeLabel($start, $start), $period->getColor(), $this->translator->trans('pdf.period_start', ['%description%' => $period->getDescription()], 'utilities'))];
            }
            if ($end >= $monthStart && $end <= $monthEnd) {
                $entries[] = ['sort' => $end, 'item' => new PrintableCalendarAnnotation($this->rangeLabel($end, $end), $period->getColor(), $this->translator->trans('pdf.period_end', ['%description%' => $period->getDescription()], 'utilities'))];
            }
        }

        // Declared non-working days: consecutive dates with the same description collapse too.
        foreach ($this->holidayRuns($monthStart, $monthEnd, $year) as $run) {
            $entries[] = [
                'sort' => $run['start'],
                'item' => new PrintableCalendarAnnotation(
                    $this->rangeLabel($run['start'], $run['end']),
                    $calendar->getNonWorkingDayColor() ?? PrintableCalendar::DEFAULT_NON_WORKING_DAY_COLOR,
                    $run['description'] ?? $this->translator->trans('pdf.non_working_day', [], 'utilities'),
                ),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return array_map(static fn (array $e): PrintableCalendarAnnotation => $e['item'], $entries);
    }

    /** @return list<array{start: \DateTimeImmutable, end: \DateTimeImmutable, description: ?string}> */
    private function holidayRuns(\DateTimeImmutable $monthStart, \DateTimeImmutable $monthEnd, AcademicYear $year): array
    {
        $inMonth = array_values(array_filter(
            $this->nonWorkingDayRepository->findByAcademicYearOrdered($year),
            static fn (NonWorkingDay $day): bool => $day->getDate() >= $monthStart && $day->getDate() <= $monthEnd,
        ));

        $runs    = [];
        $current = null;
        foreach ($inMonth as $day) {
            if ($current !== null && (int) $current['end']->diff($day->getDate())->days === 1 && $day->getDescription() === $current['description']) {
                $current['end'] = $day->getDate();

                continue;
            }
            if ($current !== null) {
                $runs[] = $current;
            }
            $current = ['start' => $day->getDate(), 'end' => $day->getDate(), 'description' => $day->getDescription()];
        }
        if ($current !== null) {
            $runs[] = $current;
        }

        return $runs;
    }

    private function rangeLabel(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        $first = (int) $start->format('j');
        $last  = (int) $end->format('j');

        return $first === $last ? (string) $first : $first . '-' . $last;
    }

    /** @param list<PeriodDay> $days */
    private function journeySummary(PrintableCalendarPeriod $period, array $days): ?PrintableCalendarJourneyLine
    {
        if ($period->getMode() === PrintableCalendarPeriodMode::DateRange) {
            $start = $period->getStartDate();
            $end   = $period->getEndDate();

            return $start === null || $end === null ? null : new PrintableCalendarJourneyLine($period->getDescription(), $start, $end, []);
        }

        if ($days === []) {
            return null;
        }

        $last = $days[array_key_last($days)];
        $main = \array_slice($days, 0, -1);
        // Did the last jornada get less than its weekday's usual quota (totalHours ran out mid-day)?
        $lastPartial = $last['quota'] !== null && $last['hours'] !== null && $last['hours'] < $last['quota'];

        $lines = [];
        if ($main === []) {
            $lines[] = $this->translator->trans('pdf.journey.single', ['%hours%' => Indicator::number((float) $last['hours'])], 'utilities');
        } else {
            /** @var array<string, list<PeriodDay>> $byQuota quota formatted to a fixed precision, to avoid float-as-array-key truncation */
            $byQuota = [];
            foreach ($main as $day) {
                $byQuota[sprintf('%.4f', $day['quota'])][] = $day;
            }
            if (\count($byQuota) === 1) {
                $group   = reset($byQuota);
                $lines[] = $this->translator->trans('pdf.journey.uniform', ['%count%' => \count($group), '%hours%' => Indicator::number((float) $group[0]['quota'])], 'utilities');
            } elseif (\count($byQuota) < \count($main)) {
                foreach ($byQuota as $quota => $group) {
                    $lines[] = $this->translator->trans('pdf.journey.uniform', ['%count%' => \count($group), '%hours%' => Indicator::number((float) $quota)], 'utilities');
                }
            } else {
                foreach ($period->weekdayHours() as $iso => $hours) {
                    if ($hours === null || $hours <= 0.0) {
                        continue;
                    }
                    $lines[] = $this->translator->trans('pdf.journey.weekday', [
                        '%weekday%' => $this->translator->trans('weekday.' . $iso, [], 'calendar'),
                        '%hours%'   => Indicator::number($hours),
                    ], 'utilities');
                }
            }
        }

        if ($lastPartial) {
            $lines[] = $this->translator->trans('pdf.journey.last_different', ['%hours%' => Indicator::number((float) $last['hours'])], 'utilities');
        }

        return new PrintableCalendarJourneyLine($period->getDescription(), $days[0]['date'], $last['date'], $lines);
    }
}
