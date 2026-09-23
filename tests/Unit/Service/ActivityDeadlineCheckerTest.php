<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Service\ActivityDeadlineChecker;
use App\Service\AppSettingsInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ActivityDeadlineCheckerTest extends TestCase
{
    use ClockSensitiveTrait;

    private function activity(int $startDay, int $startMonth, int $endDay, int $endMonth): Activity
    {
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $category = (new ActivityCategory())->setName('Categoría')->setEducationalCentre($centre);

        return (new Activity())->setCategory($category)->setTitle('Actividad')->setStart($startDay, $startMonth)->setEnd($endDay, $endMonth);
    }

    /** @param string|null $academicYearStart the centre's academic_year.start_date ("MM-DD"); null = setting not defined */
    private function checker(?string $academicYearStart = null): ActivityDeadlineChecker
    {
        $settings = $this->createStub(AppSettingsInterface::class);
        $settings->method('getForCentre')->willReturnCallback(
            static fn (string $key): ?string => $key === ActivityDeadlineChecker::START_DATE_SETTING ? $academicYearStart : null,
        );

        return new ActivityDeadlineChecker(Clock::get(), $settings);
    }

    public function testNonWrappingRangeIsNotOverdueBeforeItsEnd(): void
    {
        self::mockTime('2025-09-15 10:00:00');
        $activity = $this->activity(1, 9, 30, 9);

        self::assertFalse($this->checker()->isOverdue($activity));
    }

    public function testNonWrappingRangeIsOverdueAfterItsEnd(): void
    {
        self::mockTime('2025-10-01 00:00:01');
        $activity = $this->activity(1, 9, 30, 9);

        self::assertTrue($this->checker()->isOverdue($activity));
    }

    public function testNonWrappingRangeIsNotOverdueExactlyAtItsEndOfDay(): void
    {
        self::mockTime('2025-09-30 23:59:59');
        $activity = $this->activity(1, 9, 30, 9);

        self::assertFalse($this->checker()->isOverdue($activity));
    }

    public function testWrappingRangeEarlyInCycleEndsNextCalendarYear(): void
    {
        // Sep–Jun range; "now" is October, still in the "start" stretch — deadline is next June.
        self::mockTime('2025-10-15 10:00:00');
        $activity = $this->activity(1, 9, 30, 6);

        self::assertSame('2026-06-30', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertFalse($this->checker()->isOverdue($activity));
    }

    public function testWrappingRangeLateInCycleEndsThisCalendarYear(): void
    {
        // Sep–Jun range; "now" is March, in the "end" stretch — deadline is this June.
        self::mockTime('2026-03-01 10:00:00');
        $activity = $this->activity(1, 9, 30, 6);

        self::assertSame('2026-06-30', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertFalse($this->checker()->isOverdue($activity));
    }

    public function testWrappingRangeIsOverdueJustAfterItsEnd(): void
    {
        self::mockTime('2026-07-01 00:00:01');
        $activity = $this->activity(1, 9, 30, 6);

        self::assertTrue($this->checker()->isOverdue($activity));
    }

    public function testNonWrappingRangeStillRefersToTheFinishingAcademicYearInAugust(): void
    {
        // Non-wrapping Oct 1–31 range; "now" is the following August, still inside academic year
        // 2025-2026 with the default Sep 15 start — it's that year's (already past) occurrence.
        self::mockTime('2026-08-15 10:00:00');
        $activity = $this->activity(1, 10, 31, 10);

        self::assertSame('2025-10-31', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertTrue($this->checker()->isOverdue($activity));
    }

    public function testNonWrappingRangePointsToTheNextOccurrenceOnceTheConfiguredAcademicYearStarts(): void
    {
        // Same as above, but with the academic year configured to start on Aug 1: by Aug 15 the
        // new academic year has begun, so it points forward to this September's occurrence.
        self::mockTime('2026-08-15 10:00:00');
        $activity = $this->activity(1, 9, 30, 9);
        $checker  = $this->checker('08-01');

        self::assertSame('2026-09-01', $checker->currentCycleStartDate($activity)->format('Y-m-d'));
        self::assertSame('2026-09-30', $checker->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertFalse($checker->hasStarted($activity));
        self::assertFalse($checker->isOverdue($activity));
    }

    /**
     * The bug this anchoring fixes: a range inside the second half of the academic year (Jan–Feb)
     * used to resolve, from September to December, to the one that closed last February — showing
     * as overdue (and triggering overdue reminders) months before this year's even opened.
     */
    public function testRangeLaterInTheAcademicYearIsNotStartedDuringTheAutumn(): void
    {
        self::mockTime('2026-09-23 10:00:00');
        $activity = $this->activity(10, 1, 28, 2);

        self::assertSame('2027-01-10', $this->checker()->currentCycleStartDate($activity)->format('Y-m-d'));
        self::assertSame('2027-02-28', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertFalse($this->checker()->hasStarted($activity));
        self::assertFalse($this->checker()->isOverdue($activity));
    }

    public function testRangeLaterInTheAcademicYearIsOverdueOnceItsEndHasPassed(): void
    {
        self::mockTime('2027-03-05 10:00:00');
        $activity = $this->activity(10, 1, 28, 2);

        self::assertSame('2027-02-28', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertTrue($this->checker()->isOverdue($activity));
    }

    public function testAcademicYearStartBoundaryIsInclusiveOfItsOwnDay(): void
    {
        // Academic year configured to start on Sep 15: Sep 14 still belongs to the previous one.
        $activity = $this->activity(10, 1, 28, 2);

        self::mockTime('2026-09-14 23:59:59');
        self::assertSame('2026-02-28', $this->checker('09-15')->currentCycleEndDate($activity)->format('Y-m-d'));

        self::mockTime('2026-09-15 00:00:00');
        self::assertSame('2027-02-28', $this->checker('09-15')->currentCycleEndDate($activity)->format('Y-m-d'));
    }

    public function testInvalidAcademicYearStartFallsBackToTheSeptemberFifteenthDefault(): void
    {
        // On Sep 10 a Sep 15 start still means academic year 2025-2026 (a Sep 1 one would not).
        self::mockTime('2026-09-10 10:00:00');
        $activity = $this->activity(1, 10, 31, 10);

        self::assertSame('2025-10-31', $this->checker('02-30')->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertSame('2025-10-31', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertSame('2026-10-31', $this->checker('09-01')->currentCycleEndDate($activity)->format('Y-m-d'));
    }

    /**
     * With the default Sep 15 start, Sep 1–14 is the tail of the academic year that is ending: a
     * range entirely inside it (Sep 1–10) is open during those days, but from Sep 15 on it points to
     * next September's occurrence (not started) rather than showing as overdue.
     */
    public function testRangeEntirelyBeforeTheAcademicYearStartBelongsToTheEndingAcademicYear(): void
    {
        $activity = $this->activity(1, 9, 10, 9);

        self::mockTime('2025-09-05 10:00:00');
        self::assertSame('2025-09-10', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertTrue($this->checker()->hasStarted($activity));
        self::assertFalse($this->checker()->isOverdue($activity));

        self::mockTime('2025-10-05 10:00:00');
        self::assertSame('2026-09-01', $this->checker()->currentCycleStartDate($activity)->format('Y-m-d'));
        self::assertFalse($this->checker()->hasStarted($activity));
        self::assertFalse($this->checker()->isOverdue($activity));
    }

    public function testRangeStraddlingTheAcademicYearStartKeepsTheCalendarYearAnchoring(): void
    {
        // Jul 15 – Sep 15 crosses the default Sep 15 start: no single academic year contains it.
        $activity = $this->activity(15, 7, 15, 9);

        self::mockTime('2026-08-01 10:00:00');
        self::assertSame('2026-07-15', $this->checker()->currentCycleStartDate($activity)->format('Y-m-d'));
        self::assertSame('2026-09-15', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertFalse($this->checker()->isOverdue($activity));

        self::mockTime('2026-09-10 10:00:00');
        self::assertSame('2026-09-15', $this->checker()->currentCycleEndDate($activity)->format('Y-m-d'));
        self::assertFalse($this->checker()->isOverdue($activity));
    }

    /**
     * cycleEndDateNear() must be driven entirely by the given $reference, not by "now" — the
     * calendar calls it with the browsed month's date, which can be far from the real clock.
     */
    public function testCycleEndDateNearIsDrivenByTheReferenceNotByNow(): void
    {
        self::mockTime('2020-01-01 00:00:00');
        $activity = $this->activity(1, 9, 30, 6);

        $reference = new \DateTimeImmutable('2025-10-15');

        self::assertSame('2026-06-30', $this->checker()->cycleEndDateNear($activity, $reference)->format('Y-m-d'));
    }

    public function testCycleEndDateNearForAWrappingRangeInTheLateStretchOfTheCycle(): void
    {
        self::mockTime('2020-01-01 00:00:00');
        $activity = $this->activity(1, 9, 30, 6);

        $reference = new \DateTimeImmutable('2026-03-01');

        self::assertSame('2026-06-30', $this->checker()->cycleEndDateNear($activity, $reference)->format('Y-m-d'));
    }

    public function testCycleEndDateNearForANonWrappingRangeUsesTheReferencesOwnYear(): void
    {
        self::mockTime('2020-01-01 00:00:00');
        $activity = $this->activity(1, 9, 30, 9);

        $reference = new \DateTimeImmutable('2030-09-10');

        self::assertSame('2030-09-30', $this->checker()->cycleEndDateNear($activity, $reference)->format('Y-m-d'));
    }

    // ── hasStarted() / currentCycleStartDate() ──────────────────────────────

    public function testNonWrappingRangeHasNotStartedBeforeItsStartDate(): void
    {
        self::mockTime('2025-09-30 23:59:59');
        $activity = $this->activity(1, 10, 31, 10);

        self::assertFalse($this->checker()->hasStarted($activity));
    }

    public function testNonWrappingRangeHasStartedOnItsStartDate(): void
    {
        self::mockTime('2025-10-01 00:00:00');
        $activity = $this->activity(1, 10, 31, 10);

        self::assertTrue($this->checker()->hasStarted($activity));
    }

    public function testWrappingRangeInTheStartStretchStartedThisCalendarYear(): void
    {
        // Sep–Jun range; "now" is October, in the "start" stretch — it started this September.
        self::mockTime('2025-10-15 10:00:00');
        $activity = $this->activity(1, 9, 30, 6);

        self::assertSame('2025-09-01', $this->checker()->currentCycleStartDate($activity)->format('Y-m-d'));
        self::assertTrue($this->checker()->hasStarted($activity));
    }

    public function testWrappingRangeInTheEndStretchStartedThePreviousCalendarYear(): void
    {
        // Sep–Jun range; "now" is March, in the "end" stretch — it started last September.
        self::mockTime('2026-03-01 10:00:00');
        $activity = $this->activity(1, 9, 30, 6);

        self::assertSame('2025-09-01', $this->checker()->currentCycleStartDate($activity)->format('Y-m-d'));
        self::assertTrue($this->checker()->hasStarted($activity));
    }

    /**
     * Aug 31 sits outside both stretches of a Sep–Jun range: same as isOverdue()'s own anchoring
     * (see testNonWrappingRangeIsNotOverdueBeforeItsNextYearlyOccurrenceStarts()), the checker
     * still refers to the just-finished cycle (Sep last year–Jun this year) here, not the not-yet-
     * open next one — so hasStarted() is (correctly, consistently) true, paired with isOverdue().
     */
    public function testWrappingRangeInTheGapBetweenCyclesRefersToTheJustFinishedCycle(): void
    {
        self::mockTime('2025-08-31 10:00:00');
        $activity = $this->activity(1, 9, 30, 6);

        self::assertTrue($this->checker()->hasStarted($activity));
        self::assertTrue($this->checker()->isOverdue($activity));
    }

    // ── daysUntilDeadline() ──────────────────────────────────────────────────

    public function testDaysUntilDeadlineCountsWholeDaysToTheEndOfTheEndDate(): void
    {
        self::mockTime('2025-09-25 10:00:00');
        $activity = $this->activity(1, 9, 30, 9);

        // 2025-09-30 23:59:59 minus 2025-09-25 10:00:00 is 5 days, 13h59m59s — 5 whole days.
        self::assertSame(5, $this->checker()->daysUntilDeadline($activity));
    }

    public function testDaysUntilDeadlineIsZeroOnTheLastDay(): void
    {
        self::mockTime('2025-09-30 08:00:00');
        $activity = $this->activity(1, 9, 30, 9);

        self::assertSame(0, $this->checker()->daysUntilDeadline($activity));
    }
}
