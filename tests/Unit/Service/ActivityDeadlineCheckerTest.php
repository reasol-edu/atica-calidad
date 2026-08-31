<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Service\ActivityDeadlineChecker;
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

    private function checker(): ActivityDeadlineChecker
    {
        return new ActivityDeadlineChecker(Clock::get());
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

    public function testNonWrappingRangeIsNotOverdueBeforeItsNextYearlyOccurrenceStarts(): void
    {
        // Non-wrapping Sep 1–30 range; "now" is the following August, before this year's Sep 1 —
        // the recurring cycle hasn't started yet this calendar year, so it points forward to it
        // rather than being stuck "overdue" relative to a year-old occurrence.
        self::mockTime('2026-08-15 10:00:00');
        $activity = $this->activity(1, 9, 30, 9);

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
        self::mockTime('2025-08-31 23:59:59');
        $activity = $this->activity(1, 9, 30, 9);

        self::assertFalse($this->checker()->hasStarted($activity));
    }

    public function testNonWrappingRangeHasStartedOnItsStartDate(): void
    {
        self::mockTime('2025-09-01 00:00:00');
        $activity = $this->activity(1, 9, 30, 9);

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
