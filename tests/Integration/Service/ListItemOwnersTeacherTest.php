<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Service\Form\FormListOptions;
use App\Service\ListItemOwners;
use App\Tests\Integration\RepositoryTestCase;

/**
 * «Solo los elementos asociados a quien entrega» in a document of ONE teacher (an individual delivery): the elements that teacher is
 * associated with, own or inherited, count as theirs — the subjects they teach — besides the associations with their profile.
 */
final class ListItemOwnersTeacherTest extends RepositoryTestCase
{
    public function testTheElementsATeacherTeachesBelongToTheirOwnDocument(): void
    {
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $noelia   = (new Teacher(new PersonName('Noelia', 'Bravo')))->setUsername('n.bravo');
        $marcos   = (new Teacher(new PersonName('Marcos', 'Pardo')))->setUsername('m.pardo');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Profesorado');
        $subjects = (new ListItem())->setEducationalCentre($centre)->setName('Materia');
        $dam      = (new ListItem())->setEducationalCentre($centre)->setName('1º DAM')->setParent($subjects)->addTeacher($marcos);
        $prog     = (new ListItem())->setEducationalCentre($centre)->setName('Programación')->setParent($dam)->addTeacher($noelia);
        $bd       = (new ListItem())->setEducationalCentre($centre)->setName('Bases de datos')->setParent($dam)->setPosition(1);
        $this->persist($centre, $noelia, $marcos, $profile, $subjects, $dam, $prog, $bd);

        $index = [];
        foreach ([$subjects, $dam, $prog, $bd] as $item) {
            $index[$item->getId()->toRfc4122()] = $item;
        }
        $keyOf = static fn (Teacher $t): string => implode(':', [$profile->getId()->toRfc4122(), '', '', $t->getId()->toRfc4122()]);

        $noeliaDoc = ActivitySubmissionSlot::deliveredBy($keyOf($noelia));
        self::assertNotNull($noeliaDoc);
        self::assertSame($noelia->getId()->toRfc4122(), $noeliaDoc['teacher'] ?? null, 'the key says whose document it is');
        self::assertTrue(ListItemOwners::belongsTo($prog, $noeliaDoc, $index), 'she teaches Programación');
        self::assertFalse(ListItemOwners::belongsTo($bd, $noeliaDoc, $index));

        $marcosDoc = ActivitySubmissionSlot::deliveredBy($keyOf($marcos));
        self::assertNotNull($marcosDoc);
        self::assertTrue(ListItemOwners::belongsTo($bd, $marcosDoc, $index), 'he is on the whole group: inherited');
        self::assertTrue(ListItemOwners::belongsTo($prog, $marcosDoc, $index));

        // A table of the list in her document: only her subject (the same rule).
        $leaves = array_map(static fn (ListItem $i): string => $i->getName(), array_values(array_filter([$prog, $bd], static fn (ListItem $i): bool => FormListOptions::belongsToSlot($i, $noeliaDoc, $index))));
        self::assertSame(['Programación'], $leaves);

        // A document shared by a profile (no teacher in its key) keeps the old rule: no association with «Profesorado», nothing.
        $shared = ActivitySubmissionSlot::deliveredBy(implode(':', [$profile->getId()->toRfc4122(), '', '', '']));
        self::assertNotNull($shared);
        self::assertFalse(ListItemOwners::belongsTo($prog, $shared, $index));
    }
}
