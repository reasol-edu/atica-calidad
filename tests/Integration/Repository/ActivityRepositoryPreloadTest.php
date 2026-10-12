<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\SpecificProfile;
use App\Repository\ActivityRepository;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * Guards against the N+1 the callers of ActivityRepository::findAllByCentre() used to cost: they
 * read each activity's (and its folder's) profile collections, one lazy query per activity and
 * collection. The list must come with them already loaded.
 */
final class ActivityRepositoryPreloadTest extends RepositoryTestCase
{
    public function testTheCollectionsEveryCallerWalksComeAlreadyLoaded(): void
    {
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura')->setPosition(0);
        $this->persist($centre, $category, $section, $profile);

        for ($i = 1; $i <= 4; ++$i) {
            $folder = (new Folder())->setDocumentSection($section)->setName('Carpeta ' . $i);
            $folder->addUploadProfile($profile);
            $activity = (new Activity())->setCategory($category)->setTitle('Actividad ' . $i)->setStart(1, 9)->setEnd(30, 9)->setFolder($folder);
            $activity->addProfileRestriction($profile);
            $this->persist($folder, $activity);
        }
        $this->em->clear();

        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        /** @var ActivityRepository $repo */
        $repo       = self::getContainer()->get(ActivityRepository::class);
        $activities = $repo->findAllByCentre($centre);
        self::assertCount(4, $activities);
        self::assertNotEmpty($holder->getData()['default'] ?? [], 'the query log is on, so the zero below means something');
        $holder->reset();

        foreach ($activities as $activity) {
            self::assertCount(1, $activity->getProfileRestrictions());
            self::assertCount(0, $activity->getResponsibleProfiles());
            self::assertCount(0, $activity->getListItemDeadlines());
            $folder = $activity->getFolder();
            self::assertNotNull($folder);
            self::assertCount(1, $folder->getUploadProfiles());
            self::assertCount(0, $folder->getResponsibleProfiles());
            self::assertCount(0, $folder->getReviewProfiles());
            self::assertCount(0, $folder->getVisibilityProfiles());
            self::assertSame('Jefatura', $activity->getProfileRestrictions()->first()->getSpecificProfile()->getName());
        }

        self::assertSame(0, \count($holder->getData()['default'] ?? []), 'reading the collections must not query');
    }
}
