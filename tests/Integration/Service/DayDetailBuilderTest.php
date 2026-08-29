<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\NonWorkingDay;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Service\DayDetailBuilder;
use App\Tests\Integration\RepositoryTestCase;

final class DayDetailBuilderTest extends RepositoryTestCase
{
    private DayDetailBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DayDetailBuilder $builder */
        $builder      = self::getContainer()->get(DayDetailBuilder::class);
        $this->builder = $builder;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function event(AcademicYear $year, \DateTimeImmutable $date, bool $general = true): SchoolEvent
    {
        return (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate($date)
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Evento')
            ->setGeneral($general);
    }

    public function testAdminSeesEveryEventRegardlessOfVisibility(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-09-08');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil restringido');
        $event   = $this->event($year, $date, general: false);
        $event->addProfileRestriction($profile);
        $admin = $this->teacher('admin');
        $this->persist($centre, $year, $profile, $event, $admin);

        $report = $this->builder->build($year, $admin, true, $date);

        self::assertCount(1, $report->events);
    }

    public function testTeacherSeesGeneralEventsRegardlessOfProfile(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-09-08');
        $event   = $this->event($year, $date, general: true);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $event, $teacher);

        $report = $this->builder->build($year, $teacher, false, $date);

        self::assertCount(1, $report->events);
    }

    public function testTeacherDoesNotSeeARestrictedEventForAProfileTheyDoNotHold(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-09-08');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil restringido');
        $event   = $this->event($year, $date, general: false);
        $event->addProfileRestriction($profile);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $profile, $event, $teacher);

        $report = $this->builder->build($year, $teacher, false, $date);

        self::assertCount(0, $report->events);
    }

    public function testTeacherSeesARestrictedEventWhenTheyHoldTheProfile(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-09-08');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil restringido');
        $event   = $this->event($year, $date, general: false);
        $event->addProfileRestriction($profile);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $year, $profile, $event, $teacher, $assignment);

        $report = $this->builder->build($year, $teacher, false, $date);

        self::assertCount(1, $report->events);
    }

    public function testAnonymousViewerSeesNoEvents(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date   = new \DateTimeImmutable('2025-09-08');
        $event  = $this->event($year, $date, general: true);
        $this->persist($centre, $year, $event);

        $report = $this->builder->build($year, null, false, $date);

        self::assertCount(0, $report->events);
    }

    public function testNonWorkingDayLabelUsesTheRegisteredDescriptionWhenPresent(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-10-13');
        $holiday = (new NonWorkingDay())->setDate($date)->setDescription('Fiesta local')->setAcademicYear($year);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $holiday, $teacher);

        $report = $this->builder->build($year, $teacher, false, $date);

        self::assertSame('Fiesta local', $report->nonWorkingDayLabel);
    }

    public function testNonWorkingDayLabelFallsBackToAGenericWeekendMessage(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-09-06'); // Saturday, no registered holiday
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $teacher);

        $report = $this->builder->build($year, $teacher, false, $date);

        self::assertNotNull($report->nonWorkingDayLabel);
    }

    public function testOrdinarySchoolDayHasNoNonWorkingDayLabel(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-09-08');
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $teacher);

        $report = $this->builder->build($year, $teacher, false, $date);

        self::assertNull($report->nonWorkingDayLabel);
    }
}
