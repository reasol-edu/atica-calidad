<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;

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
     * @return array{totalDays: int, workingDays: int, totalHours: float, days: list<array{date: \DateTimeImmutable, hours: float, quota: float}>}
     */
    public function workingDaysBetween(AcademicYear $year, \DateTimeImmutable $start, \DateTimeImmutable $end, array $weekdayHours): array
    {
        $result = $this->walker->walk($year, $start, true, $weekdayHours, null, $end);

        return [
            'totalDays'   => $start->diff($end)->days + 1,
            'workingDays' => \count($result['days']),
            'totalHours'  => array_sum(array_column($result['days'], 'hours')),
            'days'        => $result['days'],
        ];
    }

    /**
     * @param array<int, ?float> $weekdayHours
     *
     * @return array{end: \DateTimeImmutable, completed: bool, totalDays: int, workingDays: int, totalHours: float, days: list<array{date: \DateTimeImmutable, hours: float, quota: float}>}
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
        ];
    }

    /**
     * @param array<int, ?float> $weekdayHours
     *
     * @return array{start: \DateTimeImmutable, completed: bool, totalDays: int, workingDays: int, totalHours: float, days: list<array{date: \DateTimeImmutable, hours: float, quota: float}>}
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
        ];
    }
}
