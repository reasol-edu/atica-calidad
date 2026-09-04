<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityCompletion;
use App\Entity\AllowedFileFormat;
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

    // ── Browser history (back/forward through categories) ───────────────────

    public function testSyncCategoryFromUrlRestoresACategoryFromTheBrowsersBackForwardButtons(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre, 'Programaciones');
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('syncCategoryFromUrl', ['category' => $category->getId()->toRfc4122()]);

        self::assertSame($category->getId()->toRfc4122(), $this->stringProp($component, 'currentCategoryId'));
    }

    public function testSyncCategoryFromUrlWithAnEmptyValueGoesBackToTheRoot(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre, 'Programaciones');
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $component->call('syncCategoryFromUrl', ['category' => '']);

        self::assertSame('', $this->stringProp($component, 'currentCategoryId'));
    }

    /** A stale or tampered category id in the URL (e.g. from another centre, or since deleted) falls back to the root instead of erroring. */
    public function testSyncCategoryFromUrlWithAnUnknownIdFallsBackToTheRoot(): void
    {
        $centre = $this->centre();
        $admin  = $this->admin();
        $this->persist($centre, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('syncCategoryFromUrl', ['category' => '00000000-0000-0000-0000-000000000000']);

        self::assertSame('', $this->stringProp($component, 'currentCategoryId'));
    }

    public function testSyncCategoryFromUrlResetsTransientStateLikeOpenLevelDoes(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre, 'Programaciones');
        $admin    = $this->admin();
        $this->persist($centre, $category, $admin);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('startAddActivity');
        self::assertTrue((bool) $this->props($component)['activityFormOpen']);

        $component->call('syncCategoryFromUrl', ['category' => $category->getId()->toRfc4122()]);

        self::assertFalse((bool) $this->props($component)['activityFormOpen']);
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

    // ── Related documents ─────────────────────────────────────────────────────

    private function documentWithActiveRevision(Folder $folder, string $name, Teacher $uploader): Document
    {
        $document = new Document($folder, $name);
        $file     = new DocumentFile(hash('sha256', $name . random_int(1, PHP_INT_MAX)), $name, 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->em->persist($file);
        $this->em->persist($revision);

        return $document;
    }

    public function testSaveActivityAttachesDocumentsStagedInFormRelatedDocumentIds(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $admin    = $this->admin();
        $document = $this->documentWithActiveRevision($folder, 'Norma de convivencia', $admin);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $admin, $document);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);

        $component
            ->set('formTitle', 'Actividad')
            ->set('formStartDay', '1')->set('formStartMonth', '9')
            ->set('formEndDay', '30')->set('formEndMonth', '6')
            ->set('formRelatedDocumentIds', [$document->getId()->toRfc4122()])
            ->call('saveActivity');

        $this->em->clear();
        /** @var \App\Repository\ActivityRepository $activities */
        $activities        = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedCategory  = self::getContainer()->get(\App\Repository\ActivityCategoryRepository::class)->findByIdAndCentre($category->getId()->toRfc4122(), $centre);
        self::assertNotNull($reloadedCategory);
        $created = $activities->findByCategory($reloadedCategory);
        self::assertCount(1, $created);
        self::assertCount(1, $created[0]->getRelatedDocuments());
        self::assertSame('Norma de convivencia', $created[0]->getRelatedDocuments()->first()->getName());
    }

    public function testSaveActivityRemovesRelatedDocumentsNoLongerStaged(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $admin    = $this->admin();
        $document = $this->documentWithActiveRevision($folder, 'Norma de convivencia', $admin);
        $activity = $this->activity($category)->addRelatedDocument($document);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $admin, $document, $activity);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $component->call('startEditActivity', ['id' => $activityId]);
        $component
            ->set('formRelatedDocumentIds', [])
            ->call('saveActivity');

        $this->em->clear();
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloaded   = $activities->findById($activityId);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getRelatedDocuments());
    }

    public function testStartEditActivityPopulatesFormRelatedDocumentIds(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $admin    = $this->admin();
        $document = $this->documentWithActiveRevision($folder, 'Norma de convivencia', $admin);
        $activity = $this->activity($category)->addRelatedDocument($document);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $admin, $document, $activity);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $component->call('startEditActivity', ['id' => $activity->getId()->toRfc4122()]);

        self::assertSame([$document->getId()->toRfc4122()], $this->stringListProp($component, 'formRelatedDocumentIds'));
    }

    public function testRelatedDocumentSearchMatchesByFolderNameAndExcludesAlreadyStagedDocuments(): void
    {
        $centre    = $this->centre();
        $category  = $this->category($centre);
        $folder    = $this->folder($centre);
        $folder->setName('Normativa de convivencia');
        $admin     = $this->admin();
        $matching  = $this->documentWithActiveRevision($folder, 'Documento sin relación por nombre', $admin);
        $staged    = $this->documentWithActiveRevision($folder, 'Ya añadido', $admin);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $admin, $matching, $staged);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $component
            ->set('formRelatedDocumentIds', [$staged->getId()->toRfc4122()])
            ->set('relatedDocumentSearchQuery', 'convivencia')
            ->render();

        /** @var ActivityBrowserComponent $instance */
        $instance = $component->component();
        $results  = $instance->getRelatedDocumentSearchResults();

        self::assertCount(1, $results, 'matches by folder name, and excludes the already-staged document');
        self::assertSame('Documento sin relación por nombre', $results[0]['document']->getName());
    }

    public function testAddRelatedDocumentAppendsItAndClearsTheSearchQuery(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $admin    = $this->admin();
        $document = $this->documentWithActiveRevision($folder, 'Norma de convivencia', $admin);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $admin, $document);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $component
            ->set('relatedDocumentSearchQuery', 'convivencia')
            ->call('addRelatedDocument', ['id' => $document->getId()->toRfc4122()]);

        self::assertSame([$document->getId()->toRfc4122()], $this->stringListProp($component, 'formRelatedDocumentIds'));
        self::assertSame('', $this->stringProp($component, 'relatedDocumentSearchQuery'));
    }

    public function testRemoveRelatedDocumentRemovesItFromTheStagedIds(): void
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
            ->set('formRelatedDocumentIds', ['one', 'two'])
            ->call('removeRelatedDocument', ['id' => 'one']);

        self::assertSame(['two'], $this->stringListProp($component, 'formRelatedDocumentIds'));
    }

    public function testActivityViewRendersADownloadLinkAndATreeLinkForARelatedDocumentWithARevision(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $admin    = $this->admin();
        $document = $this->documentWithActiveRevision($folder, 'Norma de convivencia', $admin);
        $activity = $this->activity($category)->setDescription('Descripción')->addRelatedDocument($document);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $admin, $document, $activity);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $html = (string) $component->render()->crawler()->html();

        self::assertStringContainsString('Norma de convivencia', $html);
        self::assertStringContainsString('/documentos/' . $document->getId()->toRfc4122() . '/revisiones/', $html);
        self::assertStringContainsString('/arbol-documental?section=', $html);
    }

    public function testActivityViewHidesARelatedDocumentTheViewingTeacherCannotSee(): void
    {
        $centre    = $this->centre();
        $category  = $this->category($centre);
        $folder    = $this->folder($centre);
        $restrictedProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Restringido');
        $folder->addVisibilityProfile($restrictedProfile);
        $admin    = $this->admin();
        $document = $this->documentWithActiveRevision($folder, 'Documento restringido', $admin);
        $activity = $this->activity($category)->addRelatedDocument($document);
        $outsider = $this->teacher('sin-acceso');
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $restrictedProfile, $admin, $document, $activity, $outsider);

        $this->loginAs($outsider, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $html = (string) $component->render()->crawler()->html();

        self::assertStringNotContainsString('Documento restringido', $html);
    }

    public function testActivityViewRendersATreeLinkToItsOwnFolderWhenVisible(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $admin    = $this->admin();
        $activity = $this->activity($category)->setFolder($folder);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $admin, $activity);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $html = (string) $component->render()->crawler()->html();

        self::assertStringContainsString('Ir a la carpeta', $html);
        // The rendered href HTML-escapes "&" to "&amp;" between query parameters — check each
        // param on its own rather than the literal query string.
        self::assertStringContainsString('/arbol-documental?section=' . $folder->getDocumentSection()->getId()->toRfc4122(), $html);
        self::assertStringContainsString('folder=' . $folder->getId()->toRfc4122(), $html);
    }

    /**
     * The activity is still relevant to the teacher (they can upload to the folder), but a
     * visibility restriction unrelated to the upload profile blocks the folder itself — the "go
     * to folder" link must not offer a destination the teacher can't actually reach.
     */
    public function testActivityViewHidesTheFolderLinkWhenTheFolderIsNotVisibleToTheViewer(): void
    {
        $centre     = $this->centre();
        $category   = $this->category($centre);
        $folder     = $this->folder($centre);
        $tutor      = (new SpecificProfile())->setEducationalCentre($centre)->setName('Tutor/a');
        $restricted = (new SpecificProfile())->setEducationalCentre($centre)->setName('Restringido');
        $folder->addUploadProfile($tutor);
        $folder->addVisibilityProfile($restricted);
        $activity   = $this->activity($category)->setFolder($folder);
        $teacher    = $this->teacher('tutor');
        $assignment = new SpecificProfileAssignment($tutor, null, $teacher);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $tutor, $restricted, $activity, $teacher, $assignment);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $html = (string) $component->render()->crawler()->html();

        self::assertStringContainsString($activity->getTitle(), $html, 'the activity itself is still relevant — the teacher can upload to it');
        self::assertStringNotContainsString('Ir a la carpeta', $html);
    }

    public function testActivityViewShowsTheFormatNoticeBeforeMySubmissionsWhenRestrictedWithAnOpenSlot(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $folder->setAllowedFormats([AllowedFileFormat::Image, AllowedFileFormat::NonEditableDocument]);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $activity   = $this->activity($category)->setFolder($folder);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assignment);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $html = (string) $component->render()->crawler()->html();

        self::assertStringContainsString('Formatos aceptados:', $html);
        self::assertStringContainsString('Documento no editable', $html);
        self::assertStringContainsString('Imágenes', $html);
    }

    public function testActivityViewHidesTheFormatNoticeWhenTheFolderIsNotRestricted(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $activity   = $this->activity($category)->setFolder($folder);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assignment);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $html = (string) $component->render()->crawler()->html();

        self::assertStringNotContainsString('Formatos aceptados:', $html);
    }

    /** No dropzone is offered once every one of the teacher's own slots is already filled, so the restriction isn't relevant to show right now. */
    public function testActivityViewHidesTheFormatNoticeWhenAllOfMySlotsAreAlreadyFilled(): void
    {
        $centre  = $this->centre();
        $category = $this->category($centre);
        $folder  = $this->folder($centre);
        $folder->setAllowedFormats([AllowedFileFormat::Image]);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $activity   = $this->activity($category)->setFolder($folder);
        $teacher    = $this->teacher('docente');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);

        $document = new Document($folder, 'Perfil');
        $document->setUploadProfile($profile, null);
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'image/jpeg', 'f.jpg', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $teacher);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $teacher, $assignment, $document, $file, $revision);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $html = (string) $component->render()->crawler()->html();

        self::assertStringNotContainsString('Formatos aceptados:', $html);
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

    public function testToggleRevisionPanelExpandsTheRevisionManagementPanel(): void
    {
        $centre  = $this->centre();
        $category = $this->category($centre);
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $folder->addUploadProfile($profile);
        $activity = $this->activity($category)->setFolder($folder);

        $admin = $this->admin();
        $document = new Document($folder, 'Perfil');
        $document->setUploadProfile($profile, null);
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $admin);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $profile, $activity, $admin, $document, $file, $revision);

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);

        $panelMarker = 'ml-4 space-y-1.5 border-l-2';

        $htmlBefore = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString($panelMarker, $htmlBefore);

        $component->call('toggleRevisionPanel', ['id' => $document->getId()->toRfc4122()]);

        /** @var \App\Twig\Components\ActivityBrowserComponent $instance */
        $instance = $component->component();
        self::assertSame($document->getId()->toRfc4122(), $instance->revisionPanelDocumentId);

        $htmlAfter = (string) $component->render()->crawler()->html();
        self::assertStringContainsString($panelMarker, $htmlAfter);
    }

    /**
     * A reviewer (not the folder's responsible/manager) viewing someone else's submission under
     * "Todas las entregas" — the read-only branch of _activity_submission_row.html.twig, a
     * different code path than the "Mis entregas" one covered above.
     */
    public function testToggleRevisionPanelExpandsThePanelForAReviewerInTheOtherSubmissionsSection(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $uploadProfile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Ajeno');
        $reviewProfile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addUploadProfile($uploadProfile);
        $folder->addReviewProfile($reviewProfile);
        $activity = $this->activity($category)->setFolder($folder);

        $reviewer  = $this->teacher('revisor');
        $uploader  = $this->teacher('subidor');
        $reviewAssignment = new SpecificProfileAssignment($reviewProfile, null, $reviewer);

        $document = new Document($folder, 'Ajeno');
        $document->setUploadProfile($uploadProfile, null);
        $file     = new DocumentFile(hash('sha256', 'y'), 'y', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);

        $this->persist($centre, $category, $folder->getDocumentSection(), $folder, $uploadProfile, $reviewProfile, $activity, $reviewer, $uploader, $reviewAssignment, $document, $file, $revision);

        $this->loginAs($reviewer, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $component->call('toggleAllSubmissions', ['activityId' => $activity->getId()->toRfc4122()]);

        $panelMarker = 'ml-4 space-y-1.5 border-l-2';
        $htmlBefore  = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Ajeno', $htmlBefore, 'sanity check: the read-only row is visible');
        self::assertStringNotContainsString($panelMarker, $htmlBefore);

        $component->call('toggleRevisionPanel', ['id' => $document->getId()->toRfc4122()]);

        /** @var \App\Twig\Components\ActivityBrowserComponent $instance */
        $instance = $component->component();
        self::assertSame($document->getId()->toRfc4122(), $instance->revisionPanelDocumentId);

        $htmlAfter = (string) $component->render()->crawler()->html();
        self::assertStringContainsString($panelMarker, $htmlAfter);
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

    public function testAskMarkCompletedSetsTheConfirmationKeyWithoutCompletingYet(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('askMarkCompleted', ['activityId' => $activityId]);

        /** @var \App\Twig\Components\ActivityBrowserComponent $instance */
        $instance = $component->component();
        self::assertSame($activityId . '::', $instance->confirmingCompleteKey);

        $this->em->clear();
        /** @var \App\Repository\ActivityCompletionRepository $completions */
        $completions = self::getContainer()->get(\App\Repository\ActivityCompletionRepository::class);
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedActivity = $activities->findById($activityId);
        self::assertNotNull($reloadedActivity);
        self::assertCount(0, $completions->findBy(['activity' => $reloadedActivity]));
    }

    public function testCancelMarkCompletedClearsTheConfirmationKeyWithoutCompleting(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('askMarkCompleted', ['activityId' => $activityId]);
        $component->call('cancelMarkCompleted');

        /** @var \App\Twig\Components\ActivityBrowserComponent $instance */
        $instance = $component->component();
        self::assertSame('', $instance->confirmingCompleteKey);
    }

    public function testMarkCompletedClearsTheConfirmationKeyAfterCompleting(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('askMarkCompleted', ['activityId' => $activityId]);
        $component->call('markCompleted', ['activityId' => $activityId]);

        /** @var \App\Twig\Components\ActivityBrowserComponent $instance */
        $instance = $component->component();
        self::assertSame('', $instance->confirmingCompleteKey);
    }

    public function testUnmarkCompletedRemovesAnExistingCompletionWithoutConfirmation(): void
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
        $component->call('unmarkCompleted', ['activityId' => $activityId]);

        $this->em->clear();
        /** @var \App\Repository\ActivityCompletionRepository $completions */
        $completions = self::getContainer()->get(\App\Repository\ActivityCompletionRepository::class);
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedActivity = $activities->findById($activityId);
        self::assertNotNull($reloadedActivity);
        self::assertCount(0, $completions->findBy(['activity' => $reloadedActivity]));
    }

    public function testAskMarkCompletedRendersTheConfirmationPromptInsteadOfCompleting(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $this->persist($centre, $category, $activity, $teacher);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre, 'initialActivityId' => $activityId], $this->client);
        $component->call('askMarkCompleted', ['activityId' => $activityId]);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('¿Confirmar como completada?', $html);
        self::assertStringNotContainsString('Completada', $html);
    }

    public function testACompletedActivityShowsAnUndoLinkInsteadOfTheDoneBadgeOnly(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $activity = $this->activity($category);
        $teacher  = $this->teacher('docente');
        $existing = new ActivityCompletion($activity, $teacher, null, null, $teacher);
        $this->persist($centre, $category, $activity, $teacher, $existing);
        $activityId = $activity->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', ['centre' => $centre, 'initialActivityId' => $activityId], $this->client);

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Completada', $html);
        self::assertStringContainsString('Deshacer', $html);
    }

    /**
     * Regression: the batch-upload <form> used to wrap every "Mis entregas" row, so an open
     * revision panel (which has its own approve/reject/new-revision <form> elements, which can't
     * nest inside another form) could only be rendered once, AFTER the whole form — always at the
     * end of the list, never next to the row that was actually toggled. Fixed by moving each row's
     * hidden/file inputs outside the form (linked via the HTML `form` attribute) so the panel can
     * render inline, right after its own row, like it already does under "Todas las entregas".
     */
    public function testTheRevisionPanelRendersRightAfterItsOwnRowNotAfterEveryOtherOne(): void
    {
        $centre   = $this->centre();
        $category = $this->category($centre);
        $folder   = $this->folder($centre);
        $profileA = (new SpecificProfile())->setEducationalCentre($centre)->setName('Alfa');
        $profileB = (new SpecificProfile())->setEducationalCentre($centre)->setName('Beta');
        $folder->addUploadProfile($profileA);
        $folder->addUploadProfile($profileB);
        $activity = $this->activity($category)->setFolder($folder);

        $admin = $this->admin();

        $documentA = new Document($folder, 'Alfa');
        $documentA->setUploadProfile($profileA, null);
        $fileA     = new DocumentFile(hash('sha256', 'a'), 'a', 'text/plain', 'a.txt', 1);
        $revisionA = new DocumentRevision($documentA, 1, $fileA, false, $admin);
        $documentA->getRevisions()->add($revisionA);
        $documentA->setActiveRevision($revisionA);

        $documentB = new Document($folder, 'Beta');
        $documentB->setUploadProfile($profileB, null);
        $fileB     = new DocumentFile(hash('sha256', 'b'), 'b', 'text/plain', 'b.txt', 1);
        $revisionB = new DocumentRevision($documentB, 1, $fileB, false, $admin);
        $documentB->getRevisions()->add($revisionB);
        $documentB->setActiveRevision($revisionB);

        $this->persist(
            $centre, $category, $folder->getDocumentSection(), $folder, $profileA, $profileB, $activity, $admin,
            $documentA, $fileA, $revisionA, $documentB, $fileB, $revisionB,
        );

        $this->loginAs($admin, $centre);
        $component = $this->createLiveComponent('ActivityBrowserComponent', [
            'centre'            => $centre,
            'initialCategoryId' => $category->getId()->toRfc4122(),
        ], $this->client);
        $component->call('toggleRevisionPanel', ['id' => $documentA->getId()->toRfc4122()]);

        $html = (string) $component->render()->crawler()->html();

        $panelMarker  = 'ml-4 space-y-1.5 border-l-2';
        $panelPos     = strpos($html, $panelMarker);
        $rowAPos      = strpos($html, 'Alfa');
        $rowBPos      = strpos($html, 'Beta');

        self::assertNotFalse($panelPos, 'the revision panel must render');
        self::assertNotFalse($rowAPos);
        self::assertNotFalse($rowBPos);
        self::assertGreaterThan($rowAPos, $panelPos, 'the panel must come after the row it belongs to');
        self::assertLessThan($rowBPos, $panelPos, 'the panel must come before the NEXT row, not after the whole list');
    }

    public function testUnmarkCompletedIsANoOpForAnAutoCompleteActivity(): void
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
        // Must not throw / error even though there's nothing persisted to remove.
        $component->call('unmarkCompleted', ['activityId' => $activityId]);

        $this->em->clear();
        /** @var \App\Repository\ActivityCompletionRepository $completions */
        $completions = self::getContainer()->get(\App\Repository\ActivityCompletionRepository::class);
        /** @var \App\Repository\ActivityRepository $activities */
        $activities = self::getContainer()->get(\App\Repository\ActivityRepository::class);
        $reloadedActivity = $activities->findById($activityId);
        self::assertNotNull($reloadedActivity);
        self::assertCount(0, $completions->findBy(['activity' => $reloadedActivity]));
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
