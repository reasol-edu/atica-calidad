<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivitySubmissionScope;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SchoolEvent;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class CalendarComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    /** @return array<string, mixed> */
    private function props(TestLiveComponent $component): array
    {
        $value = $component->render()->crawler()->filter('[data-live-props-value]')->attr('data-live-props-value');
        self::assertNotNull($value);

        /** @var array<string, mixed> $props */
        $props = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return $props;
    }

    public function testAdminSeesARestrictedEventOnTheGrid(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $event   = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2025-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Evento restringido')
            ->setGeneral(false);
        $event->addProfileRestriction($profile);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $year, $profile, $event, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('CalendarComponent', ['year' => 2025, 'month' => 9], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Evento restringido', $html);
    }

    public function testTeacherDoesNotSeeARestrictedEventForAProfileTheyLack(): void
    {
        $centre  = $this->centre();
        $year    = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $event   = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2025-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Evento restringido')
            ->setGeneral(false);
        $event->addProfileRestriction($profile);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $profile, $event, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('CalendarComponent', ['year' => 2025, 'month' => 9], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString('Evento restringido', $html);
    }

    public function testTeacherSeesAGeneralEvent(): void
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $event = (new SchoolEvent())
            ->setAcademicYear($year)
            ->setDate(new \DateTimeImmutable('2025-09-15'))
            ->setStartTime(new \DateTimeImmutable('09:00'))
            ->setEndTime(new \DateTimeImmutable('10:00'))
            ->setName('Evento general')
            ->setGeneral(true);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $year, $event, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('CalendarComponent', ['year' => 2025, 'month' => 9], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Evento general', $html);
    }

    public function testNextMonthAndPreviousMonthNavigate(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('CalendarComponent', ['year' => 2025, 'month' => 9], $this->client);
        $component->call('nextMonth');

        $props = $this->props($component);
        self::assertSame(10, $props['month']);
        self::assertSame(2025, $props['year']);

        $component->call('previousMonth');
        $component->call('previousMonth');
        $props = $this->props($component);
        self::assertSame(8, $props['month']);
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    public function testShowsAnActivityDeadlineInItsOwnMonthForATeacherWhoHoldsTheUploadProfile(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = (new Activity())->setCategory($category)->setTitle('Programaciones didácticas')->setStart(1, 9)->setEnd(30, 9)->setFolder($folder);
        $teacher  = $this->teacher('docente');
        $assign   = new SpecificProfileAssignment($profile, null, $teacher);

        $this->persist($centre, $year, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assign);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('CalendarComponent', ['year' => 2025, 'month' => 9], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Programaciones didácticas', $html);
    }

    public function testDoesNotShowAnActivityDeadlineInAnUnrelatedMonth(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = (new Activity())->setCategory($category)->setTitle('Programaciones didácticas')->setStart(1, 9)->setEnd(30, 9)->setFolder($folder);
        $teacher  = $this->teacher('docente');
        $assign   = new SpecificProfileAssignment($profile, null, $teacher);

        $this->persist($centre, $year, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assign);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('CalendarComponent', ['year' => 2025, 'month' => 11], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString('Programaciones didácticas', $html);
    }

    public function testAnAdminWithNoUploadProfileOfTheirOwnDoesNotSeeTheActivityDeadline(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = (new Activity())->setCategory($category)->setTitle('Programaciones didácticas')->setStart(1, 9)->setEnd(30, 9)->setFolder($folder);
        $admin    = $this->teacher('director')->setAdmin(true);

        $this->persist($centre, $year, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('CalendarComponent', ['year' => 2025, 'month' => 9], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString('Programaciones didácticas', $html);
    }

    public function testAByProfileActivityWithTwoOwnerRowsShowsBothOnTheGrid(): void
    {
        $centre   = $this->centre();
        $year     = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $mate     = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura Matemáticas');
        $info     = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura Informática');
        $folder->addUploadProfile($mate);
        $folder->addUploadProfile($info);
        $activity = (new Activity())->setCategory($category)->setTitle('Programaciones didácticas')->setStart(1, 9)->setEnd(30, 9)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $teacher  = $this->teacher('docente');
        $assignA  = new SpecificProfileAssignment($mate, null, $teacher);
        $assignB  = new SpecificProfileAssignment($info, null, $teacher);

        $this->persist($centre, $year, $category, $folder->getDocumentSection(), $folder, $mate, $info, $activity, $teacher, $assignA, $assignB);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('CalendarComponent', ['year' => 2025, 'month' => 9], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Jefatura Matemáticas', $html);
        self::assertStringContainsString('Jefatura Informática', $html);
    }

    public function testNextMonthAcrossAYearBoundaryRollsOverTheYear(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('CalendarComponent', ['year' => 2025, 'month' => 12], $this->client);
        $component->call('nextMonth');

        $props = $this->props($component);
        self::assertSame(1, $props['month']);
        self::assertSame(2026, $props['year']);
    }
}
