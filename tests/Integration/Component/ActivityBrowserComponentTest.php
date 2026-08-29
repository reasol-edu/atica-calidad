<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
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
use App\Tests\Integration\ControllerTestCase;
use App\Twig\Components\ActivityBrowserComponent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class ActivityBrowserComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

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
        return (new Activity())->setCategory($category)->setTitle($title)->setStart(1, 9)->setEnd(30, 6);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function admin(string $username = 'admin'): Teacher
    {
        $teacher = $this->teacher($username);
        $teacher->setAdmin(true);

        return $teacher;
    }

    /** @return array<string, mixed> */
    private function props(TestLiveComponent $component): array
    {
        $value = $component->render()->crawler()->filter('[data-live-props-value]')->attr('data-live-props-value');
        self::assertNotNull($value);

        /** @var array<string, mixed> $props */
        $props = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return $props;
    }

    /** @return list<string> */
    private function stringListProp(TestLiveComponent $component, string $key): array
    {
        $value = $this->props($component)[$key];
        self::assertIsArray($value);

        $strings = [];
        foreach ($value as $item) {
            self::assertIsString($item);
            $strings[] = $item;
        }

        return $strings;
    }

    private function stringProp(TestLiveComponent $component, string $key): string
    {
        $value = $this->props($component)[$key];
        self::assertIsString($value);

        return $value;
    }

    // ── Category navigation / relevance filtering ────────────────────────────

    public function testVisibleCategoriesAreFilteredToRelevantOnesByDefault(): void
    {
        $centre  = $this->centre();
        $relevantCategory   = $this->category($centre, 'Relevante');
        // An activity without a folder is always relevant (it's just a manual reminder — no
        // profiles to consult), so the irrelevant category must instead hold an activity whose
        // folder the teacher genuinely has no upload/manage/review profile for.
        $irrelevantCategory = $this->category($centre, 'Irrelevante');
        $folder       = $this->folder($centre);
        $otherFolder  = $this->folder($centre);
        $profile      = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $otherProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Otro perfil');
        $folder->addUploadProfile($profile);
        $otherFolder->addUploadProfile($otherProfile);
        $relevantActivity   = $this->activity($relevantCategory)->setFolder($folder);
        $irrelevantActivity = $this->activity($irrelevantCategory, 'Otra')->setFolder($otherFolder);

        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);

        $this->persist(
            $centre, $relevantCategory, $irrelevantCategory,
            $folder->getDocumentSection(), $folder, $profile,
            $otherFolder->getDocumentSection(), $otherFolder, $otherProfile,
            $relevantActivity, $irrelevantActivity, $teacher, $assignment,
        );

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);

        $rendered = $component->render();
        self::assertStringContainsString('Relevante', (string) $rendered->crawler()->html());
        self::assertStringNotContainsString('Irrelevante', (string) $rendered->crawler()->html());
    }

    public function testShowAllProfilesRevealsIrrelevantCategoriesToo(): void
    {
        $centre    = $this->centre();
        $category  = $this->category($centre, 'Sin relación conmigo');
        $folder    = $this->folder($centre);
        $profile   = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil ajeno');
        $folder->addUploadProfile($profile);
        // A folder-backed activity the stranger holds no profile for — an activity WITHOUT a
        // folder would always be relevant regardless, defeating the point of this test.
        $activity  = $this->activity($category)->setFolder($folder);
        $stranger  = $this->teacher('ajeno');
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $stranger);

        $this->loginAs($stranger, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);

        $before = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString('Sin relación conmigo', $before);

        $component->call('toggleShowAllProfiles');
        $after = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Sin relación conmigo', $after);
    }

    public function testOpenLevelNavigatesIntoACategory(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre, 'Programaciones');
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('openLevel', ['id' => $category->getId()->toRfc4122()]);

        self::assertSame($category->getId()->toRfc4122(), $this->stringProp($component, 'currentCategoryId'));
    }

    // ── Activity CRUD ─────────────────────────────────────────────────────────

    public function testSaveActivityCreatesANewActivityWithinTheCurrentCategory(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'             => $centre,
            'initialCategoryId'  => $category->getId()->toRfc4122(),
        ], $this->client);

        $component
            ->set('formTitle', 'Nueva actividad')
            ->set('formStartDay', '1')
            ->set('formStartMonth', '9')
            ->set('formEndDay', '30')
            ->set('formEndMonth', '6')
            ->call('saveActivity');

        $this->em->clear();
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedCategory = self::getContainer()->get(\App\Repository\ActivityCategoryRepository::class)->findByIdAndCentre($category->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloadedCategory);
        $created = $activities->findByCategory($reloadedCategory);
        self::assertCount(1, $created);
        self::assertSame('Nueva actividad', $created[0]->getTitle());
    }

    public function testSaveActivityRejectsAnEmptyTitle(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);

        $component
            ->set('formTitle', '   ')
            ->set('formStartDay', '1')->set('formStartMonth', '9')
            ->set('formEndDay', '30')->set('formEndMonth', '6')
            ->call('saveActivity');

        $this->em->clear();
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedCategory = self::getContainer()->get(\App\Repository\ActivityCategoryRepository::class)->findByIdAndCentre($category->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloadedCategory);
        self::assertCount(0, $activities->findByCategory($reloadedCategory));
    }

    public function testSaveActivityRejectsAnInvalidDate(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);

        $component
            ->set('formTitle', 'Actividad')
            ->set('formStartDay', '32')->set('formStartMonth', '9')
            ->set('formEndDay', '30')->set('formEndMonth', '6')
            ->call('saveActivity');

        $this->em->clear();
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedCategory = self::getContainer()->get(\App\Repository\ActivityCategoryRepository::class)->findByIdAndCentre($category->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloadedCategory);
        self::assertCount(0, $activities->findByCategory($reloadedCategory));
    }

    /** Entity-level guard (Activity::setAutoComplete) surfaces as a form validation error, not a 500. */
    public function testSaveActivityRejectsAutoCompleteWithoutAFolder(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);

        $component
            ->set('formTitle', 'Actividad')
            ->set('formStartDay', '1')->set('formStartMonth', '9')
            ->set('formEndDay', '30')->set('formEndMonth', '6')
            ->set('formFolderId', '')
            ->set('formAutoComplete', true)
            ->call('saveActivity');

        $this->em->clear();
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedCategory = self::getContainer()->get(\App\Repository\ActivityCategoryRepository::class)->findByIdAndCentre($category->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloadedCategory);
        self::assertCount(0, $activities->findByCategory($reloadedCategory), 'autoComplete without a folder must be rejected before ever reaching Activity::setAutoComplete()');
    }

    public function testActivityCrudActionsAreDeniedWithoutResponsibilitiesPermission(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $teacher);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('startAddActivity');
    }

    public function testDeleteActivityRemovesIt(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $admin    = $this->admin();
        $this->persist($centre, $category, $activity, $admin);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $component->call('deleteActivity', ['id' => $activityId]);

        $this->em->clear();
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        self::assertNull($activities->findById($activityId));
    }

    // ── toggleStats / toggleAllSubmissions ───────────────────────────────────

    public function testToggleStatsAddsAndRemovesTheActivityId(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $admin    = $this->admin();
        $this->persist($centre, $category, $activity, $admin);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('toggleStats', ['activityId' => $activityId]);
        self::assertContains($activityId, $this->stringListProp($component, 'statsShown'));

        $component->call('toggleStats', ['activityId' => $activityId]);
        self::assertNotContains($activityId, $this->stringListProp($component, 'statsShown'));
    }

    public function testToggleAllSubmissionsAddsAndRemovesTheActivityId(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $admin    = $this->admin();
        $this->persist($centre, $category, $activity, $admin);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('toggleAllSubmissions', ['activityId' => $activityId]);
        self::assertContains($activityId, $this->stringListProp($component, 'expandedAllSubmissions'));

        $component->call('toggleAllSubmissions', ['activityId' => $activityId]);
        self::assertNotContains($activityId, $this->stringListProp($component, 'expandedAllSubmissions'));
    }

    // ── getMySlots / getOtherSlots / getMySubmissionStats ────────────────────

    /**
     * Driven through rendering (not through TestLiveComponent::component(), which reconstructs the
     * component outside any request cycle — the security token it needs for getUser()/teacher()
     * is only reliably in place during an actual mount/action request): "Mío" must render as the
     * teacher's own dropzone up front, "Ajeno" only once "Todas las entregas" is expanded.
     */
    public function testMySlotsAndOtherSlotsPartitionAllSlots(): void
    {
        $centre  = $this->centre();
        $category = $this->category($centre);
        $folder  = $this->folder($centre);
        $mine    = (new SpecificProfile())->setEducationalCentre($centre)->setName('Mío');
        $others  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Ajeno');
        $folder->addUploadProfile($mine);
        $folder->addUploadProfile($others);
        $activity = $this->activity($category)->setFolder($folder);

        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($mine, null, $teacher);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $mine, $others, $activity, $teacher, $assignment);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Mío', $html);
        self::assertStringNotContainsString('Ajeno', $html, '"Todas las entregas" is collapsed by default');

        $component->call('toggleAllSubmissions', ['activityId' => $activity->getId()->toRfc4122()]);
        $expandedHtml = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Ajeno', $expandedHtml);
    }

    public function testGetMySubmissionStatsCountsDeliveredAndAccepted(): void
    {
        $centre  = $this->centre();
        $category = $this->category($centre);
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $reviewerProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($reviewerProfile); // needsReview=true, so "aceptadas" also renders
        $activity = $this->activity($category)->setFolder($folder);

        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);

        $document = new Document($folder, 'Perfil');
        $document->setUploadProfile($profile, null);
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $teacher);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $reviewerProfile, $activity, $teacher, $assignment, $document, $file, $revision);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Entregadas 1/1', $html);
        self::assertStringContainsString('aceptadas 1/1', $html);
    }

    // ── markCompleted ─────────────────────────────────────────────────────────

    public function testMarkCompletedCreatesACompletionForANonAutoCompleteActivity(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category); // no folder ⇒ always manual
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('markCompleted', ['activityId' => $activityId]);

        $this->em->clear();
        /** @var \App\Repository\ActivityCompletionRepository $completions */
        $completions = self::getContainer()->get(\App\Repository\ActivityCompletionRepository::class);
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedActivity = $activities->findById($activityId);
        self::assertNotNull($reloadedActivity);
        /** @var \App\Repository\TeacherRepository $teachers */
        $teachers = self::getContainer()->get(\App\Repository\TeacherRepository::class);
        $reloadedTeacher = $teachers->findById($teacher->getId()->toRfc4122());
        self::assertNotNull($reloadedTeacher);
        self::assertNotNull($completions->findOneForOwner($reloadedActivity, $reloadedTeacher, null, null));
    }

    public function testMarkCompletedIsANoOpForAnAutoCompleteActivity(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $activity = $this->activity($category)->setFolder($folder)->setAutoComplete(true);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $activity, $teacher);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('markCompleted', ['activityId' => $activityId]);

        $this->em->clear();
        /** @var \App\Repository\ActivityCompletionRepository $completions */
        $completions = self::getContainer()->get(\App\Repository\ActivityCompletionRepository::class);
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedActivity = $activities->findById($activityId);
        self::assertNotNull($reloadedActivity);
        /** @var \App\Repository\TeacherRepository $teachers */
        $teachers = self::getContainer()->get(\App\Repository\TeacherRepository::class);
        $reloadedTeacher = $teachers->findById($teacher->getId()->toRfc4122());
        self::assertNotNull($reloadedTeacher);
        self::assertNull($completions->findOneForOwner($reloadedActivity, $reloadedTeacher, null, null));
    }

    public function testMarkCompletedDoesNotDuplicateAnExistingCompletion(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $existing = new ActivityCompletion($activity, $teacher, null, null, $teacher);
        $this->persist($centre, $category, $activity, $teacher, $existing);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('markCompleted', ['activityId' => $activityId]);

        $this->em->clear();
        /** @var \App\Repository\ActivityCompletionRepository $completions */
        $completions = self::getContainer()->get(\App\Repository\ActivityCompletionRepository::class);
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedActivity = $activities->findById($activityId);
        self::assertNotNull($reloadedActivity);
        self::assertCount(1, $completions->findBy(['activity' => $reloadedActivity]));
    }

    // ── Revision panel LiveActions ────────────────────────────────────────────

    private function documentWithApprovedRevision(Folder $folder, Teacher $uploader): Document
    {
        $document = new Document($folder, 'Doc');
        $file     = new DocumentFile(hash('sha256', 'x' . random_int(1, PHP_INT_MAX)), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->em->persist($file);
        $this->em->persist($document);
        $this->em->persist($revision);

        return $document;
    }

    public function testSetActiveRevisionIsDeniedWithoutManagePermission(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithApprovedRevision($folder, $uploader);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $uploader, $document);

        $this->loginAs($uploader, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('setActiveRevision', ['id' => $document->getId()->toRfc4122()]);
    }

    /** Only an admin/responsable de calidad may edit who uploaded a revision and when — a plain folder manager can only fix the version number. */
    public function testStartEditRevisionOnlyExposesUploaderAndDateFieldsToAQualityManager(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $responsibleProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($responsibleProfile);
        $manager    = $this->teacher('responsable');
        $assignment = new SpecificProfileAssignment($responsibleProfile, null, $manager);
        $document   = $this->documentWithApprovedRevision($folder, $manager);
        $revision   = $document->getActiveRevision();
        self::assertNotNull($revision);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $responsibleProfile, $manager, $assignment, $document);

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('startEditRevision', ['id' => $document->getId()->toRfc4122(), 'revisionId' => $revision->getId()->toRfc4122()]);

        self::assertSame('1', $this->stringProp($component, 'editVersionValue'));
        self::assertSame('', $this->stringProp($component, 'editUploadedById'), 'a plain folder manager (not admin/quality manager) must not see the uploader field populated');
    }

    public function testStartEditRevisionExposesUploaderAndDateFieldsToAQualityManager(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $qm     = $this->teacher('calidad');
        $centre->getQualityManagers()->add($qm);
        $document = $this->documentWithApprovedRevision($folder, $qm);
        $revision = $document->getActiveRevision();
        self::assertNotNull($revision);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $qm, $document);

        $this->loginAs($qm, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('startEditRevision', ['id' => $document->getId()->toRfc4122(), 'revisionId' => $revision->getId()->toRfc4122()]);

        self::assertSame($qm->getId()->toRfc4122(), $this->stringProp($component, 'editUploadedById'));
    }

    public function testDeleteRevisionDeniedToAPlainFolderManager(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $responsibleProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($responsibleProfile);
        $manager    = $this->teacher('responsable');
        $assignment = new SpecificProfileAssignment($responsibleProfile, null, $manager);
        $document   = $this->documentWithApprovedRevision($folder, $manager);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $responsibleProfile, $manager, $assignment, $document);

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('deleteRevision', ['id' => $document->getId()->toRfc4122()]);
    }

    public function testDeleteRevisionGrantedToAQualityManager(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $qm     = $this->teacher('calidad');
        $centre->getQualityManagers()->add($qm);
        $document = $this->documentWithApprovedRevision($folder, $qm);
        $revision = $document->getActiveRevision();
        self::assertNotNull($revision);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $qm, $document);
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($qm, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('askDeleteRevision', ['id' => $documentId, 'revisionId' => $revision->getId()->toRfc4122()]);
        $component->call('deleteRevision', ['id' => $documentId]);

        $this->em->clear();
        /** @var \App\Repository\DocumentRepository $documents */
        $documents = self::getContainer()->get(\App\Repository\DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getRevisions());
    }

    // ── Search ───────────────────────────────────────────────────────────────

    public function testSearchResultsAreEmptyBelowTwoCharacters(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre, 'Programaciones');
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        /** @var ActivityBrowserComponent $instance */
        $instance  = $component->set('searchQuery', 'p')->component();

        self::assertSame([], $instance->getCategorySearchResults());
    }

    public function testCategorySearchMarksDirectVsOtherProfiles(): void
    {
        $centre = $this->centre();
        $relevantCategory   = $this->category($centre, 'Programaciones didácticas');
        $irrelevantCategory = $this->category($centre, 'Programaciones de otro tipo');
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $relevantActivity = $this->activity($relevantCategory)->setFolder($folder);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);

        $this->persist($centre, $relevantCategory, $irrelevantCategory, $folder->getDocumentSection(), $folder, $profile, $relevantActivity, $teacher, $assignment);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        /** @var ActivityBrowserComponent $instance */
        $instance  = $component->set('searchQuery', 'Programaciones')->component();

        $results = $instance->getCategorySearchResults();
        self::assertCount(2, $results);

        $byName = [];
        foreach ($results as $r) {
            $byName[$r['category']->getName()] = $r['direct'];
        }
        self::assertTrue($byName['Programaciones didácticas']);
        self::assertFalse($byName['Programaciones de otro tipo']);
    }

    public function testClearSearchEmptiesTheQuery(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->set('searchQuery', 'algo');
        $component->call('clearSearch');

        self::assertSame('', $this->stringProp($component, 'searchQuery'));
    }
}
