<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Service\NonWorkingDayChecker;
use App\Tests\Integration\RepositoryTestCase;

final class NonWorkingDayCheckerTest extends RepositoryTestCase
{
    private NonWorkingDayChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var NonWorkingDayChecker $checker */
        $checker      = self::getContainer()->get(NonWorkingDayChecker::class);
        $this->checker = $checker;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    public function testIsWeekendDetectsSaturdayAndSunday(): void
    {
        self::assertTrue($this->checker->isWeekend(new \DateTimeImmutable('2025-09-06'))); // Saturday
        self::assertTrue($this->checker->isWeekend(new \DateTimeImmutable('2025-09-07'))); // Sunday
        self::assertFalse($this->checker->isWeekend(new \DateTimeImmutable('2025-09-08'))); // Monday
    }

    public function testIsNonWorkingDayTrueForAWeekendEvenWithoutARegisteredHoliday(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);

        self::assertTrue($this->checker->isNonWorkingDay($year, new \DateTimeImmutable('2025-09-06')));
    }

    public function testIsNonWorkingDayTrueForARegisteredHoliday(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        // 2025-10-13 is a Monday — an ordinary weekday, so this can only be flagged via the
        // registered holiday itself, not by the weekend fallback.
        $holiday = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-10-13'))->setDescription('Fiesta local')->setAcademicYear($year);
        $this->persist($centre, $year, $holiday);

        self::assertTrue($this->checker->isNonWorkingDay($year, new \DateTimeImmutable('2025-10-13')));
        self::assertSame('Fiesta local', $this->checker->descriptionFor($year, new \DateTimeImmutable('2025-10-13')));
    }

    public function testIsNonWorkingDayFalseForAnOrdinarySchoolDay(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);

        self::assertFalse($this->checker->isNonWorkingDay($year, new \DateTimeImmutable('2025-09-08')));
    }

    public function testHolidaysAreScopedToTheirOwnAcademicYear(): void
    {
        $centre = $this->centre();
        $yearA  = (new AcademicYear())->setName('2024-2025')->setEducationalCentre($centre);
        $yearB  = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $holiday = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-10-13'))->setAcademicYear($yearA);
        $this->persist($centre, $yearA, $yearB, $holiday);

        self::assertTrue($this->checker->isNonWorkingDay($yearA, new \DateTimeImmutable('2025-10-13')));
        self::assertFalse($this->checker->isNonWorkingDay($yearB, new \DateTimeImmutable('2025-10-13')));
    }

    public function testCountSchoolDaysExcludesWeekendsAndHolidays(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        // Monday 8th to Friday 12th September 2025 = 5 school days; add a holiday on the 10th.
        $holiday = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-09-10'))->setAcademicYear($year);
        $this->persist($centre, $year, $holiday);

        $count = $this->checker->countSchoolDays($year, new \DateTimeImmutable('2025-09-08'), new \DateTimeImmutable('2025-09-12'));

        self::assertSame(4, $count);
    }

    public function testCountSchoolDaysOverAWeekendOnlyCountsWeekdays(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $this->persist($centre, $year);

        // Friday 5th through Monday 8th September 2025: Fri + Mon = 2 school days (Sat/Sun excluded).
        $count = $this->checker->countSchoolDays($year, new \DateTimeImmutable('2025-09-05'), new \DateTimeImmutable('2025-09-08'));

        self::assertSame(2, $count);
    }

    public function testAddSchoolDaysSkipsWeekendsAndHolidays(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $holiday = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-09-09'))->setAcademicYear($year);
        $this->persist($centre, $year, $holiday);

        // Starting Monday 8th, add 2 school days: 8th itself counts as day 1, the 9th is a
        // holiday (skipped), so day 2 lands on the 10th.
        $result = $this->checker->addSchoolDays($year, new \DateTimeImmutable('2025-09-08'), 2);

        self::assertSame('2025-09-10', $result->format('Y-m-d'));
    }

    public function testDatesForReturnsAllRegisteredHolidayDates(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $first  = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-10-12'))->setAcademicYear($year);
        $second = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2025-12-06'))->setAcademicYear($year);
        $this->persist($centre, $year, $first, $second);

        $dates = $this->checker->datesFor($year);
        sort($dates);

        self::assertSame(['2025-10-12', '2025-12-06'], $dates);
    }
}
