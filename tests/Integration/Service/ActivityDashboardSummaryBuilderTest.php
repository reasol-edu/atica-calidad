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
use App\Service\ActivityDashboardSummaryBuilder;
use App\Service\ActivityDeadlineChecker;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ActivityDashboardSummaryBuilderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private ActivityDashboardSummaryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        // Only ever consumed by DashboardActivitySummaryComponent, so the compiled container
        // inlines it — build it directly from its own real, container-provided dependencies.
        $this->builder = new ActivityDashboardSummaryBuilder(
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

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    private function activity(ActivityCategory $category, string $title = 'Actividad'): Activity
    {
        return (new Activity())->setCategory($category)->setTitle($title)->setStart(1, 9)->setEnd(30, 9);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testANoFolderActivityAppliesToEveryTeacher(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $summary = $this->builder->build($teacher, $centre);

        self::assertSame(1, $summary->total);
        self::assertCount(1, $summary->items);
    }

    public function testAnIndividualScopeActivityOnlyAppliesWhenTheTeacherHoldsASlot(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual);
        $holder   = $this->teacher('tutor');
        $assign   = new SpecificProfileAssignment($profile, null, $holder);
        $outsider = $this->teacher('otro');

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $holder, $assign, $outsider);

        self::assertSame(1, $this->builder->build($holder, $centre)->total);
        self::assertSame(0, $this->builder->build($outsider, $centre)->total);
    }

    public function testAByProfileActivityYieldsOneItemPerDistinctOwnerRow(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $mate     = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura Matemáticas');
        $info     = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura Informática');
        $folder->addUploadProfile($mate);
        $folder->addUploadProfile($info);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $teacher  = $this->teacher('docente');
        $assignA  = new SpecificProfileAssignment($mate, null, $teacher);
        $assignB  = new SpecificProfileAssignment($info, null, $teacher);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $mate, $info, $activity, $teacher, $assignA, $assignB);

        $summary = $this->builder->build($teacher, $centre);

        self::assertSame(2, $summary->total);
        self::assertCount(2, $summary->items);
    }

    /**
     * A folder manager who doesn't personally hold the upload profile must NOT see the activity
     * in their dashboard — "aplicable por perfil de subida" excludes overseers without a slot of
     * their own, even though they'd see it as "theirs to manage" inside Actividades itself.
     */
    public function testAFolderManagerWithoutAnUploadProfileDoesNotSeeTheActivity(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder);
        $manager  = $this->teacher('director')->setAdmin(true);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $manager);

        self::assertSame(0, $this->builder->build($manager, $centre)->total);
    }

    public function testACompletedActivityCountsButIsNotInTheNeedsAttentionList(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher));

        $summary = $this->builder->build($teacher, $centre);

        self::assertSame(1, $summary->total);
        self::assertSame(1, $summary->completed);
        self::assertSame(0, $summary->pending);
        self::assertSame(0, $summary->overdue);
        self::assertSame([], $summary->items);
        self::assertSame(100, $summary->completionPercentage());
    }

    public function testAnUncompletedActivityIsPendingBeforeItsDeadline(): void
    {
        self::mockTime('2025-09-15 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category); // Sep 1–30
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $summary = $this->builder->build($teacher, $centre);

        self::assertSame(1, $summary->pending);
        self::assertSame(0, $summary->overdue);
        self::assertSame(ActivityDashboardStatus::Pending, $summary->items[0]->status);
    }

    public function testAnUncompletedActivityIsOverdueAfterItsDeadline(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category); // Sep 1–30
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $summary = $this->builder->build($teacher, $centre);

        self::assertSame(0, $summary->pending);
        self::assertSame(1, $summary->overdue);
        self::assertSame(ActivityDashboardStatus::Overdue, $summary->items[0]->status);
    }

    public function testOverdueItemsAreSortedBeforePendingOnes(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre     = $this->centre();
        $category   = $this->category($centre);
        $overdue    = $this->activity($category, 'Vencida')->setStart(1, 9)->setEnd(30, 9);
        $pending    = $this->activity($category, 'Pendiente')->setStart(1, 11)->setEnd(30, 11);
        $teacher    = $this->teacher('docente');
        $this->persist($centre, $category, $overdue, $pending, $teacher);

        $summary = $this->builder->build($teacher, $centre);

        self::assertCount(2, $summary->items);
        self::assertSame('Vencida', $summary->items[0]->activity->getTitle());
        self::assertSame('Pendiente', $summary->items[1]->activity->getTitle());
    }

    public function testItemsAreCappedToEight(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $teacher  = $this->teacher('docente');
        $entities = [$centre, $category, $teacher];
        for ($i = 0; $i < 10; ++$i) {
            $entities[] = $this->activity($category, "Actividad {$i}");
        }
        $this->persist(...$entities);

        $summary = $this->builder->build($teacher, $centre);

        self::assertSame(10, $summary->total);
        self::assertCount(8, $summary->items);
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

        $summary = $this->builder->build($teacher, $centre);

        self::assertSame('Curso › Departamentos', $summary->items[0]->categoryPath);
    }
}
