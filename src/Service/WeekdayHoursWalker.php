<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;

/**
 * Walks calendar days one by one, skipping an academic year's non-working days
 * (NonWorkingDayChecker::isNonWorkingDay — weekends and declared holidays), applying a
 * Monday-to-Friday hours pattern to whatever's left. Originally the printable calendar generator's
 * own PrintableCalendarPdfBuilder::hourDays() algorithm (Utilidades › Generador de calendarios);
 * extracted here so App\Service\DateCalculatorService (Utilidades › Calculadora de fechas) walks
 * days exactly the same way, rather than a second, drifting implementation of the same rules.
 */
final class WeekdayHoursWalker
{
    /** A day per iteration, capped generously (~10 years) so a misconfigured pattern (every weekday
     *  quota left at zero) or an unreachable target can never loop forever. */
    private const int MAX_DAYS = 3660;

    public function __construct(
        private readonly NonWorkingDayChecker $nonWorkingDays,
    ) {}

    /**
     * @param array<int, ?float> $weekdayHours hours for ISO weekday 1 (Monday) .. 5 (Friday)
     *
     * @return array{days: list<array{date: \DateTimeImmutable, hours: float, quota: float}>, completed: bool, lastDate: \DateTimeImmutable}
     */
    public function walk(
        AcademicYear $year,
        \DateTimeImmutable $start,
        bool $forward,
        array $weekdayHours,
        ?float $targetHours,
        ?\DateTimeImmutable $limit = null,
    ): array {
        $remaining = $targetHours;
        $cursor    = $start;
        $days      = [];
        $completed = false;
        $lastDate  = $start;

        for ($i = 0; $i < self::MAX_DAYS; ++$i) {
            if ($limit !== null && ($forward ? $cursor > $limit : $cursor < $limit)) {
                break;
            }
            $lastDate = $cursor;

            if (!$this->nonWorkingDays->isNonWorkingDay($year, $cursor)) {
                $quota = $weekdayHours[(int) $cursor->format('N')] ?? null;
                if ($quota !== null && $quota > 0.0) {
                    $take   = $remaining === null ? $quota : min($quota, $remaining);
                    $days[] = ['date' => $cursor, 'hours' => $take, 'quota' => $quota];
                    if ($remaining !== null) {
                        $remaining -= $take;
                    }
                }
            }

            if ($remaining !== null && $remaining <= 0.0001) {
                $completed = true;
                break;
            }

            $cursor = $cursor->modify($forward ? '+1 day' : '-1 day');
        }

        if (!$forward) {
            $days = array_reverse($days);
        }

        return ['days' => $days, 'completed' => $completed, 'lastDate' => $lastDate];
    }
}
