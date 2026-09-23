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
use App\Service\ActivityDeadlineChecker;
use App\Service\ActivityObligationFinder;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Every status an obligation can be in (see ActivityObligationStatus), as the one service every
 * screen and the reminder emails read it from. Activities run Oct 1–31 (well inside the academic
 * year with the default Sep 15 start), looked at on dates before, during and after that window.
 */
final class ActivityObligationFinderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private ActivityObligationFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ActivityObligationFinder $finder */
        $finder       = self::getContainer()->get(ActivityObligationFinder::class);
        $this->finder = $finder;
    }

    private function cycleKey(Activity $activity): int
    {
        /** @var ActivityDeadlineChecker $deadline */
        $deadline = self::getContainer()->get(ActivityDeadlineChecker::class);

        return $deadline->currentCycleKey($activity);
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
        return (new Activity())->setCategory($category)->setTitle($title)->setStart(1, 10)->setEnd(31, 10);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    /** The single obligation a plain teacher has for a no-folder activity, looked at on $now. */
    private function statusOfManualActivity(string $now, ?callable $configure = null): ActivityObligationStatus
    {
        self::mockTime($now);
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        if ($configure !== null) {
            $configure($activity);
        }
        $teacher = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $items = $this->finder->forTeacher($teacher, $centre);
        self::assertCount(1, $items);

        return $items[0]->status;
    }

    /**
     * A by-profile activity with a folder, whose only upload profile $teacher holds, with one
     * submission document in the state $revisionState ("pending", "rejected", "approved" or
     * null for none yet), looked at on $now.
     *
     * @return array{Teacher, EducationalCentre, Activity}
     */
    private function submissionScenario(string $now, ?string $revisionState, bool $autoComplete = false): array
    {
        self::mockTime($now);
        $centre   = $this->centre();
        $category = $this->category($centre);
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile)->setAutoComplete($autoComplete);
        $teacher  = $this->teacher('docente');
        $assign   = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $category, $section, $folder, $profile, $activity, $teacher, $assign);

        if ($revisionState !== null) {
            $document = new Document($folder, 'Jefatura');
            $document->setUploadProfile($profile);
            $file     = new DocumentFile(hash('sha256', $revisionState), 'x', 'application/pdf', 'x.pdf', 1);
            $revision = new DocumentRevision($document, 1, $file, $revisionState !== 'approved', $teacher);
            $document->getRevisions()->add($revision);
            if ($revisionState === 'approved') {
                $document->setActiveRevision($revision);
            } elseif ($revisionState === 'rejected') {
                $revision->reject($teacher, 'Falta la firma');
            }
            $this->persist($file, $document, $revision);
        }

        return [$teacher, $centre, $activity];
    }

    private function onlyStatus(Teacher $teacher, EducationalCentre $centre): ActivityObligationStatus
    {
        $items = $this->finder->forTeacher($teacher, $centre);
        self::assertCount(1, $items);

        return $items[0]->status;
    }

    // ── Window-driven statuses ───────────────────────────────────────────────

    public function testUpcomingBeforeTheOccurrenceOpens(): void
    {
        self::assertSame(ActivityObligationStatus::Upcoming, $this->statusOfManualActivity('2025-09-20 10:00:00'));
    }

    public function testOpenWithinTheWindowWithItsDaysLeft(): void
    {
        self::mockTime('2025-10-10 10:00:00');
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $item = $this->finder->forTeacher($teacher, $centre)[0];

        self::assertSame(ActivityObligationStatus::Open, $item->status);
        self::assertSame(21, $item->daysLeft);
        self::assertSame('2025-10-31', $item->deadline->format('Y-m-d'));
    }

    public function testOverdueAfterADeadlineThatIsNotEnforced(): void
    {
        self::assertSame(ActivityObligationStatus::Overdue, $this->statusOfManualActivity('2025-11-05 10:00:00'));
    }

    public function testLateDuringAnEnforcedDeadlinesGracePeriod(): void
    {
        $status = $this->statusOfManualActivity('2025-11-05 10:00:00', static fn (Activity $a) => $a->setEndDateEnforced(true)->setEndDateGraceDays(10));

        self::assertSame(ActivityObligationStatus::Late, $status);
    }

    public function testClosedOnceAnEnforcedDeadlineAndItsGraceHavePassed(): void
    {
        $status = $this->statusOfManualActivity('2025-11-05 10:00:00', static fn (Activity $a) => $a->setEndDateEnforced(true));

        self::assertSame(ActivityObligationStatus::Closed, $status);
    }

    public function testCompletedWhenMarkedForThisAcademicYear(): void
    {
        self::mockTime('2025-11-05 10:00:00');
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher, $this->cycleKey($activity)));

        self::assertSame(ActivityObligationStatus::Completed, $this->onlyStatus($teacher, $centre));
    }

    // ── Submission-driven statuses ───────────────────────────────────────────

    /** The whole point of "in review": the teacher did their part, so it's never overdue for them. */
    public function testInReviewWhileTheSubmissionAwaitsApprovalEvenPastTheDeadline(): void
    {
        [$teacher, $centre] = $this->submissionScenario('2025-11-05 10:00:00', 'pending');

        self::assertSame(ActivityObligationStatus::InReview, $this->onlyStatus($teacher, $centre));
    }

    public function testRejectedWhenTheSubmissionWasRejected(): void
    {
        [$teacher, $centre] = $this->submissionScenario('2025-10-10 10:00:00', 'rejected');

        self::assertSame(ActivityObligationStatus::Rejected, $this->onlyStatus($teacher, $centre));
    }

    public function testOpenWhenNothingHasBeenSubmittedYet(): void
    {
        [$teacher, $centre] = $this->submissionScenario('2025-10-10 10:00:00', null);

        self::assertSame(ActivityObligationStatus::Open, $this->onlyStatus($teacher, $centre));
    }

    /** A manual activity still has to be marked done after its submission is accepted. */
    public function testAnAcceptedSubmissionOfAManualActivityIsStillToDo(): void
    {
        [$teacher, $centre] = $this->submissionScenario('2025-10-10 10:00:00', 'approved');

        self::assertSame(ActivityObligationStatus::Open, $this->onlyStatus($teacher, $centre));
    }

    public function testAnAcceptedSubmissionCompletesAnAutoCompleteActivity(): void
    {
        [$teacher, $centre] = $this->submissionScenario('2025-10-10 10:00:00', 'approved', autoComplete: true);

        self::assertSame(ActivityObligationStatus::Completed, $this->onlyStatus($teacher, $centre));
    }

    // ── Owners, paths, aggregation ───────────────────────────────────────────

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

        $labels = array_map(static fn ($i) => $i->ownerLabel, $this->finder->forTeacher($teacher, $centre));
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

        self::assertSame('Curso › Departamentos', $this->finder->forTeacher($teacher, $centre)[0]->categoryPath);
    }

    public function testWorstStatusIsNullForAnActivityThatIsNotTheTeachersOwn(): void
    {
        [, , $activity] = $this->submissionScenario('2025-11-05 10:00:00', null);
        $outsider = $this->teacher('ajeno');
        $this->persist($outsider);

        self::assertNull($this->finder->worstStatusFor($outsider, $activity), 'someone else\'s activity has no status of its own for this teacher');
    }

    public function testWorstStatusIsTheMostUrgentOfTheTeachersOwnObligations(): void
    {
        [$teacher, , $activity] = $this->submissionScenario('2025-11-05 10:00:00', null);

        self::assertSame(ActivityObligationStatus::Overdue, $this->finder->worstStatusFor($teacher, $activity));
    }
}
