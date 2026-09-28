<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Service\DateCalculatorService;
use App\Tests\Integration\RepositoryTestCase;

/**
 * Utilidades › Calculadora de fechas. DateCalculatorService is built on WeekdayHoursWalker, the
 * same day-walking algorithm PrintableCalendarPdfBuilderTest already exercises through the calendar
 * generator's periods — these tests cover the calculator's own three entry points and result shape,
 * not the walking rules themselves again.
 */
final class DateCalculatorServiceTest extends RepositoryTestCase
{
    private DateCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DateCalculatorService $calculator */
        $calculator       = self::getContainer()->get(DateCalculatorService::class);
        $this->calculator = $calculator;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function year(EducationalCentre $centre): AcademicYear
    {
        return (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
    }

    /** @return array<int, ?float> Monday-Friday at $hours each */
    private function uniformWeek(float $hours): array
    {
        return [1 => $hours, 2 => $hours, 3 => $hours, 4 => $hours, 5 => $hours];
    }

    public function testWorkingDaysBetweenTwoDatesCountsOnlySchoolDaysWithAssignedHours(): void
    {
        $centre = $this->centre();
        $year   = $this->year($centre);
        $this->persist($centre, $year);

        // Monday 2026-03-02 to Friday 2026-03-06: five school days at 6h each.
        $result = $this->calculator->workingDaysBetween(
            $year,
            new \DateTimeImmutable('2026-03-02'),
            new \DateTimeImmutable('2026-03-06'),
            $this->uniformWeek(6.0),
        );

        self::assertSame(5, $result['totalDays']);
        self::assertSame(5, $result['workingDays']);
        self::assertSame(30.0, $result['totalHours']);
    }

    public function testWorkingDaysBetweenTwoDatesSkipsWeekendsAndDeclaredHolidays(): void
    {
        $centre  = $this->centre();
        $year    = $this->year($centre);
        $holiday = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-04'))->setDescription('Puente')->setAcademicYear($year);
        $this->persist($centre, $year, $holiday);

        // Monday 2026-03-02 to Sunday 2026-03-08: only Mon, Tue, Thu, Fri have hours (Wed is the
        // declared holiday, Sat/Sun are the weekend), all at 4h.
        $result = $this->calculator->workingDaysBetween(
            $year,
            new \DateTimeImmutable('2026-03-02'),
            new \DateTimeImmutable('2026-03-08'),
            $this->uniformWeek(4.0),
        );

        self::assertSame(7, $result['totalDays']);
        self::assertSame(4, $result['workingDays']);
        self::assertSame(16.0, $result['totalHours']);
    }

    public function testWorkingDaysBetweenBuildsAMonthlyGridWithWeeklyAndMonthlyTotals(): void
    {
        $centre = $this->centre();
        $year   = $this->year($centre);
        $this->persist($centre, $year);

        // Monday 2026-03-02 to Friday 2026-03-06: one week, fully inside March 2026.
        $result = $this->calculator->workingDaysBetween(
            $year,
            new \DateTimeImmutable('2026-03-02'),
            new \DateTimeImmutable('2026-03-06'),
            $this->uniformWeek(6.0),
        );

        self::assertCount(1, $result['months']);
        $month = $result['months'][0];
        self::assertSame(2026, $month->year);
        self::assertSame(3, $month->month);
        self::assertSame(30.0, $month->totalHours);
        self::assertSame(5, $month->workingDays);

        // The week containing 2026-03-02..08 is the only one with any hours.
        $week = current(array_filter($month->weeks, static fn ($w) => $w->totalHours > 0.0));
        self::assertNotFalse($week);
        self::assertSame(30.0, $week->totalHours);
        self::assertSame('6', $week->days[0]->hoursLabel); // Monday
        self::assertSame(6.0, $week->days[0]->hours);
        self::assertNull($week->days[5]->hoursLabel); // Saturday: no hours assigned
        self::assertSame(0.0, $week->days[5]->hours);
    }

    public function testEndDateForHoursWalksForwardFromTheStartDateUntilTheTargetIsReached(): void
    {
        $centre = $this->centre();
        $year   = $this->year($centre);
        $this->persist($centre, $year);

        // Monday 2026-03-02, 8h/day Mon-Fri, 20h total → Mon (8h) + Tue (8h) + Wed (4h of 8h) = 20h.
        $result = $this->calculator->endDateForHours($year, new \DateTimeImmutable('2026-03-02'), 20.0, $this->uniformWeek(8.0));

        self::assertTrue($result['completed']);
        self::assertSame('2026-03-04', $result['end']->format('Y-m-d'));
        self::assertSame(3, $result['workingDays']);
        self::assertSame(20.0, $result['totalHours']);
        self::assertSame(3, $result['totalDays']);
    }

    public function testStartDateForHoursWalksBackwardFromTheEndDateUntilTheTargetIsReached(): void
    {
        $centre = $this->centre();
        $year   = $this->year($centre);
        $this->persist($centre, $year);

        // Friday 2026-03-06 back to 20h at 8h/day Mon-Fri → Fri (8h) + Thu (8h) + Wed (4h of 8h) = 20h,
        // so the period starts Wednesday 2026-03-04.
        $result = $this->calculator->startDateForHours($year, new \DateTimeImmutable('2026-03-06'), 20.0, $this->uniformWeek(8.0));

        self::assertTrue($result['completed']);
        self::assertSame('2026-03-04', $result['start']->format('Y-m-d'));
        self::assertSame(3, $result['workingDays']);
        self::assertSame(20.0, $result['totalHours']);
        self::assertSame(3, $result['totalDays']);
    }
}
