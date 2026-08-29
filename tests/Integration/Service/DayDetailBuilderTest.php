<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
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

        $report = $this->builder->build($year, $centre, $admin, true, $date);

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

        $report = $this->builder->build($year, $centre, $teacher, false, $date);

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

        $report = $this->builder->build($year, $centre, $teacher, false, $date);

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

        $report = $this->builder->build($year, $centre, $teacher, false, $date);

        self::assertCount(1, $report->events);
    }

    public function testAnonymousViewerSeesNoEvents(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date   = new \DateTimeImmutable('2025-09-08');
        $event  = $this->event($year, $date, general: true);
        $this->persist($centre, $year, $event);

        $report = $this->builder->build($year, $centre, null, false, $date);

        self::assertCount(0, $report->events);
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    public function testAnActivityDeadlineLandingOnTheRequestedDateAppearsForItsOwner(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date     = new \DateTimeImmutable('2025-09-30');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = (new Activity())->setCategory($category)->setTitle('Programaciones didácticas')->setStart(1, 9)->setEnd(30, 9)->setFolder($folder);
        $teacher  = $this->teacher('docente');
        $assign   = new SpecificProfileAssignment($profile, null, $teacher);

        $this->persist($centre, $year, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assign);

        $report = $this->builder->build($year, $centre, $teacher, false, $date);

        self::assertCount(1, $report->activityDeadlines);
        self::assertSame('Programaciones didácticas', $report->activityDeadlines[0]->activity->getTitle());
        self::assertFalse($report->activityDeadlines[0]->completed);
    }

    public function testAnActivityDeadlineDoesNotAppearOnAnUnrelatedDate(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date     = new \DateTimeImmutable('2025-09-08');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = (new Activity())->setCategory($category)->setTitle('Programaciones didácticas')->setStart(1, 9)->setEnd(30, 9)->setFolder($folder);
        $teacher  = $this->teacher('docente');
        $assign   = new SpecificProfileAssignment($profile, null, $teacher);

        $this->persist($centre, $year, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assign);

        $report = $this->builder->build($year, $centre, $teacher, false, $date);

        self::assertSame([], $report->activityDeadlines);
    }

    public function testACompletedActivityDeadlineReflectsItsCompletionState(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date     = new \DateTimeImmutable('2025-09-30');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Lectura de la política de calidad')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $completion = new ActivityCompletion($activity, $teacher, null, null, $teacher);

        $this->persist($centre, $year, $category, $activity, $teacher, $completion);

        $report = $this->builder->build($year, $centre, $teacher, false, $date);

        self::assertCount(1, $report->activityDeadlines);
        self::assertTrue($report->activityDeadlines[0]->completed);
    }

    public function testAnAnonymousViewerSeesNoActivityDeadlines(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date     = new \DateTimeImmutable('2025-09-30');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Lectura de la política de calidad')->setStart(1, 9)->setEnd(30, 9);
        $this->persist($centre, $year, $category, $activity);

        $report = $this->builder->build($year, $centre, null, false, $date);

        self::assertSame([], $report->activityDeadlines);
    }

    public function testNonWorkingDayLabelUsesTheRegisteredDescriptionWhenPresent(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-10-13');
        $holiday = (new NonWorkingDay())->setDate($date)->setDescription('Fiesta local')->setAcademicYear($year);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $holiday, $teacher);

        $report = $this->builder->build($year, $centre, $teacher, false, $date);

        self::assertSame('Fiesta local', $report->nonWorkingDayLabel);
    }

    public function testNonWorkingDayLabelFallsBackToAGenericWeekendMessage(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-09-06'); // Saturday, no registered holiday
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $teacher);

        $report = $this->builder->build($year, $centre, $teacher, false, $date);

        self::assertNotNull($report->nonWorkingDayLabel);
    }

    public function testOrdinarySchoolDayHasNoNonWorkingDayLabel(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $date    = new \DateTimeImmutable('2025-09-08');
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $teacher);

        $report = $this->builder->build($year, $centre, $teacher, false, $date);

        self::assertNull($report->nonWorkingDayLabel);
    }
}
