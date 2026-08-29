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
}
