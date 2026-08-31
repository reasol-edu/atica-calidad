<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\ActivitySubmissionScope;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Model\ActivityDashboardStatus;
use App\Repository\ActivityRepository;
use App\Service\ActivityCompletionChecker;
use App\Service\ActivityDeadlineChecker;
use App\Service\MyActivitiesFinder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class MyActivitiesFinderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private MyActivitiesFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();

        // Mirrors ActivityDashboardSummaryBuilderTest: built directly from its own real,
        // container-provided dependencies rather than fetched by class name, since a service only
        // ever consumed by one Live Component can get inlined into the compiled test container.
        $this->finder = new MyActivitiesFinder(
            self::getContainer()->get(ActivityRepository::class),
            self::getContainer()->get(ActivityCompletionChecker::class),
            new ActivityDeadlineChecker(self::getContainer()->get('clock')),
        );
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function category(EducationalCentre $centre, string $name = 'Categoría'): ActivityCategory
    {
        return (new ActivityCategory())->setEducationalCentre($centre)->setName($name);
    }

    private function activity(ActivityCategory $category, string $title = 'Actividad'): Activity
    {
        return (new Activity())->setCategory($category)->setTitle($title)->setStart(1, 9)->setEnd(30, 9);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testACompletedObligationIsIncludedWithCompletedStatus(): void
    {
        self::mockTime('2025-09-15 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher));

        $items = $this->finder->forTeacher($teacher, $centre);

        self::assertCount(1, $items, 'unlike ActivityDashboardSummaryBuilder, completed obligations must appear as items here, not just be counted');
        self::assertSame(ActivityDashboardStatus::Completed, $items[0]->status);
    }

    public function testAnUncompletedActivityIsPendingBeforeItsDeadline(): void
    {
        self::mockTime('2025-09-15 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $items = $this->finder->forTeacher($teacher, $centre);

        self::assertCount(1, $items);
        self::assertSame(ActivityDashboardStatus::Pending, $items[0]->status);
    }

    public function testAnUncompletedActivityIsOverdueAfterItsDeadline(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $items = $this->finder->forTeacher($teacher, $centre);

        self::assertCount(1, $items);
        self::assertSame(ActivityDashboardStatus::Overdue, $items[0]->status);
    }

    public function testIsNeverCappedUnlikeTheDashboardWidget(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $teacher  = $this->teacher('docente');
        $entities = [$centre, $category, $teacher];
        for ($i = 0; $i < 10; ++$i) {
            $entities[] = $this->activity($category, "Actividad {$i}");
        }
        $this->persist(...$entities);

        self::assertCount(10, $this->finder->forTeacher($teacher, $centre));
    }

    public function testAByProfileActivityYieldsOneItemPerDistinctOwnerRowWithItsOwnerLabel(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $mate     = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura Matemáticas');
        $info     = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura Informática');
        $folder->addUploadProfile($mate);
        $folder->addUploadProfile($info);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $teacher  = $this->teacher('docente');
        $assignA  = new SpecificProfileAssignment($mate, null, $teacher);
        $assignB  = new SpecificProfileAssignment($info, null, $teacher);
        $this->persist($centre, $category, $section, $folder, $mate, $info, $activity, $teacher, $assignA, $assignB);

        $items = $this->finder->forTeacher($teacher, $centre);

        self::assertCount(2, $items);
        $labels = array_map(static fn ($i) => $i->ownerLabel, $items);
        sort($labels);
        self::assertSame(['Jefatura Informática', 'Jefatura Matemáticas'], $labels);
    }

    public function testCategoryPathIncludesTheFullAncestorTrail(): void
    {
        $centre = $this->centre();
        $root   = $this->category($centre, 'Curso');
        $child  = $this->category($centre, 'Departamentos');
        $child->setParent($root);
        $activity = $this->activity($child);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $root, $child, $activity, $teacher);

        $items = $this->finder->forTeacher($teacher, $centre);

        self::assertSame('Curso › Departamentos', $items[0]->categoryPath);
    }
}
