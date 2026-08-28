<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\EducationalCentre;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Repository\SpecificProfileRepository;
use App\Tests\Integration\RepositoryTestCase;

final class SpecificProfileRepositoryTest extends RepositoryTestCase
{
    public function testIsListItemInUse(): void
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $item    = (new ListItem())->setEducationalCentre($centre)->setName('Grupo')->setPosition(0);
        $unused  = (new ListItem())->setEducationalCentre($centre)->setName('Sin usar')->setPosition(1);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a')->setPosition(0);
        $profile->setListItem($item);

        $this->persist($centre, $item, $unused, $profile);

        /** @var SpecificProfileRepository $repo */
        $repo = $this->em->getRepository(SpecificProfile::class);

        self::assertTrue($repo->isListItemInUse($item));
        self::assertFalse($repo->isListItemInUse($unused));
    }
}
