<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\SpecificProfileAssignmentRepository;
use App\Tests\Integration\RepositoryTestCase;

final class SpecificProfileAssignmentRepositoryTest extends RepositoryTestCase
{
    public function testIsTeacherAssignedToAny(): void
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $item   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);

        $tutor    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a')->setPosition(0);
        $auditor  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Auditor/a')->setPosition(1);
        $tutor->setListItem($item);

        $teacher = (new Teacher(new PersonName('Nombre', 'Apellido')))->setUsername('docente');
        $other   = (new Teacher(new PersonName('Otro', 'Docente')))->setUsername('otro');

        $assignment = new SpecificProfileAssignment($tutor, $item, $teacher);

        $this->persist($centre, $item, $tutor, $auditor, $teacher, $other, $assignment);

        /** @var SpecificProfileAssignmentRepository $repo */
        $repo = self::getContainer()->get(SpecificProfileAssignmentRepository::class);

        self::assertTrue($repo->isTeacherAssignedToAny($teacher, [[$tutor, $item]]));
        self::assertTrue(
            $repo->isTeacherAssignedToAny($teacher, [[$tutor, null]]),
            'a "whole profile" pair (null subprofile) on a list-associated profile matches any subprofile holder',
        );
        self::assertFalse($repo->isTeacherAssignedToAny($teacher, [[$auditor, null]]));
        self::assertFalse($repo->isTeacherAssignedToAny($other, [[$tutor, $item]]));
        self::assertFalse(
            $repo->isTeacherAssignedToAny($other, [[$tutor, null]]),
            'the wildcard still only matches teachers actually assigned to one of its subprofiles',
        );
        self::assertFalse($repo->isTeacherAssignedToAny($teacher, []));
        self::assertTrue($repo->isTeacherAssignedToAny($teacher, [[$auditor, null], [$tutor, $item]]), 'any pair matching is enough');
    }

    public function testFindTeachersByProfileAndListItemIsAnExactMatch(): void
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo');
        $leafA  = (new ListItem())->setEducationalCentre($centre)->setName('1º DAW A');
        $leafB  = (new ListItem())->setEducationalCentre($centre)->setName('1º DAW B');
        $leafA->setParent($root);
        $leafB->setParent($root);

        $tutor    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a')->setListItem($root);
        $teacherA = (new Teacher(new PersonName('Nombre', 'A')))->setUsername('a');
        $teacherB = (new Teacher(new PersonName('Nombre', 'B')))->setUsername('b');
        $assignA  = new SpecificProfileAssignment($tutor, $leafA, $teacherA);
        $assignB  = new SpecificProfileAssignment($tutor, $leafB, $teacherB);

        $this->persist($centre, $root, $leafA, $leafB, $tutor, $teacherA, $teacherB, $assignA, $assignB);

        /** @var SpecificProfileAssignmentRepository $repo */
        $repo = self::getContainer()->get(SpecificProfileAssignmentRepository::class);

        $forA = $repo->findTeachersByProfileAndListItem($tutor, $leafA);
        self::assertCount(1, $forA);
        self::assertSame('a', $forA[0]->getUsername());

        self::assertSame(
            [],
            $repo->findTeachersByProfileAndListItem($tutor, null),
            'the exact-match lookup does not fall back to a wildcard for a list-associated profile',
        );
    }

    /**
     * Covers the wildcard-inclusive lookup ActivitySubmissionSlotBuilder relies on to expand an
     * Individual-scope submission row into one slot per teacher: unlike
     * findTeachersByProfileAndListItem() (used by the exact per-leaf admin assignment screen), this
     * must also surface teachers assigned to any OTHER subprofile of the same profile when the
     * caller queries with a specific leaf, and must never return the same teacher twice.
     */
    public function testFindTeachersHoldingProfileAndListItemIncludesWildcardHoldersAndDeduplicates(): void
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Grupo');
        $leafA  = (new ListItem())->setEducationalCentre($centre)->setName('1º DAW A');
        $leafB  = (new ListItem())->setEducationalCentre($centre)->setName('1º DAW B');
        $leafA->setParent($root);
        $leafB->setParent($root);

        $tutor   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a')->setListItem($root);
        $other   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Otro perfil');

        $directHolder   = (new Teacher(new PersonName('Nombre', 'Directo')))->setUsername('directo');
        $wildcardHolder = (new Teacher(new PersonName('Nombre', 'Comodín')))->setUsername('comodin');
        $otherLeafHolder = (new Teacher(new PersonName('Nombre', 'Otra hoja')))->setUsername('otra_hoja');
        $unrelated      = (new Teacher(new PersonName('Nombre', 'Ajeno')))->setUsername('ajeno');

        $directAssignment    = new SpecificProfileAssignment($tutor, $leafA, $directHolder);
        // A "whole profile" assignment (listItem === null) on a list-associated profile is the
        // wildcard: it must count as holding EVERY one of that profile's subprofiles.
        $wildcardAssignment  = new SpecificProfileAssignment($tutor, null, $wildcardHolder);
        $otherLeafAssignment = new SpecificProfileAssignment($tutor, $leafB, $otherLeafHolder);
        $unrelatedAssignment = new SpecificProfileAssignment($other, null, $unrelated);

        $this->persist(
            $centre, $root, $leafA, $leafB, $tutor, $other,
            $directHolder, $wildcardHolder, $otherLeafHolder, $unrelated,
            $directAssignment, $wildcardAssignment, $otherLeafAssignment, $unrelatedAssignment,
        );

        /** @var SpecificProfileAssignmentRepository $repo */
        $repo = self::getContainer()->get(SpecificProfileAssignmentRepository::class);

        $holders = $repo->findTeachersHoldingProfileAndListItem($tutor, $leafA);
        $usernames = array_map(static fn (Teacher $t): string => $t->getUsername(), $holders);
        sort($usernames);

        self::assertSame(['comodin', 'directo'], $usernames, 'holds leafA directly, plus the profile-wide wildcard holder — but not leafB\'s holder or an unrelated profile\'s');
    }

    /** Departamento > (ESO/Bach > Matemáticas) and (FP > Informática, Sanidad); one teacher per leaf. */
    public function testAnIntermediateNodeStandsForEveryLeafUnderItInEveryLookup(): void
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $eso    = (new ListItem())->setEducationalCentre($centre)->setName('ESO/Bach')->setParent($root);
        $mates  = (new ListItem())->setEducationalCentre($centre)->setName('Matemáticas')->setParent($eso);
        $fp     = (new ListItem())->setEducationalCentre($centre)->setName('FP')->setParent($root);
        $infor  = (new ListItem())->setEducationalCentre($centre)->setName('Informática')->setParent($fp);
        $sani   = (new ListItem())->setEducationalCentre($centre)->setName('Sanidad')->setParent($fp);
        $jefe   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($root);

        $tInfor = (new Teacher(new PersonName('Nombre', 'Informática')))->setUsername('t_infor');
        $tSani  = (new Teacher(new PersonName('Nombre', 'Sanidad')))->setUsername('t_sani');
        $tMates = (new Teacher(new PersonName('Nombre', 'Matemáticas')))->setUsername('t_mates');

        $this->persist(
            $centre, $root, $eso, $mates, $fp, $infor, $sani, $jefe, $tInfor, $tSani, $tMates,
            new SpecificProfileAssignment($jefe, $infor, $tInfor),
            new SpecificProfileAssignment($jefe, $sani, $tSani),
            new SpecificProfileAssignment($jefe, $mates, $tMates),
        );

        /** @var SpecificProfileAssignmentRepository $repo */
        $repo      = self::getContainer()->get(SpecificProfileAssignmentRepository::class);
        $usernames = static function (array $teachers): array {
            $names = array_map(static fn (Teacher $t): string => (string) $t->getUsername(), $teachers);
            sort($names);

            return $names;
        };

        self::assertSame(['t_infor', 't_sani'], $usernames($repo->findTeachersHoldingProfileAndListItem($jefe, $fp)));
        self::assertSame(['t_mates'], $usernames($repo->findTeachersHoldingProfileAndListItem($jefe, $eso)));
        self::assertSame(['t_sani'], $usernames($repo->findTeachersHoldingProfileAndListItem($jefe, $sani)), 'a leaf, as before');

        $batched = $repo->findTeachersHoldingProfileAndListItemForPairs([[$jefe, $fp], [$jefe, $eso]]);
        self::assertSame(['t_infor', 't_sani'], $usernames($batched[$jefe->getId()->toRfc4122() . ':' . $fp->getId()->toRfc4122()]));
        self::assertSame(['t_mates'], $usernames($batched[$jefe->getId()->toRfc4122() . ':' . $eso->getId()->toRfc4122()]));

        self::assertTrue($repo->isTeacherAssignedToAny($tInfor, [[$jefe, $fp]]));
        self::assertFalse($repo->isTeacherAssignedToAny($tMates, [[$jefe, $fp]]));
    }

    /** "(todos)" on a list-associated profile means whoever holds any of its leaves — teachers hold leaves, never the profile itself. */
    public function testTheWholeProfilePickOfAListAssociatedProfileReachesTheHoldersOfItsLeaves(): void
    {
        $centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $root   = (new ListItem())->setEducationalCentre($centre)->setName('Departamento');
        $leaf   = (new ListItem())->setEducationalCentre($centre)->setName('Informática')->setParent($root);
        $jefe   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($root);
        $holder = (new Teacher(new PersonName('Nombre', 'Jefe')))->setUsername('jefe');

        $this->persist($centre, $root, $leaf, $jefe, $holder, new SpecificProfileAssignment($jefe, $leaf, $holder));

        /** @var SpecificProfileAssignmentRepository $repo */
        $repo = self::getContainer()->get(SpecificProfileAssignmentRepository::class);

        self::assertSame(['jefe'], array_map(static fn (Teacher $t): ?string => $t->getUsername(), $repo->findTeachersHoldingProfileAndListItem($jefe, null)));
        $batched = $repo->findTeachersHoldingProfileAndListItemForPairs([[$jefe, null]]);
        self::assertCount(1, $batched[$jefe->getId()->toRfc4122()]);
    }

    public function testFindTeachersHoldingProfileAndListItemWithNullListItemIsAPlainProfileLookup(): void
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $teacher = (new Teacher(new PersonName('Nombre', 'Apellido')))->setUsername('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);

        $this->persist($centre, $profile, $teacher, $assignment);

        /** @var SpecificProfileAssignmentRepository $repo */
        $repo = self::getContainer()->get(SpecificProfileAssignmentRepository::class);

        $holders = $repo->findTeachersHoldingProfileAndListItem($profile, null);

        self::assertCount(1, $holders);
        self::assertSame('docente', $holders[0]->getUsername());
    }
}
