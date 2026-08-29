<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\SpecificProfile;
use PHPUnit\Framework\TestCase;

final class DocumentSectionTest extends TestCase
{
    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro')->setCity('Ciudad');
    }

    private function section(EducationalCentre $centre, string $name = 'Sección'): DocumentSection
    {
        return (new DocumentSection())->setEducationalCentre($centre)->setName($name);
    }

    public function testSetParentRejectsCrossCentreAttachment(): void
    {
        $section = $this->section($this->centre());
        $parent  = $this->section($this->centre('87654321'));

        $this->expectException(\LogicException::class);
        $section->setParent($parent);
    }

    public function testSetParentRejectsSelfAsParent(): void
    {
        $section = $this->section($this->centre());

        $this->expectException(\LogicException::class);
        $section->setParent($section);
    }

    public function testSetParentRejectsOwnDescendantAsParent(): void
    {
        $centre = $this->centre();
        $root   = $this->section($centre, 'Root');
        $child  = $this->section($centre, 'Child');
        $child->setParent($root);

        $this->expectException(\LogicException::class);
        $root->setParent($child);
    }

    public function testSetParentAcceptsSameCentreNonCyclicParent(): void
    {
        $centre = $this->centre();
        $root   = $this->section($centre, 'Root');
        $child  = $this->section($centre, 'Child');

        $child->setParent($root);

        self::assertSame($root, $child->getParent());
        self::assertFalse($child->isRoot());
        self::assertTrue($root->isRoot());
    }

    public function testSetParentNullDetachesSection(): void
    {
        $centre = $this->centre();
        $root   = $this->section($centre, 'Root');
        $child  = $this->section($centre, 'Child');
        $child->setParent($root);

        $child->setParent(null);

        self::assertNull($child->getParent());
        self::assertTrue($child->isRoot());
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
        $root   = $this->section($centre, 'Root');
        self::assertTrue($root->isLeaf());

        $child = $this->section($centre, 'Child');
        $child->setParent($root);
        /** @var \Doctrine\Common\Collections\Collection<int, DocumentSection> $children */
        $children = (new \ReflectionProperty(DocumentSection::class, 'children'))->getValue($root);
        $children->add($child);

        self::assertFalse($root->isLeaf());
        self::assertTrue($child->isLeaf());
    }

    public function testAddProfileRestrictionDoesNotDuplicateSamePair(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');

        self::assertFalse($section->isRestricted());

        $section->addProfileRestriction($profile);
        $section->addProfileRestriction($profile);

        self::assertCount(1, $section->getProfileRestrictions());
        self::assertTrue($section->isRestricted());
    }

    public function testAddProfileRestrictionTreatsDifferentListItemAsDistinct(): void
    {
        $centre    = $this->centre();
        $section   = $this->section($centre);
        $profile   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $listItemA = (new \App\Entity\ListItem())->setEducationalCentre($centre)->setName('A');
        $listItemB = (new \App\Entity\ListItem())->setEducationalCentre($centre)->setName('B');

        $section->addProfileRestriction($profile, $listItemA);
        $section->addProfileRestriction($profile, $listItemB);

        self::assertCount(2, $section->getProfileRestrictions());
        self::assertTrue($section->hasProfileRestriction($profile, $listItemA));
        self::assertTrue($section->hasProfileRestriction($profile, $listItemB));
        self::assertFalse($section->hasProfileRestriction($profile, null));
    }
}
