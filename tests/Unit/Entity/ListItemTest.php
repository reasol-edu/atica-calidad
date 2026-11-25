<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
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
        self::assertNull($item->getProfileAssociations()->first()->getSubprofile());
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
        self::assertTrue($item->hasProfileAssociation($a, null));
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
        self::assertSame($a, $leaf->getEffectiveProfileAssociations()->first()->getSpecificProfile());
        $inherited = $leaf->getInheritedProfileAssociations();
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

        $effective = $leaf->getEffectiveProfileAssociations();
        self::assertCount(3, $effective, 'what the item has plus what every ancestor has');
        self::assertSame([$c, $b, $a], array_map(static fn ($x) => $x->getSpecificProfile(), $effective->getValues()), 'own first, then the closest ancestor');
        self::assertTrue($leaf->hasInheritedAssociation(), 'with its own, it still inherits');
        self::assertCount(2, $leaf->getInheritedProfileAssociations());
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

        self::assertCount(1, $leaf->getEffectiveProfileAssociations());
        self::assertSame($leaf, $leaf->getEffectiveProfileAssociations()->first()->getListItem(), 'the own one comes first');
        self::assertFalse($leaf->hasInheritedAssociation());
        self::assertSame([], $leaf->getInheritedProfileAssociations());
        $covering = $leaf->getAncestorCovering($leaf->getProfileAssociations()->first());
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

        $covering = $leaf->getAncestorCovering($leaf->getProfileAssociations()->first());
        self::assertNotNull($covering);
        self::assertFalse($covering['exact']);
        self::assertSame($root, $covering['item']);
        self::assertSame('Jefatura Área', $covering['association']->getLabel());
        self::assertNull($leaf->getAncestorCovering($leaf->getProfileAssociations()->last()), 'another department is not covered');
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

        self::assertCount(1, $leaf->getEffectiveProfileAssociations());
        self::assertSame($mid, $leaf->getInheritedProfileAssociations()[0]['item']);
    }

    public function testAnItemWithNoAssociationAnywhereHasNoEffectiveOnes(): void
    {
        $item = $this->item($this->centre());

        self::assertCount(0, $item->getEffectiveProfileAssociations());
        self::assertFalse($item->hasInheritedAssociation());
        self::assertSame([], $item->getInheritedProfileAssociations());
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
