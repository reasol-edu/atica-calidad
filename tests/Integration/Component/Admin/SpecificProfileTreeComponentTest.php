<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component\Admin;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\SpecificProfileRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class SpecificProfileTreeComponentTest extends ControllerTestCase
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

    public function testMountDeniesAccessWithoutResponsibilitiesPermission(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->render();
    }

    public function testAddProfileCreatesAndSelectsIt(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $this->persist($centre, $year, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);
        $component->set('addName', 'Tutor/a')->call('addProfile');

        $this->em->clear();
        /** @var SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(SpecificProfileRepository::class);
        $all      = $profiles->findByCentre($centre);
        self::assertCount(1, $all);
        self::assertSame('Tutor/a', $all[0]->getName());
    }

    /** Without an active academic year, writes must be blocked even though the read-only view still works. */
    public function testAddProfileDeniedWithoutAnActiveAcademicYear(): void
    {
        $centre = $this->centre();
        $admin  = $this->teacher('director');
        $centre->getAdmins()->add($admin);
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->set('addName', 'Tutor/a')->call('addProfile');
    }

    public function testSaveDetailRenames(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Original');
        $this->persist($centre, $year, $admin, $profile);
        $profileId = $profile->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectProfile', ['id' => $profileId]);
        $component->set('editName', 'Renombrado')->call('saveDetail');

        $this->em->clear();
        /** @var SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profileId, $centre);
        self::assertNotNull($reloaded);
        self::assertSame('Renombrado', $reloaded->getName());
    }

    public function testDeleteSelectedRemovesTheProfile(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('A borrar');
        $this->persist($centre, $year, $admin, $profile);
        $profileId = $profile->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectProfile', ['id' => $profileId]);
        $component->call('deleteSelected');

        $this->em->clear();
        /** @var SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(SpecificProfileRepository::class);
        self::assertNull($profiles->findByIdAndCentre($profileId, $centre));
    }

    public function testPickListItemAssociatesTheProfileAndInvalidatesExistingAssignments(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $profile    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $teacher    = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $listItem   = (new ListItem())->setEducationalCentre($centre)->setName('Departamentos');
        $this->persist($centre, $year, $admin, $profile, $teacher, $assignment, $listItem);
        $profileId  = $profile->getId()->toRfc4122();
        $listItemId = $listItem->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectProfile', ['id' => $profileId]);
        $component->call('pickListItem', ['id' => $listItemId]);

        $this->em->clear();
        /** @var SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profileId, $centre);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isListAssociated());
        self::assertCount(0, $reloaded->getAssignments(), 'switching to list-associated mode must clear the direct assignment made under the old (non-associated) mode');
    }

    public function testClearListAssociationReturnsToDirectMode(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $listItem = (new ListItem())->setEducationalCentre($centre)->setName('Departamentos');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($listItem);
        $this->persist($centre, $year, $admin, $listItem, $profile);
        $profileId = $profile->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectProfile', ['id' => $profileId]);
        $component->call('clearListAssociation');

        $this->em->clear();
        /** @var SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profileId, $centre);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isListAssociated());
    }

    public function testAssignTeacherToADirectModeProfile(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $this->persist($centre, $year, $admin, $profile, $teacher);
        $profileId = $profile->getId()->toRfc4122();
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectProfile', ['id' => $profileId]);
        $component->call('assignTeacher', ['teacherId' => $teacherId]);

        $this->em->clear();
        /** @var SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profileId, $centre);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getAssignments());
    }

    public function testAssignTeacherToAListAssociatedProfileRequiresALeafSelected(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $listItem = (new ListItem())->setEducationalCentre($centre)->setName('Departamentos');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($listItem);
        $teacher  = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $this->persist($centre, $year, $admin, $listItem, $profile, $teacher);
        $profileId = $profile->getId()->toRfc4122();
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectProfile', ['id' => $profileId]);
        // No leaf selected — must be refused, not silently assign to "the whole profile".
        $component->call('assignTeacher', ['teacherId' => $teacherId]);

        $this->em->clear();
        /** @var SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profileId, $centre);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getAssignments());
    }

    public function testRemoveTeacherUnassignsThem(): void
    {
        [$centre, $year, $admin] = $this->centreWithAdminAndActiveYear();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $teacher = $this->teacher('docente');
        $teacher->addAcademicYear($year);
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $year, $admin, $profile, $teacher, $assignment);
        $profileId = $profile->getId()->toRfc4122();
        $teacherId = $teacher->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('Admin:SpecificProfileTreeComponent', ['centre' => $centre], $this->client);
        $component->call('selectProfile', ['id' => $profileId]);
        $component->call('removeTeacher', ['teacherId' => $teacherId]);

        $this->em->clear();
        /** @var SpecificProfileRepository $profiles */
        $profiles = self::getContainer()->get(SpecificProfileRepository::class);
        $reloaded = $profiles->findByIdAndCentre($profileId, $centre);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getAssignments());
    }
}
