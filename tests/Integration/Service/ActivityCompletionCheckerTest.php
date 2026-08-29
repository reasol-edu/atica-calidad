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

final class ActivityCompletionCheckerTest extends RepositoryTestCase
{
    private ActivityCompletionChecker $checker;

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

        $this->persist(new ActivityCompletion($activity, $teacher, null, null, $teacher));

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
        $this->persist($centre, $category, $activity, $teacher, new ActivityCompletion($activity, $teacher, null, null, $teacher));

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
}
