<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use PHPUnit\Framework\TestCase;

final class SpecificProfileTest extends TestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function profile(EducationalCentre $centre): SpecificProfile
    {
        return (new SpecificProfile())->setName('Tutor/a')->setEducationalCentre($centre);
    }

    private function teacher(): Teacher
    {
        return (new Teacher(new PersonName('Ana', 'García')))->setUsername('agarcia');
    }

    public function testIsListAssociatedReflectsWhetherAListItemIsSet(): void
    {
        $centre  = $this->centre();
        $profile = $this->profile($centre);

        self::assertFalse($profile->isListAssociated());

        $listItem = (new ListItem())->setName('Raíz')->setEducationalCentre($centre);
        $profile->setListItem($listItem);

        self::assertTrue($profile->isListAssociated());
    }

    public function testSetListItemRejectsAnItemFromAnotherCentre(): void
    {
        $profile        = $this->profile($this->centre());
        $foreignListItem = (new ListItem())->setName('Raíz')->setEducationalCentre($this->centre());

        $this->expectException(\LogicException::class);
        $profile->setListItem($foreignListItem);
    }

    public function testAddAssignmentIsIdempotentForTheSameTeacherAndListItem(): void
    {
        $centre  = $this->centre();
        $profile = $this->profile($centre);
        $teacher = $this->teacher();

        $profile->addAssignment($teacher);
        $profile->addAssignment($teacher);

        self::assertCount(1, $profile->getAssignments());
    }

    public function testAddAssignmentAllowsTheSameTeacherOnDifferentListItems(): void
    {
        $centre  = $this->centre();
        $profile = $this->profile($centre);
        $teacher = $this->teacher();
        $itemA   = (new ListItem())->setName('A')->setEducationalCentre($centre);
        $itemB   = (new ListItem())->setName('B')->setEducationalCentre($centre);

        $profile->addAssignment($teacher, $itemA);
        $profile->addAssignment($teacher, $itemB);

        self::assertCount(2, $profile->getAssignments());
    }

    public function testHasAssignmentReflectsWhatWasAdded(): void
    {
        $centre  = $this->centre();
        $profile = $this->profile($centre);
        $teacher = $this->teacher();

        self::assertFalse($profile->hasAssignment($teacher, null));

        $profile->addAssignment($teacher);

        self::assertTrue($profile->hasAssignment($teacher, null));
    }

    public function testRemoveAssignmentRemovesIt(): void
    {
        $centre  = $this->centre();
        $profile = $this->profile($centre);
        $teacher = $this->teacher();
        $profile->addAssignment($teacher);
        $assignment = $profile->getAssignments()->first();
        self::assertNotFalse($assignment);

        $profile->removeAssignment($assignment);

        self::assertCount(0, $profile->getAssignments());
    }
}
