<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Indicator;
use App\Model\DateCalculatorDay;
use App\Model\DateCalculatorMonth;
use App\Model\DateCalculatorWeek;

/**
 * Utilidades › Calculadora de fechas: the three calculations a teacher needs when planning a
 * period against a Monday-to-Friday hours pattern — how many working days/hours fall between two
 * given dates, or which date closes a target number of hours starting from (or ending at) a known
 * one. Built on WeekdayHoursWalker, the same day-by-day algorithm the calendar generator
 * (PrintableCalendarPdfBuilder) uses for its own periods, so both tools agree on what counts as a
 * working day for a given academic year.
 */
final class DateCalculatorService
{
    public function __construct(
        private readonly WeekdayHoursWalker $walker,
    ) {}

    /**
     * @param array<int, ?float> $weekdayHours
     *
     * @return array{totalDays: int, workingDays: int, totalHours: float, days: list<array{date: \DateTimeImmutable, hours: float, quota: float}>, months: list<DateCalculatorMonth>}
     */
    public function workingDaysBetween(AcademicYear $year, \DateTimeImmutable $start, \DateTimeImmutable $end, array $weekdayHours): array
    {
        $result = $this->walker->walk($year, $start, true, $weekdayHours, null, $end);

        return [
            'totalDays'   => $start->diff($end)->days + 1,
            'workingDays' => \count($result['days']),
            'totalHours'  => array_sum(array_column($result['days'], 'hours')),
            'days'        => $result['days'],
            'months'      => $this->buildMonths($start, $end, $result['days']),
        ];
    }

    /**
     * @param array<int, ?float> $weekdayHours
     *
     * @return array{end: \DateTimeImmutable, completed: bool, totalDays: int, workingDays: int, totalHours: float, days: list<array{date: \DateTimeImmutable, hours: float, quota: float}>, months: list<DateCalculatorMonth>}
     */
    public function endDateForHours(AcademicYear $year, \DateTimeImmutable $start, float $totalHours, array $weekdayHours): array
    {
        $result = $this->walker->walk($year, $start, true, $weekdayHours, $totalHours);

        return [
            'end'         => $result['lastDate'],
            'completed'   => $result['completed'],
            'totalDays'   => $start->diff($result['lastDate'])->days + 1,
            'workingDays' => \count($result['days']),
            'totalHours'  => array_sum(array_column($result['days'], 'hours')),
            'days'        => $result['days'],
            'months'      => $this->buildMonths($start, $result['lastDate'], $result['days']),
        ];
    }

    /**
     * @param array<int, ?float> $weekdayHours
     *
     * @return array{start: \DateTimeImmutable, completed: bool, totalDays: int, workingDays: int, totalHours: float, days: list<array{date: \DateTimeImmutable, hours: float, quota: float}>, months: list<DateCalculatorMonth>}
     */
    public function startDateForHours(AcademicYear $year, \DateTimeImmutable $end, float $totalHours, array $weekdayHours): array
    {
        $result = $this->walker->walk($year, $end, false, $weekdayHours, $totalHours);

        return [
            'start'       => $result['lastDate'],
            'completed'   => $result['completed'],
            'totalDays'   => $result['lastDate']->diff($end)->days + 1,
            'workingDays' => \count($result['days']),
            'totalHours'  => array_sum(array_column($result['days'], 'hours')),
            'days'        => $result['days'],
            'months'      => $this->buildMonths($result['lastDate'], $end, $result['days']),
        ];
    }

    /**
     * The monthly calendar grid shown below the result and fed to the Excel export — every day in
     * [$start, $end], not just the ones with hours (WeekdayHoursWalker only returns the latter), so
     * the grid reads like an actual calendar. Built the same way PrintableCalendarPdfBuilder builds
     * a month's day grid, minus the per-day highlight colours and side annotations this tool has no
     * use for, plus the weekly/monthly subtotals it does need.
     *
     * @param list<array{date: \DateTimeImmutable, hours: float, quota: float}> $days
     *
     * @return list<DateCalculatorMonth>
     */
    private function buildMonths(\DateTimeImmutable $start, \DateTimeImmutable $end, array $days): array
    {
        /** @var array<string, float> $hoursByDate ISO date (Y-m-d) => hours */
        $hoursByDate = [];
        foreach ($days as $day) {
            $hoursByDate[$day['date']->format('Y-m-d')] = $day['hours'];
        }

        $months    = [];
        $cursor    = $start->modify('first day of this month');
        $lastMonth = $end->modify('first day of this month');
        while ($cursor <= $lastMonth) {
            $months[] = $this->buildMonth($cursor, $hoursByDate);
            $cursor   = $cursor->modify('first day of next month');
        }

        return $months;
    }

    /** @param array<string, float> $hoursByDate */
    private function buildMonth(\DateTimeImmutable $monthStart, array $hoursByDate): DateCalculatorMonth
    {
        $monthEnd    = $monthStart->modify('last day of this month');
        $weeks       = [];
        $monthHours  = 0.0;
        $workingDays = 0;

        $firstWeekday = (int) $monthStart->format('N');
        $cursor       = $monthStart->modify('-' . ($firstWeekday - 1) . ' days');
        do {
            $weekDays  = [];
            $weekHours = 0.0;
            for ($i = 0; $i < 7; ++$i) {
                if ((int) $cursor->format('n') !== (int) $monthStart->format('n')) {
                    $weekDays[] = null;
                } else {
                    $hours      = $hoursByDate[$cursor->format('Y-m-d')] ?? 0.0;
                    $weekDays[] = new DateCalculatorDay($cursor, $hours, $hours > 0.0 ? Indicator::number($hours) : null);
                    $weekHours += $hours;
                    if ($hours > 0.0) {
                        ++$workingDays;
                    }
                }
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[]     = new DateCalculatorWeek($weekDays, $weekHours, Indicator::number($weekHours));
            $monthHours += $weekHours;
        } while ($cursor <= $monthEnd);

        return new DateCalculatorMonth(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('n'),
            $weeks,
            $monthHours,
            Indicator::number($monthHours),
            $workingDays,
        );
    }
}
