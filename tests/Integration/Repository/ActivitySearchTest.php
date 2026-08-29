<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Repository\ActivityCategoryRepository;
use App\Repository\ActivityRepository;
use App\Tests\Integration\RepositoryTestCase;

final class ActivitySearchTest extends RepositoryTestCase
{
    private function centre(string $code = '12345678'): EducationalCentre
    {
        return (new EducationalCentre())->setCode($code)->setName('Centro')->setCity('Ciudad');
    }

    private function category(EducationalCentre $centre, string $name = 'Categoría'): ActivityCategory
    {
        return (new ActivityCategory())->setEducationalCentre($centre)->setName($name);
    }

    private function activity(ActivityCategory $category, string $title): Activity
    {
        return (new Activity())->setCategory($category)->setTitle($title)->setStart(1, 9)->setEnd(30, 6);
    }

    public function testActivityCategoryRepositorySearchByCentreMatchesNameCaseInsensitively(): void
    {
        $centre  = $this->centre();
        $matching    = $this->category($centre, 'Programaciones didácticas');
        $notMatching = $this->category($centre, 'Otra cosa');
        $this->persist($centre, $matching, $notMatching);

        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);

        $results = $categories->searchByCentre($centre, 'programaciones');

        self::assertCount(1, $results);
        self::assertSame('Programaciones didácticas', $results[0]->getName());
    }

    public function testActivityCategoryRepositorySearchByCentreIsScopedToTheCentre(): void
    {
        $centreA = $this->centre('11111111');
        $centreB = $this->centre('22222222');
        $inA     = $this->category($centreA, 'Compartido');
        $inB     = $this->category($centreB, 'Compartido');
        $this->persist($centreA, $centreB, $inA, $inB);

        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);

        $results = $categories->searchByCentre($centreA, 'Compartido');

        self::assertCount(1, $results);
        self::assertSame($inA->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testActivityCategoryRepositorySearchByCentreMatchesAnyDepthOfTheTree(): void
    {
        $centre = $this->centre();
        $root   = $this->category($centre, 'Raíz');
        $child  = $this->category($centre, 'Programaciones didácticas');
        $child->setParent($root);
        $this->persist($centre, $root, $child);

        /** @var ActivityCategoryRepository $categories */
        $categories = self::getContainer()->get(ActivityCategoryRepository::class);

        $results = $categories->searchByCentre($centre, 'didácticas');

        self::assertCount(1, $results);
        self::assertSame('Programaciones didácticas', $results[0]->getName());
    }

    public function testActivityRepositorySearchByCentreMatchesTitleCaseInsensitively(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $matching    = $this->activity($category, 'Programaciones didácticas');
        $notMatching = $this->activity($category, 'Actas del ETCP');
        $this->persist($centre, $category, $matching, $notMatching);

        /** @var ActivityRepository $activities */
        $activities = self::getContainer()->get(ActivityRepository::class);

        $results = $activities->searchByCentre($centre, 'PROGRAMACIONES');

        self::assertCount(1, $results);
        self::assertSame('Programaciones didácticas', $results[0]->getTitle());
    }

    public function testActivityRepositorySearchByCentreIsScopedToTheCentre(): void
    {
        $centreA   = $this->centre('11111111');
        $centreB   = $this->centre('22222222');
        $categoryA = $this->category($centreA);
        $categoryB = $this->category($centreB);
        $inA       = $this->activity($categoryA, 'Entrega compartida');
        $inB       = $this->activity($categoryB, 'Entrega compartida');
        $this->persist($centreA, $centreB, $categoryA, $categoryB, $inA, $inB);

        /** @var ActivityRepository $activities */
        $activities = self::getContainer()->get(ActivityRepository::class);

        $results = $activities->searchByCentre($centreA, 'Entrega');

        self::assertCount(1, $results);
        self::assertSame($inA->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testFindAllByCentreReturnsActivitiesAcrossEveryCategory(): void
    {
        $centre     = $this->centre();
        $categoryA  = $this->category($centre, 'Categoría A');
        $categoryB  = $this->category($centre, 'Categoría B');
        $activityA  = $this->activity($categoryA, 'Actividad A');
        $activityB  = $this->activity($categoryB, 'Actividad B');
        $this->persist($centre, $categoryA, $categoryB, $activityA, $activityB);

        /** @var ActivityRepository $activities */
        $activities = self::getContainer()->get(ActivityRepository::class);

        $results = $activities->findAllByCentre($centre);

        self::assertCount(2, $results);
    }

    public function testFindAllByCentreIsScopedToTheCentre(): void
    {
        $centreA   = $this->centre('11111111');
        $centreB   = $this->centre('22222222');
        $categoryA = $this->category($centreA);
        $categoryB = $this->category($centreB);
        $inA       = $this->activity($categoryA, 'De A');
        $inB       = $this->activity($categoryB, 'De B');
        $this->persist($centreA, $centreB, $categoryA, $categoryB, $inA, $inB);

        /** @var ActivityRepository $activities */
        $activities = self::getContainer()->get(ActivityRepository::class);

        $results = $activities->findAllByCentre($centreA);

        self::assertCount(1, $results);
        self::assertSame($inA->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testActivityRepositorySearchByCentreRespectsTheLimit(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $entities = [$centre, $category];
        for ($i = 0; $i < 5; ++$i) {
            $entities[] = $this->activity($category, "Actividad {$i}");
        }
        $this->persist(...$entities);

        /** @var ActivityRepository $activities */
        $activities = self::getContainer()->get(ActivityRepository::class);

        $results = $activities->searchByCentre($centre, 'Actividad', 3);

        self::assertCount(3, $results);
    }
}
