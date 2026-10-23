<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use PHPUnit\Framework\TestCase;

final class ListItemTest extends TestCase
{
    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro')->setCity('Ciudad');
    }

    private function item(EducationalCentre $centre, string $name = 'Item'): ListItem
    {
        return (new ListItem())->setEducationalCentre($centre)->setName($name);
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

    public function testAnItemWithoutAssociationsInheritsTheOnesOfItsClosestAssociatedAncestor(): void
    {
        $centre  = $this->centre();
        $a       = (new SpecificProfile())->setEducationalCentre($centre)->setName('A');
        $b       = (new SpecificProfile())->setEducationalCentre($centre)->setName('B');
        $root    = $this->item($centre, 'Raíz');
        $mid     = $this->item($centre, 'Medio')->setParent($root);
        $leaf    = $this->item($centre, 'Hoja')->setParent($mid);
        $root->addProfileAssociation($a);

        self::assertSame($root, $leaf->getAssociationSource());
        self::assertTrue($leaf->hasInheritedAssociation());
        self::assertSame($a, $leaf->getEffectiveProfileAssociations()->first()->getSpecificProfile());

        $mid->addProfileAssociation($b);
        self::assertSame($mid, $leaf->getAssociationSource(), 'the closest ancestor wins');
        self::assertCount(1, $leaf->getEffectiveProfileAssociations(), 'its associations replace the farther ones, they are not added to them');

        $leaf->addProfileAssociation($a);
        self::assertSame($leaf, $leaf->getAssociationSource(), 'with its own, nothing is inherited');
        self::assertFalse($leaf->hasInheritedAssociation());
    }

    public function testAnItemWithNoAssociationAnywhereHasNoEffectiveOnes(): void
    {
        $item = $this->item($this->centre());

        self::assertNull($item->getAssociationSource());
        self::assertCount(0, $item->getEffectiveProfileAssociations());
        self::assertFalse($item->hasInheritedAssociation());
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
