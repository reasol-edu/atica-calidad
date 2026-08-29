<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SchoolEvent;
use App\Entity\SpecificProfile;
use PHPUnit\Framework\TestCase;

final class SchoolEventTest extends TestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function event(): SchoolEvent
    {
        return new SchoolEvent();
    }

    public function testAddProfileRestrictionDoesNotDuplicateTheSamePair(): void
    {
        $centre  = $this->centre();
        $event   = $this->event();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');

        self::assertFalse($event->hasProfileRestriction($profile, null));

        $event->addProfileRestriction($profile);
        $event->addProfileRestriction($profile);

        self::assertCount(1, $event->getProfileRestrictions());
        self::assertTrue($event->hasProfileRestriction($profile, null));
    }

    public function testAddProfileRestrictionTreatsDifferentListItemAsDistinct(): void
    {
        $centre    = $this->centre();
        $event     = $this->event();
        $profile   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $listItemA = (new ListItem())->setEducationalCentre($centre)->setName('A');
        $listItemB = (new ListItem())->setEducationalCentre($centre)->setName('B');

        $event->addProfileRestriction($profile, $listItemA);
        $event->addProfileRestriction($profile, $listItemB);

        self::assertCount(2, $event->getProfileRestrictions());
        self::assertTrue($event->hasProfileRestriction($profile, $listItemA));
        self::assertTrue($event->hasProfileRestriction($profile, $listItemB));
        self::assertFalse($event->hasProfileRestriction($profile, null));
    }

    public function testRemoveProfileRestrictionRemovesIt(): void
    {
        $centre  = $this->centre();
        $event   = $this->event();
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $event->addProfileRestriction($profile);
        $restriction = $event->getProfileRestrictions()->first();
        self::assertNotFalse($restriction);

        $event->removeProfileRestriction($restriction);

        self::assertCount(0, $event->getProfileRestrictions());
    }
}
