<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ListItemTest extends TestCase
{
    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro')->setCity('Ciudad');
    }

    /** Unsaved entities have no id; the association keys are built from UUIDs, so give them one. */
    private function item(EducationalCentre $centre, string $name = 'Item'): ListItem
    {
        $item = (new ListItem())->setEducationalCentre($centre)->setName($name);
        (new \ReflectionProperty(ListItem::class, 'id'))->setValue($item, Uuid::v7());

        return $item;
    }

    private function profile(EducationalCentre $centre, string $name, ?ListItem $anchor = null): SpecificProfile
    {
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName($name);
        if ($anchor !== null) {
            $profile->setListItem($anchor);
        }
        (new \ReflectionProperty(SpecificProfile::class, 'id'))->setValue($profile, Uuid::v7());

        return $profile;
    }

    public function testANameNeverHoldsTheSeparatorsOfPathsAndDeliveries(): void
    {
        self::assertSame('Física - Química', (new ListItem())->setName('Física · Química')->getName(), 'a delivery «Física · Química · 1ª» would split wrong');
        self::assertSame('Ciencias - Física', (new ListItem())->setName('Ciencias › Física')->getName(), 'a path «… › Ciencias › Física» too');
        self::assertSame('Física·Química y 1º›2º', (new ListItem())->setName('Física·Química y 1º›2º')->getName(), 'only the separators, with their spaces');
    }

    public function testSetParentRejectsCrossCentreAttachment(): void
    {
        $item   = $this->item($this->centre());
        $parent = $this->item($this->centre('87654321'));

        $this->expectException(\LogicException::class);
        $item->setParent($parent);
    }

    public function testSetParentRejectsSelfAsParent(): void
    {
        $centre = $this->centre();
        $item   = $this->item($centre);

        $this->expectException(\LogicException::class);
        $item->setParent($item);
    }

    public function testSetParentRejectsOwnDescendantAsParent(): void
    {
        $centre = $this->centre();
        $root   = $this->item($centre, 'Root');
        $child  = $this->item($centre, 'Child');
        $child->setParent($root);

        $this->expectException(\LogicException::class);
        $root->setParent($child);
    }

    public function testSetParentAcceptsSameCentreNonCyclicParent(): void
    {
        $centre = $this->centre();
        $root   = $this->item($centre, 'Root');
        $child  = $this->item($centre, 'Child');

        $child->setParent($root);

        self::assertSame($root, $child->getParent());
        self::assertFalse($child->isRoot());
        self::assertTrue($root->isRoot());
    }

    public function testSetParentNullDetachesItem(): void
    {
        $centre = $this->centre();
        $root   = $this->item($centre, 'Root');
        $child  = $this->item($centre, 'Child');
        $child->setParent($root);

        $child->setParent(null);

        self::assertNull($child->getParent());
        self::assertTrue($child->isRoot());
    }

    public function testSetAssociationRejectsListItemWithoutProfile(): void
    {
        $centre     = $this->centre();
        $item       = $this->item($centre);
        $listItem   = $this->item($centre, 'Subprofile');

        $this->expectException(\LogicException::class);
        $item->setAssociation(null, $listItem);
    }

    public function testSetAssociationRejectsProfileFromAnotherCentre(): void
    {
        $item    = $this->item($this->centre());
        $profile = (new SpecificProfile())->setEducationalCentre($this->centre('87654321'))->setName('Perfil');

        $this->expectException(\LogicException::class);
        $item->setAssociation($profile);
    }

    public function testSetAssociationRejectsListItemFromAnotherCentre(): void
    {
        $centre     = $this->centre();
        $item       = $this->item($centre);
        $profile    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $otherLeaf  = $this->item($this->centre('87654321'), 'Hoja');

        $this->expectException(\LogicException::class);
        $item->setAssociation($profile, $otherLeaf);
    }

    public function testSetAssociationWithPlainProfileNeedsNoListItem(): void
    {
        $centre  = $this->centre();
        $item    = $this->item($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');

        $item->setAssociation($profile);

        self::assertTrue($item->isAssociated());
        self::assertCount(1, $item->getProfileAssociations());
        self::assertSame($profile, $item->getProfileAssociations()->first()->getSpecificProfile());
        self::assertSame([], $item->getLinks());
    }

    public function testSetAssociationClearsWithNullProfile(): void
    {
        $centre  = $this->centre();
        $item    = $this->item($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $item->setAssociation($profile);

        $item->setAssociation(null);

        self::assertFalse($item->isAssociated());
        self::assertCount(0, $item->getProfileAssociations());
    }

    public function testAnItemCanHaveSeveralAssociationsAndNeverTheSameTwice(): void
    {
        $centre = $this->centre();
        $item   = $this->item($centre);
        $a      = (new SpecificProfile())->setEducationalCentre($centre)->setName('A');
        $b      = (new SpecificProfile())->setEducationalCentre($centre)->setName('B');

        $item->addProfileAssociation($a)->addProfileAssociation($b)->addProfileAssociation($a);

        self::assertCount(2, $item->getProfileAssociations());
        self::assertCount(2, $item->getAssociations());
    }

    public function testASubprofileOfAProfileBecomesALinkToThatElementAndDeclaresItsList(): void
    {
        $centre = $this->centre();
        $depts  = $this->item($centre, 'Departamento');
        $fp     = $this->item($centre, 'FP')->setParent($depts);
        $infor  = $this->item($centre, 'Informática')->setParent($fp);
        $jefe   = $this->profile($centre, 'Jefatura', $depts);
        $subjects = $this->item($centre, 'Materia');
        $subject  = $this->item($centre, 'Programación')->setParent($subjects);

        $subject->addProfileAssociation($jefe, $infor)->addProfileAssociation($jefe, $infor);

        self::assertSame([$infor], $subject->getLinks(), 'once');
        self::assertCount(0, $subject->getProfileAssociations());
        self::assertSame([$depts], $subjects->getLinkedLists(), 'the list of the subprofile is now associated with «Materia»');
        self::assertTrue($subject->hasLinkedList($fp), 'any node of a list stands for the list');
        self::assertSame('Departamento › FP › Informática', $subject->getAssociations()[0]->label());
    }

    public function testASubprofileOutsideTheProfilesListIsRefused(): void
    {
        $centre = $this->centre();
        $depts  = $this->item($centre, 'Departamento');
        $other  = $this->item($centre, 'Otra');
        $jefe   = $this->profile($centre, 'Jefatura', $depts);

        $this->expectException(\LogicException::class);
        $this->item($centre)->addProfileAssociation($jefe, $other);
    }

    public function testALinkNeedsItsListToBeAssociated(): void
    {
        $centre = $this->centre();
        $evals  = $this->item($centre, 'Evaluación');
        $first  = $this->item($centre, '1ª')->setParent($evals);
        $subject = $this->item($centre, 'Programación')->setParent($this->item($centre, 'Materia'));

        try {
            $subject->addLink($first);
            self::fail('a list that is not associated cannot be linked');
        } catch (\LogicException) {
        }

        $subject->addLinkedList($evals)->addLink($first);
        self::assertSame([$first], $subject->getLinks());

        $subject->clearLinks($evals);
        self::assertSame([], $subject->getLinks());
    }

    public function testLinksAccumulateAndCoverTheLeavesOfTheirList(): void
    {
        $centre  = $this->centre();
        $evals   = $this->item($centre, 'Evaluación');
        $fp      = $this->item($centre, 'FP')->setParent($evals);
        $first   = $this->item($centre, '1ª')->setParent($fp);
        $final   = $this->item($centre, 'Final 1')->setParent($fp);
        $subjects = $this->item($centre, 'Materia')->addLinkedList($evals);
        $course  = $this->item($centre, '1º DAM')->setParent($subjects);
        $subject = $this->item($centre, 'Programación')->setParent($course);
        $course->addLink($fp);

        self::assertSame([], $subject->getLinks());
        self::assertSame('l:' . $fp->identityKey(), $subject->getEffectiveAssociations()[0]->key());
        $leaves = $subject->linkedLeafIdsIn($evals, static fn (ListItem $node): array => $node === $fp ? ['first', 'final'] : []);
        self::assertSame(['first' => true, 'final' => true], $leaves);
        self::assertSame($course, $subject->getInheritedAssociations()[0]['item']);
        unset($first, $final);
    }

    public function testAListAssociatedProfileMustBeAssociatedThroughASubprofile(): void
    {
        $centre  = $this->centre();
        $anchor  = $this->item($centre, 'Ancla');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($anchor);

        $this->expectException(\LogicException::class);
        $this->item($centre)->addProfileAssociation($profile);
    }

    public function testAnItemWithoutAssociationsInheritsTheOnesOfItsAncestors(): void
    {
        $centre  = $this->centre();
        $a       = $this->profile($centre, 'A');
        $root    = $this->item($centre, 'Raíz');
        $mid     = $this->item($centre, 'Medio')->setParent($root);
        $leaf    = $this->item($centre, 'Hoja')->setParent($mid);
        $root->addProfileAssociation($a);

        self::assertTrue($leaf->hasInheritedAssociation());
        self::assertSame($a, $leaf->getEffectiveAssociations()[0]->profile);
        $inherited = $leaf->getInheritedAssociations();
        self::assertCount(1, $inherited);
        self::assertSame($root, $inherited[0]['item']);
    }

    public function testAssociationsAccumulateDownTheBranchOwnOnesFirst(): void
    {
        $centre = $this->centre();
        $a      = $this->profile($centre, 'A');
        $b      = $this->profile($centre, 'B');
        $c      = $this->profile($centre, 'C');
        $root   = $this->item($centre, 'Raíz');
        $mid    = $this->item($centre, 'Medio')->setParent($root);
        $leaf   = $this->item($centre, 'Hoja')->setParent($mid);
        $root->addProfileAssociation($a);
        $mid->addProfileAssociation($b);
        $leaf->addProfileAssociation($c);

        $effective = $leaf->getEffectiveAssociations();
        self::assertCount(3, $effective, 'what the item has plus what every ancestor has');
        self::assertSame([$c, $b, $a], array_map(static fn ($x) => $x->profile, $effective), 'own first, then the closest ancestor');
        self::assertTrue($leaf->hasInheritedAssociation(), 'with its own, it still inherits');
        self::assertCount(2, $leaf->getInheritedAssociations());
        self::assertTrue($leaf->isAssociated());
    }

    public function testAnOwnAssociationAlsoInheritedCountsOnceAndIsNotListedAsInherited(): void
    {
        $centre = $this->centre();
        $a      = $this->profile($centre, 'A');
        $root   = $this->item($centre, 'Raíz');
        $leaf   = $this->item($centre, 'Hoja')->setParent($root);
        $root->addProfileAssociation($a);
        $leaf->addProfileAssociation($a);

        self::assertCount(1, $leaf->getEffectiveAssociations());
        self::assertFalse($leaf->hasInheritedAssociation());
        self::assertSame([], $leaf->getInheritedAssociations());
        $covering = $leaf->getAncestorCovering($leaf->getAssociations()[0]);
        self::assertNotNull($covering);
        self::assertSame($root, $covering['item']);
        self::assertTrue($covering['exact']);
    }

    public function testAnOwnSubprofileUnderAnInheritedIntermediateOneIsCoveredNotExact(): void
    {
        $centre = $this->centre();
        $anchor = $this->item($centre, 'Departamentos');
        $area   = $this->item($centre, 'Área')->setParent($anchor);
        $dept   = $this->item($centre, 'Informática')->setParent($area);
        $other  = $this->item($centre, 'Lengua')->setParent($anchor);
        $jefe   = $this->profile($centre, 'Jefatura', $anchor);
        $root   = $this->item($centre, 'Raíz');
        $leaf   = $this->item($centre, 'Hoja')->setParent($root);
        $root->addProfileAssociation($jefe, $area);
        $leaf->addProfileAssociation($jefe, $dept);
        $leaf->addProfileAssociation($jefe, $other);

        $own = $leaf->getAssociations();
        $covering = $leaf->getAncestorCovering($own[0]);
        self::assertNotNull($covering);
        self::assertFalse($covering['exact']);
        self::assertSame($root, $covering['item']);
        self::assertSame('Departamentos › Área', $covering['association']->label());
        self::assertNull($leaf->getAncestorCovering($own[1]), 'another department is not covered');
    }

    public function testTheSamePairInTwoAncestorsIsListedOnceFromTheClosest(): void
    {
        $centre = $this->centre();
        $a      = $this->profile($centre, 'A');
        $root   = $this->item($centre, 'Raíz');
        $mid    = $this->item($centre, 'Medio')->setParent($root);
        $leaf   = $this->item($centre, 'Hoja')->setParent($mid);
        $root->addProfileAssociation($a);
        $mid->addProfileAssociation($a);

        self::assertCount(1, $leaf->getEffectiveAssociations());
        self::assertSame($mid, $leaf->getInheritedAssociations()[0]['item']);
    }

    public function testAnItemWithNoAssociationAnywhereHasNoEffectiveOnes(): void
    {
        $item = $this->item($this->centre());

        self::assertCount(0, $item->getEffectiveAssociations());
        self::assertFalse($item->hasInheritedAssociation());
        self::assertSame([], $item->getInheritedAssociations());
    }

    private function teacher(string $first): Teacher
    {
        $teacher = (new Teacher(new PersonName($first, 'Docente')))->setUsername(strtolower($first));
        (new \ReflectionProperty(Teacher::class, 'id'))->setValue($teacher, Uuid::v7());

        return $teacher;
    }

    public function testTeachersAddRemoveAndClearWithoutRepeating(): void
    {
        $item  = $this->item($this->centre());
        $noelia = $this->teacher('Noelia');
        $marcos = $this->teacher('Marcos');

        $item->addTeacher($noelia)->addTeacher($marcos)->addTeacher($noelia);
        self::assertSame([$noelia, $marcos], $item->getTeachers());
        self::assertTrue($item->hasTeacher($marcos));

        $item->removeTeacher($noelia);
        self::assertSame([$marcos], $item->getTeachers());

        $item->clearTeachers();
        self::assertSame([], $item->getTeachers());
    }

    public function testTeachersAccumulateAlongTheBranchWithoutRepeating(): void
    {
        $centre = $this->centre();
        $root   = $this->item($centre, 'Materia');
        $mid    = $this->item($centre, 'Programación')->setParent($root);
        $leaf   = $this->item($centre, '1º DAM')->setParent($mid);
        $noelia = $this->teacher('Noelia');
        $marcos = $this->teacher('Marcos');
        $rocio  = $this->teacher('Rocio');

        $root->addTeacher($rocio);
        $mid->addTeacher($noelia)->addTeacher($rocio);
        $leaf->addTeacher($marcos)->addTeacher($noelia);

        // Own first, then the closest ancestor's, then the farthest; Noelia and Rocío once, wherever they are repeated.
        self::assertSame([$marcos, $noelia, $rocio], $leaf->getEffectiveTeachers());
        self::assertSame([$marcos, $noelia], $leaf->getTeachers(), 'the own ones are only the own ones');

        $inherited = $leaf->getInheritedTeachers();
        self::assertCount(1, $inherited);
        self::assertSame($rocio, $inherited[0]['teacher']);
        self::assertSame($mid, $inherited[0]['item'], 'the closest ancestor that has them');

        self::assertSame([$noelia, $rocio], $mid->getEffectiveTeachers());
        self::assertSame([$rocio], $root->getEffectiveTeachers());
    }

    public function testEffectiveTeachersSurviveACorruptedParentLoop(): void
    {
        $centre = $this->centre();
        $a      = $this->item($centre, 'A');
        $b      = $this->item($centre, 'B')->setParent($a);
        // setParent() refuses a loop: corrupt the data by reflection, as the profile associations' test does.
        (new \ReflectionProperty(ListItem::class, 'parent'))->setValue($a, $b);
        $noelia = $this->teacher('Noelia');
        $a->addTeacher($noelia);

        self::assertSame([$noelia], $b->getEffectiveTeachers());
        self::assertSame([$noelia], $a->getEffectiveTeachers());
    }

    /**
     * $children is the inverse side of the parent/child ManyToOne — Doctrine only populates it
     * from a flush+refresh, never from calling setParent() on the owning side in memory (same
     * caveat as DocumentRevision's inverse $revisions collection). Add to it directly to test
     * isLeaf() itself without depending on ORM hydration.
     */
    public function testIsLeafReflectsChildren(): void
    {
        $centre = $this->centre();
        $root   = $this->item($centre, 'Root');
        self::assertTrue($root->isLeaf());

        $child = $this->item($centre, 'Child');
        $child->setParent($root);
        /** @var \Doctrine\Common\Collections\Collection<int, ListItem> $children */
        $children = (new \ReflectionProperty(ListItem::class, 'children'))->getValue($root);
        $children->add($child);

        self::assertFalse($root->isLeaf());
        self::assertTrue($child->isLeaf());
    }
}
