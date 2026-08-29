<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class SpecificProfileAssignmentsComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    /** @return array{0: EducationalCentre, 1: AcademicYear, 2: Teacher} */
    private function centreWithAdminAndActiveYear(): array
    {
        $centre = $this->centre();
        $year   = (new AcademicYear())->setName('2025-2026')->setEducationalCentre($centre);
        $centre->setActiveAcademicYear($year);
        $admin = $this->teacher('director');
        $centre->getAdmins()->add($admin);

        return [$centre, $year, $admin];
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

    public function testMountDeniesAccessWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileAssignmentsComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->render();
    }

    public function testAssignTeacherToRowAddsTheAssignment(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $this->persist($centre, $year, $admin, $profile, $teacher);
        $rowKey    = $profile->getId()->toRfc4122();
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileAssignmentsComponent', ['centre' => $centre], $this->client);
        $component->call('selectRow', ['key' => $rowKey]);
        $component->call('assignTeacherToRow', ['teacherId' => $teacherId]);

        $this->em->clear();
        /** @var \App\Repository\SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(\App\Repository\SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profile->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getAssignments());
    }

    public function testRemoveTeacherFromRowRemovesTheAssignment(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $year, $admin, $profile, $teacher, $assignment);
        $rowKey    = $profile->getId()->toRfc4122();
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileAssignmentsComponent', ['centre' => $centre], $this->client);
        $component->call('selectRow', ['key' => $rowKey]);
        $component->call('removeTeacherFromRow', ['teacherId' => $teacherId]);

        $this->em->clear();
        /** @var \App\Repository\SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(\App\Repository\SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profile->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getAssignments());
    }

    /** A teacher assigned last year but not re-enrolled this year is flagged as "off year" and counted for bulk cleanup. */
    public function testOffYearAssignmentIsDetectedAndBulkRemovable(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $profile     = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $offYearTeacher = $this->teacher('ausente'); // NOT added to $year
        $assignment  = new SpecificProfileAssignment($profile, null, $offYearTeacher);
        $this->persist($centre, $year, $admin, $profile, $offYearTeacher, $assignment);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileAssignmentsComponent', ['centre' => $centre], $this->client);
        /** @var \App\Twig\Components\Admin\SpecificProfileAssignmentsComponent $instance */
        $instance  = $component->component();
        self::assertSame(1, $instance->getOffYearAssignmentCount());

        $component->call('bulkRemoveOffYear');

        $this->em->clear();
        /** @var \App\Repository\SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(\App\Repository\SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profile->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getAssignments(), 'the off-year assignment must be gone after the bulk cleanup');
    }

    public function testProfilePaginationFiltersOutInactiveProfilesByDefault(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $activeProfile   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Activo')->setActive(true);
        $inactiveProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Inactivo')->setActive(false);
        $this->persist($centre, $year, $admin, $activeProfile, $inactiveProfile);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileAssignmentsComponent', ['centre' => $centre], $this->client);
        /** @var \App\Twig\Components\Admin\SpecificProfileAssignmentsComponent $instance */
        $instance  = $component->component();

        $names = array_map(static fn (\App\Model\ProfileAssignmentRow $row): string => $row->displayName, $instance->getProfilePagination()->getItems());
        self::assertContains('Activo', $names);
        self::assertNotContains('Inactivo', $names);
    }

    public function testSelectTabSwitchesBetweenProfilesAndTeachers(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $this->persist($centre, $year, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileAssignmentsComponent', ['centre' => $centre], $this->client);
        $component->call('selectTab', ['tab' => 'teachers']);

        $props = $this->props($component);
        self::assertSame('teachers', $props['tab']);

        $component->call('selectTab', ['tab' => 'not-a-real-tab']);
        $props = $this->props($component);
        self::assertSame('profiles', $props['tab'], 'an unrecognised tab value must fall back to "profiles"');
    }

    public function testAssignRowToTeacherFromTheTeachersTab(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $this->persist($centre, $year, $admin, $profile, $teacher);
        $teacherId = $teacher->getId()->toRfc4122();
        $rowKey    = $profile->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileAssignmentsComponent', ['centre' => $centre], $this->client);
        $component->call('selectTab', ['tab' => 'teachers']);
        $component->call('selectTeacher', ['id' => $teacherId]);
        $component->call('assignRowToTeacher', ['rowKey' => $rowKey]);

        $this->em->clear();
        /** @var \App\Repository\SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(\App\Repository\SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profile->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getAssignments());
    }
}
