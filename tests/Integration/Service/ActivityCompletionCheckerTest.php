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
use App\Service\ActivityCompletionChecker;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ActivityCompletionCheckerTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private ActivityCompletionChecker $checker;

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

        /** @var ActivityCompletionChecker $checker */
        $checker      = self::getContainer()->get(ActivityCompletionChecker::class);
        $this->checker = $checker;
    }

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    private function activity(ActivityCategory $category): Activity
    {
        return (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 9)->setEnd(30, 9);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    public function testGetMySlotsIsEmptyWhenActivityHasNoFolder(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        self::assertSame([], $this->checker->getMySlots($teacher, $activity));
    }

    public function testGetMySlotsIncludesEverythingForAFolderManager(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder);
        $admin    = $this->teacher('director')->setAdmin(true);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $admin);

        self::assertCount(1, $this->checker->getMySlots($admin, $activity));
    }

    public function testGetMySlotsExcludesRowsTheTeacherDoesNotHold(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder);
        $teacher  = $this->teacher('docente');

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher);

        self::assertSame([], $this->checker->getMySlots($teacher, $activity));
    }

    /**
     * Unlike getMySlots(), getMyOwnedSlots() must NOT be widened by folder-management rights — a
     * folder manager who doesn't personally hold the upload profile owns nothing here. This is
     * what lets the dashboard summary show only genuine "I have to upload this" obligations.
     */
    public function testGetMyOwnedSlotsIgnoresFolderManagementRights(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder);
        $admin    = $this->teacher('director')->setAdmin(true);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $admin);

        self::assertSame([], $this->checker->getMyOwnedSlots($admin, $activity));
    }

    public function testGetMyOwnedSlotsIncludesRowsTheTeacherPersonallyHolds(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $teacher  = $this->teacher('docente');
        $assign   = new SpecificProfileAssignment($profile, null, $teacher);
        $activity = $this->activity($category)->setFolder($folder);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $teacher, $assign, $activity);

        self::assertCount(1, $this->checker->getMyOwnedSlots($teacher, $activity));
    }

    public function testGetMyOwnedCompletionOwnersIgnoresFolderManagementRights(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);
        $admin    = $this->teacher('director')->setAdmin(true);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $admin);

        self::assertSame([], $this->checker->getMyOwnedCompletionOwners($admin, $activity));
    }

    public function testGetMyOwnedObligationsAppliesANoFolderActivityToEveryTeacher(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Actividad manual')->setStart(1, 9)->setEnd(30, 9);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $obligations = $this->checker->getMyOwnedObligations($teacher, $activity);

        self::assertCount(1, $obligations);
        self::assertSame($teacher, $obligations[0]['teacher']);
        self::assertNull($obligations[0]['profile']);
        self::assertSame('', $obligations[0]['key']);
    }

    public function testGetMyOwnedObligationsForIndividualScopeRequiresAHeldSlot(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual);
        $holder   = $this->teacher('tutor');
        $assign   = new SpecificProfileAssignment($profile, null, $holder);
        $outsider = $this->teacher('otro');

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $holder, $assign, $outsider);

        self::assertCount(1, $this->checker->getMyOwnedObligations($holder, $activity));
        self::assertSame([], $this->checker->getMyOwnedObligations($outsider, $activity));
    }

    public function testGetMyOwnedObligationsForByProfileScopeYieldsOneRowPerDistinctOwner(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
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

        $obligations = $this->checker->getMyOwnedObligations($teacher, $activity);

        self::assertCount(2, $obligations);
        $keys = array_map(static fn (array $o): string => $o['key'], $obligations);
        sort($keys);
        self::assertNotSame($keys[0], $keys[1]);
        $labels = array_map(static fn (array $o): ?string => $o['label'], $obligations);
        sort($labels);
        self::assertSame(['Jefatura Informática', 'Jefatura Matemáticas'], $labels);
    }

    public function testGetMyOwnedObligationsIgnoresFolderManagementRights(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder);
        $manager  = $this->teacher('director')->setAdmin(true);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $manager);

        self::assertSame([], $this->checker->getMyOwnedObligations($manager, $activity));
    }

    public function testHasIndividualCompletionOwnerIsTrueWithoutAFolder(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->activity($category);

        self::assertTrue($this->checker->hasIndividualCompletionOwner($activity));
        $this->persist($centre, $category, $activity);
    }

    public function testHasIndividualCompletionOwnerIsTrueForIndividualScope(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual);

        self::assertTrue($this->checker->hasIndividualCompletionOwner($activity));
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $activity);
    }

    public function testHasIndividualCompletionOwnerIsFalseForByProfileScope(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);

        self::assertFalse($this->checker->hasIndividualCompletionOwner($activity));
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $activity);
    }

    public function testGetMyCompletionOwnersDeduplicatesAndIgnoresIndividualScope(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profileA = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura Matemáticas');
        $profileB = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura Informática');
        $folder->addUploadProfile($profileA);
        $folder->addUploadProfile($profileB);
        $teacher  = $this->teacher('docente');
        $assignA  = new SpecificProfileAssignment($profileA, null, $teacher);
        $assignB  = new SpecificProfileAssignment($profileB, null, $teacher);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::ByProfile);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profileA, $profileB, $teacher, $assignA, $assignB, $activity);

        $owners = $this->checker->getMyCompletionOwners($teacher, $activity);

        self::assertCount(2, $owners);
    }

    public function testGetMyCompletionOwnersIsEmptyForIndividualScope(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a');
        $folder->addUploadProfile($profile);
        $teacher  = $this->teacher('docente');
        $assign   = new SpecificProfileAssignment($profile, null, $teacher);
        $activity = $this->activity($category)->setFolder($folder)->setSubmissionScope(ActivitySubmissionScope::Individual);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $teacher, $assign, $activity);

        self::assertSame([], $this->checker->getMyCompletionOwners($teacher, $activity));
    }

    public function testIsCompletedForManualActivityChecksThePersistedCompletion(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        self::assertFalse($this->checker->isCompletedFor($activity, null, null, $teacher));

        $this->persist(new ActivityCompletion($activity, $teacher, null, null, $teacher, $this->cycleKey($activity)));

        self::assertTrue($this->checker->isCompletedFor($activity, null, null, $teacher));
    }

    public function testIsCompletedForAutoCompleteActivityRequiresAllOwnedSlotsToHaveAnActiveRevision(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder)->setAutoComplete(true);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity);

        self::assertFalse($this->checker->isCompletedFor($activity, $profile, null, null));

        $teacher  = $this->teacher('docente');
        $document = new Document($folder, 'Secretario/a');
        $document->setUploadProfile($profile, null);
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $teacher);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->persist($teacher, $document, $file, $revision);

        self::assertTrue($this->checker->isCompletedFor($activity, $profile, null, null));
    }

    public function testMarkCompletedCreatesACompletionAndReturnsTrue(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        $created = $this->checker->markCompleted($activity, $teacher, null, null, $teacher);

        self::assertTrue($created);
        $this->em->flush();
        self::assertTrue($this->checker->isCompletedFor($activity, null, null, $teacher));
    }

    public function testMarkCompletedIsANoOpWhenAlreadyCompleted(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher, $this->cycleKey($activity)));

        self::assertFalse($this->checker->markCompleted($activity, $teacher, null, null, $teacher));
    }

    public function testMarkCompletedIsANoOpForAnAutoCompleteActivity(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $activity = $this->activity($category)->setFolder($folder)->setAutoComplete(true);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $activity, $teacher);

        self::assertFalse($this->checker->markCompleted($activity, $teacher, null, null, $teacher));
    }

    public function testUnmarkCompletedRemovesAnExistingCompletionAndReturnsTrue(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher, $this->cycleKey($activity)));

        $removed = $this->checker->unmarkCompleted($activity, $teacher, null, null);

        self::assertTrue($removed);
        $this->em->flush();
        self::assertFalse($this->checker->isCompletedFor($activity, null, null, $teacher));
    }

    public function testUnmarkCompletedIsANoOpWhenNotCompleted(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        self::assertFalse($this->checker->unmarkCompleted($activity, $teacher, null, null));
    }

    public function testUnmarkCompletedIsANoOpForAnAutoCompleteActivity(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $activity = $this->activity($category)->setFolder($folder)->setAutoComplete(true);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $activity, $teacher);

        self::assertFalse($this->checker->unmarkCompleted($activity, $teacher, null, null));
    }

    // ── Per academic year ─────────────────────────────────────────────────────

    /** Oct 1–31: well inside the academic year with the default Sep 15 start, so its occurrence is unambiguous. */
    private function octoberActivity(ActivityCategory $category): Activity
    {
        return (new Activity())->setCategory($category)->setTitle('Actividad')->setStart(1, 10)->setEnd(31, 10);
    }

    public function testACompletionOnlyCountsForTheAcademicYearItWasMadeIn(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->octoberActivity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);

        self::mockTime('2025-10-10 10:00:00');
        self::assertTrue($this->checker->markCompleted($activity, $teacher, null, null, $teacher));
        $this->em->flush();
        self::assertTrue($this->checker->isCompletedFor($activity, null, null, $teacher));

        // Next academic year's occurrence starts out pending, and can be completed on its own.
        self::mockTime('2026-10-10 10:00:00');
        self::assertFalse($this->checker->isCompletedFor($activity, null, null, $teacher));
        self::assertTrue($this->checker->markCompleted($activity, $teacher, null, null, $teacher));
        $this->em->flush();
        self::assertTrue($this->checker->isCompletedFor($activity, null, null, $teacher));

        // Looking back at last year's occurrence (as the calendar does) still finds that one.
        self::assertTrue($this->checker->isCompletedFor($activity, null, null, $teacher, new \DateTimeImmutable('2025-10-31')));
    }

    public function testUnmarkCompletedOnlyUndoesTheCurrentAcademicYear(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $activity = $this->octoberActivity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher, 2025));

        self::mockTime('2026-10-10 10:00:00');
        self::assertFalse($this->checker->unmarkCompleted($activity, $teacher, null, null), 'nothing to undo this year');
        $this->em->flush();

        self::assertTrue($this->checker->isCompletedFor($activity, null, null, $teacher, new \DateTimeImmutable('2025-10-31')), 'last year\'s completion is untouched');
    }

    public function testASubmissionFromLastAcademicYearDoesNotFillThisYearsSlot(): void
    {
        $centre   = $this->centre();
        $category = (new ActivityCategory())->setEducationalCentre($centre)->setName('Categoría');
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $activity = $this->octoberActivity($category)->setFolder($folder)->setAutoComplete(true);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity);

        self::mockTime('2025-10-10 10:00:00');
        $teacher  = $this->teacher('docente');
        $document = new Document($folder, 'Secretario/a');
        $document->setUploadProfile($profile, null);
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $teacher);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->persist($teacher, $document, $file, $revision);

        self::assertSame(2025, $document->getActivityCycleYear(), 'stamped with the occurrence open when it was uploaded');
        self::assertTrue($this->checker->isCompletedFor($activity, $profile, null, null));

        self::mockTime('2026-10-10 10:00:00');
        self::assertFalse($this->checker->isCompletedFor($activity, $profile, null, null));
        $slots = $this->checker->getAllSlots($activity);
        self::assertCount(1, $slots);
        self::assertNull($this->checker->resolveSlot($activity, $slots[0]), 'this year\'s slot is empty again, ready for a new upload');
    }

    public function testADocumentOutsideAnActivityFolderIsNotStamped(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $teacher = $this->teacher('docente');
        $document = new Document($folder, 'Acta');
        $file     = new DocumentFile(hash('sha256', 'acta'), 'acta', 'text/plain', 'acta.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $teacher);
        $document->getRevisions()->add($revision);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher, $document, $file, $revision);

        self::assertNull($document->getActivityCycleYear());
    }
}
