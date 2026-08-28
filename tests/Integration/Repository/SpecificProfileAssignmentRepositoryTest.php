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
            'a "whole profile" pair (null subperfil) on a list-associated profile matches any subperfil holder',
        );
        self::assertFalse($repo->isTeacherAssignedToAny($teacher, [[$auditor, null]]));
        self::assertFalse($repo->isTeacherAssignedToAny($other, [[$tutor, $item]]));
        self::assertFalse(
            $repo->isTeacherAssignedToAny($other, [[$tutor, null]]),
            'the wildcard still only matches teachers actually assigned to one of its subperfiles',
        );
        self::assertFalse($repo->isTeacherAssignedToAny($teacher, []));
        self::assertTrue($repo->isTeacherAssignedToAny($teacher, [[$auditor, null], [$tutor, $item]]), 'any pair matching is enough');
    }
}
