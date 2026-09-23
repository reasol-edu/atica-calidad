<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Model\ActivityObligationStatus;
use App\Service\ActivityObligationFinder;
use App\Service\PendingActivityReminderFinder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class PendingActivityReminderFinderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private PendingActivityReminderFinder $finder;

    /** Cycle key of $activity's occurrence "now" — what a completion made at this point would be stored against. */
    private function cycleKey(Activity $activity): int
    {
        /** @var \App\Service\ActivityDeadlineChecker $deadline */
        $deadline = self::getContainer()->get(\App\Service\ActivityDeadlineChecker::class);

        return $deadline->currentCycleKey($activity);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Mirrors ActivityDashboardSummaryBuilderTest: built directly from its own real,
        // container-provided dependencies rather than fetched by class name, since a service only
        // ever consumed by one message handler can get inlined into the compiled test container.
        /** @var ActivityObligationFinder $obligations */
        $obligations  = self::getContainer()->get(ActivityObligationFinder::class);
        $this->finder = new PendingActivityReminderFinder($obligations);
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
        self::mockTime('2025-09-15 10:00:00');

        $centre   = $this->centre();
        $category = $this->category($centre);
        // Oct 1–30: hasn't started yet on Sep 15, even though the (wrong) naive "days until Oct 30"
        // would fall well inside a generous warning window.
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 10)->setEnd(30, 10);
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
        self::assertSame(ActivityObligationStatus::Overdue, $result['overdue'][0]->status);
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
        self::assertSame(ActivityObligationStatus::Open, $result['dueSoon'][0]->status);
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
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher, $this->cycleKey($activity)));

        $result = $this->finder->forTeacher($teacher, $centre, 5);

        self::assertSame([], $result['dueSoon']);
        self::assertSame([], $result['overdue']);
    }

    public function testBothBucketsAreSortedByDeadlineAscending(): void
    {
        self::mockTime('2025-11-05 10:00:00');

        $centre    = $this->centre();
        $category  = $this->category($centre);
        $earlier   = (new Activity())->setCategory($category)->setTitle('Antigua')->setStart(1, 10)->setEnd(10, 10);
        $later     = (new Activity())->setCategory($category)->setTitle('Reciente')->setStart(1, 10)->setEnd(30, 10);
        $teacher   = $this->teacher('docente');
        $this->persist($centre, $category, $earlier, $later, $teacher);

        $result = $this->finder->forTeacher($teacher, $centre, 5);

        self::assertCount(2, $result['overdue']);
        self::assertSame('Antigua', $result['overdue'][0]->activity->getTitle());
        self::assertSame('Reciente', $result['overdue'][1]->activity->getTitle());
    }

    /**
     * A by-profile activity (Oct 1–31) with a folder, whose only upload profile $teacher holds,
     * with one submission in the state $revisionState ("pending" or "rejected").
     */
    private function activityWithSubmission(EducationalCentre $centre, Teacher $teacher, string $revisionState): void
    {
        $category = $this->category($centre);
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);
        $activity = (new Activity())->setCategory($category)->setTitle('Con entrega')->setStart(1, 10)->setEnd(31, 10)
            ->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $assign   = new SpecificProfileAssignment($profile, null, $teacher);
        $document = (new Document($folder, 'Jefatura'))->setUploadProfile($profile);
        $file     = new DocumentFile(hash('sha256', $revisionState), 'x', 'application/pdf', 'x.pdf', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $teacher);
        if ($revisionState === 'rejected') {
            $revision->reject($teacher, null);
        }
        $document->getRevisions()->add($revision);
        $this->persist($category, $section, $folder, $profile, $activity, $assign, $file, $document, $revision);
    }

    /** Already submitted and waiting for approval: the teacher did their part — no "overdue" nagging. */
    public function testASubmissionAwaitingApprovalIsNeverReminded(): void
    {
        self::mockTime('2025-11-05 10:00:00');
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $this->activityWithSubmission($centre, $teacher, 'pending');

        $result = $this->finder->forTeacher($teacher, $centre, 60);

        self::assertSame([], $result['dueSoon']);
        self::assertSame([], $result['overdue']);
    }

    public function testARejectedSubmissionDueSoonIsReminded(): void
    {
        self::mockTime('2025-10-28 10:00:00');
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $this->persist($centre, $teacher);
        $this->activityWithSubmission($centre, $teacher, 'rejected');

        $result = $this->finder->forTeacher($teacher, $centre, 5);

        self::assertCount(1, $result['dueSoon']);
        self::assertSame(ActivityObligationStatus::Rejected, $result['dueSoon'][0]->status);
    }

    public function testAClosedActivityIsNotReminded(): void
    {
        self::mockTime('2025-11-05 10:00:00');
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = (new Activity())->setCategory($category)->setTitle('Cerrada')->setStart(1, 10)->setEnd(31, 10)->setEndDateEnforced(true);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $result = $this->finder->forTeacher($teacher, $centre, 60);

        self::assertSame([], $result['dueSoon']);
        self::assertSame([], $result['overdue'], 'nothing can be done about it any more');
    }
}
