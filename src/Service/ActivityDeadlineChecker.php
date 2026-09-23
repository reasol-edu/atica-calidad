<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Model\DayMonth;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Computes whether an Activity's day/month deadline (no year — it repeats every academic year)
 * has already passed for the current cycle, anchoring it to a real calendar date. AcademicYear
 * only stores a name like "2025-2026", not real dates, so the cycle boundary comes instead from
 * the centre's "start of the academic year" setting (academic_year.start_date, Sep 15 by
 * default).
 *
 * A cycle is the academic year the reference date falls in, not its calendar year: on
 * 2026-10-01 a Jan 10 – Feb 28 activity is the one opening on 2027-01-10 (this academic year's),
 * not the one that closed on 2026-02-28 (last academic year's). Only a range straddling the
 * start of the academic year itself (e.g. Sep 1 – Sep 30 with a Sep 15 start) can't fit inside
 * one academic year; those keep being anchored to the reference's calendar year instead. A range
 * entirely before the start day belongs to the academic year that is ending, even within the same
 * month: with a Sep 15 start, Sep 1–10 is the tail of the previous academic year.
 *
 * Each occurrence is identified by its cycle key: the first calendar year of the academic year it
 * belongs to (2026 for 2026-2027). Completions and submissions are stored against that key, so
 * last year's don't count for this year's occurrence of the same activity.
 */
final class ActivityDeadlineChecker implements ResetInterface
{
    public const string START_DATE_SETTING = 'academic_year.start_date';

    /** Used when the setting is missing (e.g. not migrated yet) or somehow holds an invalid value. */
    private const string DEFAULT_START_DATE = '09-15';

    /** @var array<int, DayMonth> the date the academic year starts on, by centre object id (cleared on reset()) */
    private array $academicYearStarts = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly AppSettingsInterface $settings,
    ) {}

    public function reset(): void
    {
        $this->academicYearStarts = [];
    }

    /** The real calendar date the activity's deadline falls on for the cycle "now" belongs to. */
    public function currentCycleEndDate(Activity $activity): \DateTimeImmutable
    {
        return $this->cycleEndDateNear($activity, $this->clock->now());
    }

    /**
     * The real calendar date the activity's deadline falls on for the cycle $reference belongs
     * to — same anchoring as currentCycleEndDate(), just anchored to an arbitrary date instead of
     * "now". Used by the calendar, which can be browsed to any month.
     */
    public function cycleEndDateNear(Activity $activity, \DateTimeImmutable $reference): \DateTimeImmutable
    {
        return $this->cycleNear($activity, $reference)[1];
    }

    public function isOverdue(Activity $activity): bool
    {
        return $this->clock->now() > $this->currentCycleEndDate($activity);
    }

    /** The real calendar date the activity's period opens on for the cycle "now" belongs to. */
    public function currentCycleStartDate(Activity $activity): \DateTimeImmutable
    {
        return $this->cycleStartDateNear($activity, $this->clock->now());
    }

    /**
     * The real calendar date the activity's period opens on for the cycle $reference belongs to —
     * same anchoring as cycleEndDateNear(), mirrored for the start side.
     */
    public function cycleStartDateNear(Activity $activity, \DateTimeImmutable $reference): \DateTimeImmutable
    {
        return $this->cycleNear($activity, $reference)[0];
    }

    /** Whether the activity's current cycle has already opened — false while still waiting for its yearly start date. */
    public function hasStarted(Activity $activity): bool
    {
        return $this->clock->now() >= $this->currentCycleStartDate($activity);
    }

    /** Whole days remaining until the current cycle's deadline. Meaningful only when not overdue — see isOverdue(). */
    public function daysUntilDeadline(Activity $activity): int
    {
        return (int) $this->clock->now()->diff($this->currentCycleEndDate($activity))->days;
    }

    /** The academic year a cycle key stands for, as shown to users: 2026 → "2026-2027". */
    public static function academicYearLabel(int $cycleYear): string
    {
        return \sprintf('%d-%d', $cycleYear, $cycleYear + 1);
    }

    /** Cycle key (first calendar year of its academic year, e.g. 2026 for 2026-2027) of the occurrence "now" belongs to. */
    public function currentCycleKey(Activity $activity): int
    {
        return $this->cycleKeyNear($activity, $this->clock->now());
    }

    /** Cycle key of the occurrence $reference belongs to — see currentCycleKey(). */
    public function cycleKeyNear(Activity $activity, \DateTimeImmutable $reference): int
    {
        return $this->cycleNear($activity, $reference)[2];
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int} the [start, end, cycle key] of the cycle $reference belongs to. */
    private function cycleNear(Activity $activity, \DateTimeImmutable $reference): array
    {
        $academicYearStart = $this->academicYearStart($activity->getCategory()->getEducationalCentre());
        $boundary          = $academicYearStart->month * 100 + $academicYearStart->day;

        // First calendar year of the academic year $reference falls in (2026 for 2026-2027).
        $referenceYear = (int) $reference->format('Y');
        $academicYear  = (int) $reference->format('nd') < $boundary ? $referenceYear - 1 : $referenceYear;

        $start = $this->startOf($activity, $this->calendarYearWithin($academicYear, $boundary, $activity->getStartMonth(), $activity->getStartDay()));
        $end   = $this->endOf($activity, $this->calendarYearWithin($academicYear, $boundary, $activity->getEndMonth(), $activity->getEndDay()));

        if ($start <= $end) {
            return [$start, $end, $academicYear];
        }

        [$start, $end] = $this->calendarYearCycleNear($activity, $reference);

        // A straddling occurrence is keyed by the academic year its end falls in: a whole-year
        // Sep 1 – Jun 30 activity with a Sep 15 start is 2026-2027's when it runs Sep 2026 – Jun
        // 2027, even though its first two weeks come before that academic year starts.
        $endYear = (int) $end->format('Y');

        return [$start, $end, (int) $end->format('nd') < $boundary ? $endYear - 1 : $endYear];
    }

    /** Calendar year that $month/$day falls in within the academic year starting on $boundary (month * 100 + day) of $academicYear. */
    private function calendarYearWithin(int $academicYear, int $boundary, int $month, int $day): int
    {
        return $month * 100 + $day >= $boundary ? $academicYear : $academicYear + 1;
    }

    /**
     * Fallback for a range straddling the start of the academic year (e.g. Sep 1 – Sep 30 with a
     * Sep 15 start), which no single academic year contains: anchored to $reference's calendar
     * year, moving the end forward (or the start back) a year only for a range that also wraps
     * the calendar year.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function calendarYearCycleNear(Activity $activity, \DateTimeImmutable $reference): array
    {
        $year  = (int) $reference->format('Y');
        $start = $this->startOf($activity, $year);
        $end   = $this->endOf($activity, $year);

        if ($activity->getStartMonth() > $activity->getEndMonth()) {
            if ((int) $reference->format('n') >= $activity->getStartMonth()) {
                $end = $end->modify('+1 year');
            } else {
                $start = $start->modify('-1 year');
            }
        }

        return [$start, $end];
    }

    private function academicYearStart(EducationalCentre $centre): DayMonth
    {
        $centreKey = spl_object_id($centre);

        return $this->academicYearStarts[$centreKey] ??= DayMonth::tryParse($this->settings->getForCentre(self::START_DATE_SETTING, $centre))
            ?? DayMonth::tryParse(self::DEFAULT_START_DATE)
            ?? throw new \LogicException('Invalid default academic year start date.');
    }

    private function startOf(Activity $activity, int $year): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d 00:00:00', $year, $activity->getStartMonth(), $activity->getStartDay()));
    }

    private function endOf(Activity $activity, int $year): \DateTimeImmutable
    {
        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d 23:59:59', $year, $activity->getEndMonth(), $activity->getEndDay()));
    }
}
