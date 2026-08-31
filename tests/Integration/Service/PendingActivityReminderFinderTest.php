<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Model\ActivityDashboardStatus;
use App\Repository\ActivityRepository;
use App\Service\ActivityCompletionChecker;
use App\Service\ActivityDeadlineChecker;
use App\Service\PendingActivityReminderFinder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PendingActivityReminderFinderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private PendingActivityReminderFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();

        // Mirrors ActivityDashboardSummaryBuilderTest: built directly from its own real,
        // container-provided dependencies rather than fetched by class name, since a service only
        // ever consumed by one message handler can get inlined into the compiled test container.
        $this->finder = new PendingActivityReminderFinder(
            self::getContainer()->get(ActivityRepository::class),
            self::getContainer()->get(ActivityCompletionChecker::class),
            new ActivityDeadlineChecker(self::getContainer()->get('clock')),
        );
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function category(EducationalCentre $centre): ActivityCategory
    {
        return (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testAnActivityWhoseCycleHasNotStartedIsExcludedEvenIfNominallyWithinWarningRange(): void
    {
        self::mockTime('2025-08-15 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        // Sep 1–30: hasn't started yet on Aug 15, even though the (wrong) naive "days until Sep 30"
        // would fall well inside a generous warning window.
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $result = $this->finder->forTeacher($teacher, $centre, 60);

        self::assertSame([], $result['dueSoon']);
        self::assertSame([], $result['overdue']);
    }

    public function testAStartedOverdueActivityGoesInTheOverdueBucket(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $result = $this->finder->forTeacher($teacher, $centre, 5);

        self::assertSame([], $result['dueSoon']);
        self::assertCount(1, $result['overdue']);
        self::assertSame(ActivityDashboardStatus::Overdue, $result['overdue'][0]->status);
    }

    public function testAStartedActivityWithinTheWarningWindowGoesInTheDueSoonBucket(): void
    {
        self::mockTime('2025-09-27 10:00:00'); // 3 days before the Sep 30 deadline

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $result = $this->finder->forTeacher($teacher, $centre, 5);

        self::assertCount(1, $result['dueSoon']);
        self::assertSame(ActivityDashboardStatus::Pending, $result['dueSoon'][0]->status);
        self::assertSame([], $result['overdue']);
    }

    public function testAStartedActivityOutsideTheWarningWindowIsExcluded(): void
    {
        self::mockTime('2025-09-10 10:00:00'); // 20 days before the Sep 30 deadline

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $result = $this->finder->forTeacher($teacher, $centre, 5);

        self::assertSame([], $result['dueSoon']);
        self::assertSame([], $result['overdue']);
    }

    public function testACompletedActivityIsExcludedEvenIfOverdue(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher));

        $result = $this->finder->forTeacher($teacher, $centre, 5);

        self::assertSame([], $result['dueSoon']);
        self::assertSame([], $result['overdue']);
    }

    public function testBothBucketsAreSortedByDeadlineAscending(): void
    {
        self::mockTime('2025-10-05 10:00:00');

        $centre    = $this->centre();
        $category  = $this->category($centre);
        $earlier   = (new Activity())->setCategory($category)->setTitle('Antigua')->setStart(1, 9)->setEnd(10, 9);
        $later     = (new Activity())->setCategory($category)->setTitle('Reciente')->setStart(1, 9)->setEnd(30, 9);
        $teacher   = $this->teacher('docente');
        $this->persist($centre, $category, $earlier, $later, $teacher);

        $result = $this->finder->forTeacher($teacher, $centre, 5);

        self::assertCount(2, $result['overdue']);
        self::assertSame('Antigua', $result['overdue'][0]->activity->getTitle());
        self::assertSame('Reciente', $result['overdue'][1]->activity->getTitle());
    }
}
