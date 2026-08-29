<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use PHPUnit\Framework\TestCase;

final class ActivityCategoryTest extends TestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function category(EducationalCentre $centre, string $name = 'Categoría'): ActivityCategory
    {
        return (new ActivityCategory())->setName($name)->setEducationalCentre($centre);
    }

    public function testARootCategoryHasNoParent(): void
    {
        $category = $this->category($this->centre());

        self::assertTrue($category->isRoot());
        self::assertNull($category->getParent());
    }

    public function testSettingAParentMakesItNoLongerARoot(): void
    {
        $centre = $this->centre();
        $parent = $this->category($centre, 'Padre');
        $child  = $this->category($centre, 'Hijo');

        $child->setParent($parent);

        self::assertFalse($child->isRoot());
        self::assertSame($parent, $child->getParent());
    }

    /**
     * $children is the inverse side of the parent/child ManyToOne — Doctrine only populates it
     * from a flush+refresh, never from calling setParent() on the owning side in memory. Add to
     * it directly to test isLeaf() itself without depending on ORM hydration.
     */
    public function testIsLeafReflectsWhetherItHasChildren(): void
    {
        $centre = $this->centre();
        $parent = $this->category($centre, 'Padre');
        $child  = $this->category($centre, 'Hijo');

        self::assertTrue($parent->isLeaf());

        $child->setParent($parent);
        /** @var \Doctrine\Common\Collections\Collection<int, ActivityCategory> $children */
        $children = (new \ReflectionProperty(ActivityCategory::class, 'children'))->getValue($parent);
        $children->add($child);

        self::assertFalse($parent->isLeaf());
        self::assertTrue($child->isLeaf());
    }

    public function testSetParentRejectsACategoryFromAnotherCentre(): void
    {
        $category      = $this->category($this->centre());
        $foreignParent = $this->category($this->centre());

        $this->expectException(\LogicException::class);
        $category->setParent($foreignParent);
    }

    public function testSetParentRejectsBecomingItsOwnParent(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);

        $this->expectException(\LogicException::class);
        $category->setParent($category);
    }

    public function testSetParentRejectsBecomingADescendantOfItself(): void
    {
        $centre = $this->centre();
        $root   = $this->category($centre, 'Raíz');
        $child  = $this->category($centre, 'Hijo');
        $child->setParent($root);

        $this->expectException(\LogicException::class);
        $root->setParent($child);
    }

    public function testSetParentToNullMakesItARootAgain(): void
    {
        $centre = $this->centre();
        $parent = $this->category($centre, 'Padre');
        $child  = $this->category($centre, 'Hijo');
        $child->setParent($parent);

        $child->setParent(null);

        self::assertTrue($child->isRoot());
    }
}
