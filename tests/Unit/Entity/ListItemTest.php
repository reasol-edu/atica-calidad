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
        $listItem   = $this->item($centre, 'Subperfil');

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
        self::assertSame($profile, $item->getAssociatedProfile());
        self::assertNull($item->getAssociatedProfileListItem());
    }

    public function testSetAssociationClearsWithNullProfile(): void
    {
        $centre  = $this->centre();
        $item    = $this->item($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $item->setAssociation($profile);

        $item->setAssociation(null);

        self::assertFalse($item->isAssociated());
        self::assertNull($item->getAssociatedProfile());
    }

    /**
     * A list-associated profile's own subprofile leaf is nulled independently on ITS OWN deletion
     * (ON DELETE SET NULL) without touching $associatedProfile — leaving "associated with subprofile
     * X of profile Y" looking like "associated with the whole of profile Y", a state
     * setAssociation() itself never allows. The getters must treat that inconsistent state as no
     * association at all rather than silently upgrading it.
     */
    public function testInconsistentAssociationIsTreatedAsNone(): void
    {
        $centre       = $this->centre();
        $listAnchor   = $this->item($centre, 'Ancla');
        $profile      = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setListItem($listAnchor);
        $subperfil    = $this->item($centre, 'Subperfil leaf');
        $item         = $this->item($centre);

        $item->setAssociation($profile, $subperfil);
        self::assertSame($profile, $item->getAssociatedProfile());

        // Simulate the leaf being deleted out from under the association (ON DELETE SET NULL) by
        // reflecting the private property directly — there's no public API that produces this
        // state, which is exactly the point.
        $ref = new \ReflectionProperty(ListItem::class, 'associatedProfileListItem');
        $ref->setValue($item, null);

        self::assertNull($item->getAssociatedProfile());
        self::assertNull($item->getAssociatedProfileListItem());
        self::assertFalse($item->isAssociated());
    }

    public function testGetEffectiveTagsIncludesOwnAndAncestorTags(): void
    {
        $centre = $this->centre();
        $root   = $this->item($centre, 'Root');
        $mid    = $this->item($centre, 'Mid');
        $leaf   = $this->item($centre, 'Leaf');
        $mid->setParent($root);
        $leaf->setParent($mid);

        $rootTag = new \App\Entity\Tag();
        $rootTag->setName('RootTag')->setEducationalCentre($centre);
        $leafTag = new \App\Entity\Tag();
        $leafTag->setName('LeafTag')->setEducationalCentre($centre);

        $root->addTag($rootTag);
        $leaf->addTag($leafTag);

        $effective = $leaf->getEffectiveTags();

        self::assertTrue($effective->contains($rootTag));
        self::assertTrue($effective->contains($leafTag));
        self::assertCount(2, $effective);
        // The leaf's own tags() collection must not have grown from the inherited lookup.
        self::assertCount(1, $leaf->getTags());
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
