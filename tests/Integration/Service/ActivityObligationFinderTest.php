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
use App\Entity\ListItem;
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

    public function testASingleEmptyRowCanBeUploadedFromTheList(): void
    {
        [$teacher, $centre] = $this->submissionScenario('2025-10-10 10:00:00', null);

        $item = $this->finder->forTeacher($teacher, $centre)[0];

        self::assertSame('new', $item->uploadMode);
        self::assertSame($item->slotKey, $item->uploadSlotKey);
        self::assertSame('', $item->uploadDocumentId);
    }

    public function testARejectedSubmissionOffersANewVersionFromTheList(): void
    {
        [$teacher, $centre] = $this->submissionScenario('2025-10-10 10:00:00', 'rejected');

        $item = $this->finder->forTeacher($teacher, $centre)[0];

        self::assertSame('revision', $item->uploadMode);
        self::assertNotSame('', $item->uploadDocumentId);
    }

    public function testNothingIsOfferedWhileTheSubmissionIsInReview(): void
    {
        [$teacher, $centre] = $this->submissionScenario('2025-10-10 10:00:00', 'pending');

        self::assertSame('', $this->finder->forTeacher($teacher, $centre)[0]->uploadMode);
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

    public function testWorstItemCarriesTheDatesBehindTheWorstStatus(): void
    {
        [$teacher, , $activity] = $this->submissionScenario('2025-11-05 10:00:00', null);

        $item = $this->finder->worstItemFor($teacher, $activity);

        self::assertNotNull($item);
        self::assertSame(ActivityObligationStatus::Overdue, $item->status);
        self::assertLessThan(0, $item->daysLeft, 'past its deadline');
        $outsider = $this->teacher('ajeno');
        $this->persist($outsider);
        self::assertNull($this->finder->worstItemFor($outsider, $activity));
    }

    public function testAHiddenActivityIsNobodysObligation(): void
    {
        self::mockTime('2025-10-10 10:00:00');
        $centre   = $this->centre();
        $category = $this->category($centre);
        $visible  = $this->activity($category, 'Visible');
        $hidden   = $this->activity($category, 'Oculta')->setHidden(true);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $visible, $hidden, $teacher);

        $titles = array_map(static fn ($i) => $i->activity->getTitle(), $this->finder->forTeacher($teacher, $centre));

        self::assertSame(['Visible'], $titles);
    }

    // ── Per-leaf deadline overrides ─────────────────────────────────────────

    /**
     * Two list leaves sharing the exact same (unassociated) upload row are still tracked as two
     * independent obligations, each with its own deadline — see ActivityCompletionChecker::
     * getMyOwnedObligations(). Only one of them has a deadline override, so this also checks that
     * the other one is untouched, still using the activity's own default dates.
     */
    public function testListBackedLeavesSharingAnUploadRowAreIndependentObligations(): void
    {
        // Past the activity's own Oct 31 deadline, but before Química's own Dec 31 override.
        self::mockTime('2025-11-05 10:00:00');
        $centre   = $this->centre();
        $category = $this->category($centre);
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);
        $root    = (new ListItem())->setName('Materias')->setEducationalCentre($centre);
        $fisica  = (new ListItem())->setName('Física')->setEducationalCentre($centre)->setParent($root);
        $quimica = (new ListItem())->setName('Química')->setEducationalCentre($centre)->setParent($root);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile)->setListItem($root);
        $activity->setDeadlineOverride($quimica, 1, 12, 31, 12);
        $teacher = $this->teacher('docente');
        $assign  = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $category, $section, $folder, $profile, $root, $fisica, $quimica, $activity, $teacher, $assign);

        $items = $this->finder->forTeacher($teacher, $centre);
        self::assertCount(2, $items, 'each leaf of the shared row is its own obligation');

        $byLabel = [];
        foreach ($items as $item) {
            $byLabel[$item->ownerLabel] = $item;
        }
        self::assertSame('2025-10-31', $byLabel['Jefatura · Física']->deadline->format('Y-m-d'), 'untouched leaf keeps the activity\'s own deadline');
        self::assertSame('2025-12-31', $byLabel['Jefatura · Química']->deadline->format('Y-m-d'), 'overridden leaf uses its own, later deadline');
        self::assertSame(ActivityObligationStatus::Overdue, $byLabel['Jefatura · Física']->status, 'past its own deadline');
        self::assertSame(ActivityObligationStatus::Upcoming, $byLabel['Jefatura · Química']->status, 'its own, later occurrence has not opened yet');
    }

    /**
     * An activity with a dimension with dates («una entrega por materia y evaluación»): each evaluation is its own obligation, with the
     * evaluation's dates, and completing one never completes another.
     */
    public function testEachValueOfTheDimensionWithDatesIsItsOwnObligationWithItsOwnDatesAndCompletion(): void
    {
        self::mockTime('2025-12-10 10:00:00');
        $centre   = $this->centre();
        $category = $this->category($centre);
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);
        $evals  = (new ListItem())->setName('Evaluación')->setEducationalCentre($centre);
        $first  = (new ListItem())->setName('1ª')->setEducationalCentre($centre)->setParent($evals)->setPosition(0);
        $second = (new ListItem())->setName('2ª')->setEducationalCentre($centre)->setParent($evals)->setPosition(1);
        $root   = (new ListItem())->setName('Materias')->setEducationalCentre($centre);
        $fisica = (new ListItem())->setName('Física')->setEducationalCentre($centre)->setParent($root);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile)->setListItem($root)
            ->setDimensions([$evals], 0);
        $activity->setDeadlineOverride($first, 1, 12, 20, 12);
        $activity->setDeadlineOverride($second, 1, 3, 20, 3);
        $teacher = $this->teacher('docente');
        $assign  = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $category, $section, $folder, $profile, $evals, $first, $second, $root, $fisica, $activity, $teacher, $assign);

        $byLabel = [];
        foreach ($this->finder->forTeacher($teacher, $centre) as $item) {
            $byLabel[(string) $item->ownerLabel] = $item;
        }
        self::assertSame(['Jefatura · Física · 1ª', 'Jefatura · Física · 2ª'], array_keys($byLabel));
        self::assertSame('2025-12-20', $byLabel['Jefatura · Física · 1ª']->deadline->format('Y-m-d'), 'the 1ª\'s dates');
        self::assertSame('2026-03-20', $byLabel['Jefatura · Física · 2ª']->deadline->format('Y-m-d'), 'the 2ª\'s dates');
        self::assertSame(ActivityObligationStatus::Open, $byLabel['Jefatura · Física · 1ª']->status);
        self::assertSame(ActivityObligationStatus::Upcoming, $byLabel['Jefatura · Física · 2ª']->status);
        self::assertNotSame('', $byLabel['Jefatura · Física · 1ª']->combination);
        self::assertCount(5, explode(':', $byLabel['Jefatura · Física · 1ª']->slotKey), 'it lands on its own row');

        /** @var \App\Service\ActivityCompletionChecker $completion */
        $completion  = self::getContainer()->get(\App\Service\ActivityCompletionChecker::class);
        $combination = $byLabel['Jefatura · Física · 1ª']->combination;
        self::assertTrue($completion->markCompleted($activity, null, $profile, null, $teacher, $fisica, $combination));
        $this->em->flush();

        self::assertTrue($completion->isCompletedFor($activity, $profile, null, null, $fisica, null, $combination));
        self::assertFalse($completion->isCompletedFor($activity, $profile, null, null, $fisica, null, $byLabel['Jefatura · Física · 2ª']->combination), 'the 2ª is still to do');
        self::assertFalse($completion->isCompletedFor($activity, $profile, null, null, $fisica), 'nor is a completion without combination');
    }

    public function testIndividuallyEachTeacherOfAnElementDeliversTheirOwnBesidesWhoHoldsTheProfile(): void
    {
        self::mockTime('2025-10-10 10:00:00');
        $centre   = $this->centre();
        $category = $this->category($centre);
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Revisiones');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);
        $head     = $this->teacher('jefa');
        $noelia   = $this->teacher('noelia');
        $root     = (new ListItem())->setName('Materias')->setEducationalCentre($centre);
        $physics  = (new ListItem())->setName('Física')->setEducationalCentre($centre)->setParent($root)->addTeacher($noelia);
        $chem     = (new ListItem())->setName('Química')->setEducationalCentre($centre)->setParent($root)->setPosition(1);
        $year     = (new \App\Entity\AcademicYear())->setName('2025-2026')->setEducationalCentre($centre)->addTeacher($head)->addTeacher($noelia);
        $centre->setActiveAcademicYear($year);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual)->setListItem($root)->setTeachersDeliver(true);
        $this->persist($centre, $category, $section, $folder, $profile, $head, $noelia, $root, $physics, $chem, $year, $activity, new SpecificProfileAssignment($profile, null, $head));

        /** @var \App\Service\ActivitySubmissionSlotBuilder $builder */
        $builder = self::getContainer()->get(\App\Service\ActivitySubmissionSlotBuilder::class);
        $who     = array_map(static fn ($s): string => $s->displayName . ' — ' . $s->teacher?->getUsername(), $builder->buildSlots($activity));
        sort($who);
        self::assertSame(['Física — jefa', 'Física — noelia', 'Química — jefa'], $who, 'Noelia teaches Física: her own one, besides the head\'s');

        /** @var \App\Service\SlotResponsibility $responsibility */
        $responsibility = self::getContainer()->get(\App\Service\SlotResponsibility::class);
        foreach ($builder->buildSlots($activity) as $slot) {
            self::assertSame($slot->teacher === $noelia, $responsibility->canFill($noelia, $activity, $slot), 'she fills in only her own: ' . $slot->displayName);
        }
        self::assertCount(1, $this->finder->forTeacher($noelia, $centre), 'her task: the activity, for her own delivery');

        // Without the box, only who holds the profile.
        $activity->setTeachersDeliver(false);
        $this->em->flush();
        $builder->reset();
        self::assertCount(2, $builder->buildSlots($activity));
    }

    public function testWithIndividualSubmissionsEachValueIsStillItsOwnObligationWithItsOwnDates(): void
    {
        self::mockTime('2025-12-10 10:00:00');
        $centre   = $this->centre();
        $category = $this->category($centre);
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutoría');
        $folder->addUploadProfile($profile);
        $evals  = (new ListItem())->setName('Evaluación')->setEducationalCentre($centre);
        $first  = (new ListItem())->setName('1ª')->setEducationalCentre($centre)->setParent($evals)->setPosition(0);
        $second = (new ListItem())->setName('2ª')->setEducationalCentre($centre)->setParent($evals)->setPosition(1);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual)->setDimensions([$evals], 0);
        $activity->setDeadlineOverride($first, 1, 12, 20, 12);
        $activity->setDeadlineOverride($second, 1, 3, 20, 3);
        $teacher = $this->teacher('docente');
        $this->persist($centre, $category, $section, $folder, $profile, $evals, $first, $second, $activity, $teacher, new SpecificProfileAssignment($profile, null, $teacher));

        $byLabel = [];
        foreach ($this->finder->forTeacher($teacher, $centre) as $item) {
            $byLabel[(string) $item->ownerLabel] = $item;
        }
        self::assertSame(['1ª', '2ª'], array_keys($byLabel), 'one per evaluation, not one for the whole activity');
        self::assertSame('2025-12-20', $byLabel['1ª']->deadline->format('Y-m-d'));
        self::assertSame('2026-03-20', $byLabel['2ª']->deadline->format('Y-m-d'));
        self::assertSame(ActivityObligationStatus::Open, $byLabel['1ª']->status);
        self::assertSame(ActivityObligationStatus::Upcoming, $byLabel['2ª']->status);

        // Ticking off the 1ª by hand (OwnCompletionManager, as the list does) leaves the 2ª to do.
        $manager = self::getContainer()->get(\App\Service\OwnCompletionManager::class);
        self::assertSame(\App\Model\OwnCompletionOutcome::Marked, $manager->mark($teacher, $activity, $centre, '', '', '', $byLabel['1ª']->combination));
        $this->em->flush();
        $statuses = [];
        foreach ($this->finder->forTeacher($teacher, $centre) as $item) {
            $statuses[(string) $item->ownerLabel] = $item->status;
        }
        self::assertSame(ActivityObligationStatus::Completed, $statuses['1ª']);
        self::assertSame(ActivityObligationStatus::Upcoming, $statuses['2ª']);

        try {
            $manager->mark($teacher, $activity, $centre, '', '', '', '');
            self::fail('without a combination, a completion nobody reads');
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
        }
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $manager->mark($teacher, $activity, $centre, '', '', '', 'not-a-combination');
    }
}
