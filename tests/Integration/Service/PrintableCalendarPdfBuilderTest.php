<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\PrintableCalendar;
use App\Entity\PrintableCalendarPeriodMode;
use App\Entity\Teacher;
use App\Model\PrintableCalendarMonth;
use App\Service\PrintableCalendarPdfBuilder;
use App\Tests\Integration\RepositoryTestCase;

final class PrintableCalendarPdfBuilderTest extends RepositoryTestCase
{
    private PrintableCalendarPdfBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var PrintableCalendarPdfBuilder $builder */
        $builder       = self::getContainer()->get(PrintableCalendarPdfBuilder::class);
        $this->builder = $builder;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function year(EducationalCentre $centre): AcademicYear
    {
        return (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function calendar(EducationalCentre $centre, AcademicYear $year, Teacher $owner): PrintableCalendar
    {
        return new PrintableCalendar($centre, $year, $owner, 'FCT 2º CFGS');
    }

    /** @return list<string> */
    private function monthAnnotationLabels(iterable $months, int $month): array
    {
        foreach ($months as $m) {
            \assert($m instanceof PrintableCalendarMonth);
            if ($m->month === $month) {
                return array_map(static fn ($a): string => $a->label, $m->annotations);
            }
        }

        return [];
    }

    public function testUniformHoursPeriodComputesTheJourneySummaryWithADifferentLastDay(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Monday 2026-03-02, 8h/day Mon-Fri, 20h total → two full 8h days (Mon, Tue) then a
        // partial 4h day (Wed) — same "N jornadas de H horas" + "y 1 de H2 horas" split as the
        // FCT example that motivated this feature (47 @ 8h + 1 @ 4h).
        $calendar->addPeriod('FCT', '#c7d2fe', true, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-02'), null, 20.0, 8.0, 8.0, 8.0, 8.0, 8.0);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertCount(1, $data->journeySummaries);
        self::assertSame(['2 jornadas de 8 horas', 'y 1 jornada de 4 horas'], $data->journeySummaries[0]->hoursLines);
        self::assertEquals(new \DateTimeImmutable('2026-03-02'), $data->journeySummaries[0]->startDate);
        self::assertEquals(new \DateTimeImmutable('2026-03-04'), $data->journeySummaries[0]->endDate);
    }

    public function testADeclaredNonWorkingDayIsSkippedEvenIfItsWeekdayHasHoursAssigned(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Monday 2026-03-02 is declared non-working: the period must skip straight to the next
        // Monday (2026-03-09), the only weekday with hours assigned.
        $holiday = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-02'))->setDescription('Día no lectivo')->setAcademicYear($year);
        $calendar->addPeriod('FCT', '#c7d2fe', false, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-03-02'), null, 8.0, 8.0, null, null, null, null);
        $this->persist($centre, $year, $teacher, $holiday, $calendar);

        $data = $this->builder->build($calendar);

        self::assertEquals(new \DateTimeImmutable('2026-03-09'), $data->effectiveStart);
        self::assertEquals(new \DateTimeImmutable('2026-03-09'), $data->effectiveEnd);
    }

    public function testEveryMonthBoxHasSixWeekRowsEvenWhenTheMonthNeedsFewer(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // February 2026 starts on a Sunday and has 28 days: only 5 calendar weeks — every month
        // box must still render 6, so side-by-side (and stacked) months line up at the same height.
        $calendar->addDate(new \DateTimeImmutable('2026-02-10'), '#a7f3d0', 'Cualquier cosa');
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertCount(1, $data->months);
        self::assertCount(6, $data->months[0]->weeks);
    }

    public function testTheEffectiveRangeIsComputedFromPeriodsAndDatesWhenNotSetExplicitly(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        $calendar->addPeriod('Sesiones', '#fca5a5', false, PrintableCalendarPeriodMode::DateRange, new \DateTimeImmutable('2026-02-10'), new \DateTimeImmutable('2026-02-12'), null, null, null, null, null, null);
        $calendar->addDate(new \DateTimeImmutable('2026-05-20'), '#a7f3d0', 'Graduación');
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertEquals(new \DateTimeImmutable('2026-02-10'), $data->effectiveStart);
        self::assertEquals(new \DateTimeImmutable('2026-05-20'), $data->effectiveEnd);
    }

    public function testAnExplicitDateOverridesTheComputedRange(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        $calendar->setStartDate(new \DateTimeImmutable('2026-01-01'));
        $calendar->addDate(new \DateTimeImmutable('2026-05-20'), '#a7f3d0', 'Graduación');
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        self::assertEquals(new \DateTimeImmutable('2026-01-01'), $data->effectiveStart);
    }

    public function testTwoIndividualDatesSharingTheSameDayAreListedSeparatelyNeverMerged(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        $calendar->addDate(new \DateTimeImmutable('2026-03-10'), '#a7f3d0', 'Entrega de notas');
        $calendar->addDate(new \DateTimeImmutable('2026-03-10'), '#a7f3d0', 'Claustro');
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        $march = $this->monthAnnotationLabels($data->months, 3);
        self::assertSame(['10', '10'], $march);
    }

    public function testANonWorkingDaysRunMergesBySameDescriptionWhileADifferentlyDescribedOneStaysApart(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Two non-working days with the same description merge; a third, differently described,
        // stays on its own even though it's the very next day.
        $holiday1 = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-19'))->setDescription('Puente')->setAcademicYear($year);
        $holiday2 = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-20'))->setDescription('Puente')->setAcademicYear($year);
        $holiday3 = (new NonWorkingDay())->setDate(new \DateTimeImmutable('2026-03-23'))->setDescription('Día del centro')->setAcademicYear($year);
        $calendar->setStartDate(new \DateTimeImmutable('2026-03-01'));
        $calendar->setEndDate(new \DateTimeImmutable('2026-03-31'));
        $this->persist($centre, $year, $teacher, $holiday1, $holiday2, $holiday3, $calendar);

        $data = $this->builder->build($calendar);

        $march = $this->monthAnnotationLabels($data->months, 3);
        self::assertContains('19-20', $march);
        self::assertContains('23', $march);
    }

    public function testAPeriodOnlyMarksItsOverallStartAndEndDayInTheMargin(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        // Weekdays-with-hours from Jan 5 (Mon) to Jan 16 (Fri): weekends split it into several
        // runs of working days, but the margin must show only "Inicio FCT" (5) and "Fin FCT"
        // (16) — never one entry per week.
        $calendar->addPeriod('FCT', '#c7d2fe', false, PrintableCalendarPeriodMode::StartWithHours, new \DateTimeImmutable('2026-01-05'), null, 80.0, 8.0, 8.0, 8.0, 8.0, 8.0);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        $january = $data->months[0]->annotations;
        self::assertCount(2, $january);
        self::assertSame('5', $january[0]->label);
        self::assertSame('Inicio FCT', $january[0]->description);
        self::assertSame('16', $january[1]->label);
        self::assertSame('Fin FCT', $january[1]->description);
    }

    public function testASingleDayPeriodGetsOnePlainMarginEntryWithNoInicioFinLabel(): void
    {
        $centre   = $this->centre();
        $year     = $this->year($centre);
        $teacher  = $this->teacher('docente');
        $calendar = $this->calendar($centre, $year, $teacher);
        $calendar->addPeriod('Jornada de puertas abiertas', '#fca5a5', false, PrintableCalendarPeriodMode::DateRange, new \DateTimeImmutable('2026-03-12'), new \DateTimeImmutable('2026-03-12'), null, null, null, null, null, null);
        $this->persist($centre, $year, $teacher, $calendar);

        $data = $this->builder->build($calendar);

        $march = $data->months[0]->annotations;
        self::assertCount(1, $march);
        self::assertSame('Jornada de puertas abiertas', $march[0]->description);
    }
}
