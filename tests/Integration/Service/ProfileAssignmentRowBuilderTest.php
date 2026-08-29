<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Service\ProfileAssignmentRowBuilder;
use App\Tests\Integration\RepositoryTestCase;

final class ProfileAssignmentRowBuilderTest extends RepositoryTestCase
{
    private ProfileAssignmentRowBuilder $rowBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ProfileAssignmentRowBuilder $rowBuilder */
        $rowBuilder       = self::getContainer()->get(ProfileAssignmentRowBuilder::class);
        $this->rowBuilder = $rowBuilder;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testBuildAllRowsProducesOneRowPerPlainProfile(): void
    {
        $centre  = $this->centre();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $this->persist($centre, $profile);

        $rows = $this->rowBuilder->buildAllRows($centre);

        self::assertCount(1, $rows);
        self::assertSame('Secretario/a', $rows[0]->displayName);
        self::assertNull($rows[0]->listItem);
    }

    public function testBuildAllRowsProducesOneRowPerLeafForAListAssociatedProfile(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo');
        $leafA  = (new ListItem())->setEducationalCentre($centre)->setName('1º ESO A');
        $leafB  = (new ListItem())->setEducationalCentre($centre)->setName('1º ESO B');
        $leafA->setParent($root);
        $leafB->setParent($root);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a')->setListItem($root);
        $this->persist($centre, $root, $leafA, $leafB, $profile);

        $rows = $this->rowBuilder->buildAllRows($centre);

        self::assertCount(2, $rows);
        $names = array_map(static fn ($r) => $r->displayName, $rows);
        sort($names);
        self::assertSame(['Tutor/a 1º ESO A', 'Tutor/a 1º ESO B'], $names);
    }

    public function testBuildAllRowsCarriesAssignedTeachersPerRow(): void
    {
        $centre     = $this->centre();
        $profile    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $profile, $teacher, $assignment);

        $rows = $this->rowBuilder->buildAllRows($centre);

        self::assertCount(1, $rows);
        self::assertCount(1, $rows[0]->teachers);
        self::assertSame('docente', $rows[0]->teachers[0]->getUsername());
    }

    public function testBuildActiveRowsExcludesInactiveProfilesAndLeaves(): void
    {
        $centre         = $this->centre();
        $activeProfile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Activo')->setActive(true);
        $inactiveProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Inactivo')->setActive(false);
        $this->persist($centre, $activeProfile, $inactiveProfile);

        $rows = $this->rowBuilder->buildActiveRows($centre);

        self::assertCount(1, $rows);
        self::assertSame('Activo', $rows[0]->displayName);
    }

    public function testBuildActiveRowsExcludesLeavesOfAnInactiveListItem(): void
    {
        $centre       = $this->centre();
        $root         = (new ListItem())->setEducationalCentre($centre)->setName('Grupo');
        $activeLeaf   = (new ListItem())->setEducationalCentre($centre)->setName('Activa')->setActive(true);
        $inactiveLeaf = (new ListItem())->setEducationalCentre($centre)->setName('Inactiva')->setActive(false);
        $activeLeaf->setParent($root);
        $inactiveLeaf->setParent($root);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a')->setListItem($root);
        $this->persist($centre, $root, $activeLeaf, $inactiveLeaf, $profile);

        $rows = $this->rowBuilder->buildActiveRows($centre);

        self::assertCount(1, $rows);
        self::assertSame('Tutor/a Activa', $rows[0]->displayName);
    }

    /** The "(todos)" wildcard row is only added for list-associated profiles, right before their own per-subperfil rows. */
    public function testBuildActiveRowsWithWholeProfileOptionAddsAWildcardRowOnlyForListAssociatedProfiles(): void
    {
        $centre = $this->centre();
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo');
        $leaf   = (new ListItem())->setEducationalCentre($centre)->setName('1º ESO A');
        $leaf->setParent($root);
        $listAssociated = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a')->setListItem($root);
        $plain          = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $this->persist($centre, $root, $leaf, $listAssociated, $plain);

        $rows = $this->rowBuilder->buildActiveRowsWithWholeProfileOption($centre);

        self::assertCount(3, $rows, 'Secretario/a (1) + Tutor/a (todos) + Tutor/a 1º ESO A (2), alphabetically grouped by profile');
        $names = array_map(static fn ($r) => $r->displayName, $rows);
        self::assertContains('Tutor/a (todos)', $names);
        self::assertContains('Tutor/a 1º ESO A', $names);
        self::assertContains('Secretario/a', $names);
        self::assertNotContains('Secretario/a (todos)', $names, 'a non-list-associated profile never gets a wildcard row');
    }

    public function testBuildActiveRowsWithWholeProfileOptionOrdersGroupsAlphabeticallyByProfileName(): void
    {
        $centre = $this->centre();
        $zebra  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Zebra');
        $alpha  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Alpha');
        $this->persist($centre, $zebra, $alpha);

        $rows = $this->rowBuilder->buildActiveRowsWithWholeProfileOption($centre);

        self::assertCount(2, $rows);
        self::assertSame('Alpha', $rows[0]->displayName);
        self::assertSame('Zebra', $rows[1]->displayName);
    }

    public function testRowsAreScopedToTheirOwnCentre(): void
    {
        $centreA  = $this->centre();
        $centreB  = (new EducationalCentre())->setCode('87654321')->setName('Otro')->setCity('Otra ciudad');
        $profileA = (new SpecificProfile())->setEducationalCentre($centreA)->setName('De A');
        $profileB = (new SpecificProfile())->setEducationalCentre($centreB)->setName('De B');
        $this->persist($centreA, $centreB, $profileA, $profileB);

        $rows = $this->rowBuilder->buildAllRows($centreA);

        self::assertCount(1, $rows);
        self::assertSame('De A', $rows[0]->displayName);
    }
}
