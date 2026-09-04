<?php

declare(strict_types=1);

namespace App\Tests\Integration\Component;

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
use App\Repository\DocumentRepository;
use App\Tests\Integration\ControllerTestCase;
use App\Twig\Components\SectionBrowserComponent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class SectionBrowserComponentTest extends ControllerTestCase
{
    use InteractsWithLiveComponents;

    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function section(EducationalCentre $centre, string $name = 'Sección'): DocumentSection
    {
        return (new DocumentSection())->setEducationalCentre($centre)->setName($name);
    }

    private function folder(DocumentSection $section, string $name = 'Carpeta'): Folder
    {
        return (new Folder())->setDocumentSection($section)->setName($name);
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function documentWithApprovedRevision(Folder $folder, Teacher $uploader, string $name = 'Doc'): Document
    {
        $document = new Document($folder, $name);
        $file     = new DocumentFile(hash('sha256', $name . random_int(1, PHP_INT_MAX)), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->em->persist($file);
        $this->em->persist($document);
        $this->em->persist($revision);

        return $document;
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

    private function stringProp(TestLiveComponent $component, string $key): string
    {
        $value = $this->props($component)[$key];
        self::assertIsString($value);

        return $value;
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

    /** @return array{centre: EducationalCentre, initialSectionId: string} */
    private function inSection(DocumentSection $section, EducationalCentre $centre): array
    {
        return ['centre' => $centre, 'initialSectionId' => $section->getId()->toRfc4122()];
    }

    // ── Rename document ───────────────────────────────────────────────────────

    public function testSaveRenameDocumentUpdatesTheName(): void
    {
        $centre   = $this->centre();
        $section  = $this->section($centre);
        $folder   = $this->folder($section);
        $manager  = $this->teacher('responsable');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $document   = $this->documentWithApprovedRevision($folder, $manager, 'Nombre viejo');
        $this->persist($centre, $section, $folder, $profile, $manager, $assignment, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('startRenameDocument', ['folderId' => $folderId, 'id' => $documentId]);
        $component->set('renameDocumentName', 'Nombre nuevo')->call('saveRenameDocument', ['folderId' => $folderId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertSame('Nombre nuevo', $reloaded->getName());
    }

    public function testSaveRenameDocumentRejectsAnEmptyName(): void
    {
        $centre   = $this->centre();
        $section  = $this->section($centre);
        $folder   = $this->folder($section);
        $manager  = $this->teacher('responsable');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $document   = $this->documentWithApprovedRevision($folder, $manager, 'Nombre original');
        $this->persist($centre, $section, $folder, $profile, $manager, $assignment, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('startRenameDocument', ['folderId' => $folderId, 'id' => $documentId]);
        $component->set('renameDocumentName', '   ')->call('saveRenameDocument', ['folderId' => $folderId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertSame('Nombre original', $reloaded->getName());
    }

    // ── Delete document ───────────────────────────────────────────────────────

    public function testDeleteDocumentGrantedToTheActiveRevisionsUploader(): void
    {
        $centre   = $this->centre();
        $section  = $this->section($centre);
        $folder   = $this->folder($section);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithApprovedRevision($folder, $uploader);
        $this->persist($centre, $section, $folder, $uploader, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('deleteDocument', ['folderId' => $folderId, 'id' => $documentId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        self::assertNull($documents->findById($documentId));
    }

    public function testDeleteDocumentDeniedToAStranger(): void
    {
        $centre   = $this->centre();
        $section  = $this->section($centre);
        $folder   = $this->folder($section);
        $uploader = $this->teacher('subidor');
        $stranger = $this->teacher('ajeno');
        $document = $this->documentWithApprovedRevision($folder, $uploader);
        $this->persist($centre, $section, $folder, $uploader, $stranger, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($stranger, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('deleteDocument', ['folderId' => $folderId, 'id' => $documentId]);
    }

    // ── Reorder documents ─────────────────────────────────────────────────────

    public function testMoveDocumentUpAndDownSwapPositions(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $manager = $this->teacher('responsable');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);

        $first  = $this->documentWithApprovedRevision($folder, $manager, 'A');
        $first->setPosition(0);
        $second = $this->documentWithApprovedRevision($folder, $manager, 'B');
        $second->setPosition(1);

        $this->persist($centre, $section, $folder, $profile, $manager, $assignment, $first, $second);
        $folderId  = $folder->getId()->toRfc4122();
        $firstId   = $first->getId()->toRfc4122();
        $secondId  = $second->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('moveDocumentDown', ['folderId' => $folderId, 'id' => $firstId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents      = self::getContainer()->get(DocumentRepository::class);
        $reloadedFirst  = $documents->findById($firstId);
        $reloadedSecond = $documents->findById($secondId);
        self::assertNotNull($reloadedFirst);
        self::assertNotNull($reloadedSecond);
        self::assertSame(1, $reloadedFirst->getPosition());
        self::assertSame(0, $reloadedSecond->getPosition());
    }

    public function testSortDocumentsAlphabeticallyReordersByName(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $manager = $this->teacher('responsable');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);

        $zebra = $this->documentWithApprovedRevision($folder, $manager, 'Zebra');
        $zebra->setPosition(0);
        $alpha = $this->documentWithApprovedRevision($folder, $manager, 'Alpha');
        $alpha->setPosition(1);

        $this->persist($centre, $section, $folder, $profile, $manager, $assignment, $zebra, $alpha);
        $folderId = $folder->getId()->toRfc4122();
        $zebraId  = $zebra->getId()->toRfc4122();
        $alphaId  = $alpha->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('sortDocumentsAlphabetically', ['folderId' => $folderId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents      = self::getContainer()->get(DocumentRepository::class);
        $reloadedZebra  = $documents->findById($zebraId);
        $reloadedAlpha  = $documents->findById($alphaId);
        self::assertNotNull($reloadedZebra);
        self::assertNotNull($reloadedAlpha);
        self::assertLessThan($reloadedZebra->getPosition(), $reloadedAlpha->getPosition());
    }

    // ── setActiveRevision ────────────────────────────────────────────────────

    public function testSetActiveRevisionDeniedWithoutManagePermission(): void
    {
        $centre   = $this->centre();
        $section  = $this->section($centre);
        $folder   = $this->folder($section);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithApprovedRevision($folder, $uploader);
        $this->persist($centre, $section, $folder, $uploader, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('setActiveRevision', ['folderId' => $folderId, 'id' => $documentId]);
    }

    public function testSetActiveRevisionRejectsANonApprovedRevision(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $manager = $this->teacher('responsable');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);

        $document = new Document($folder, 'Doc');
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $pending  = new DocumentRevision($document, 1, $file, true, $manager);
        $document->getRevisions()->add($pending);

        $this->persist($centre, $section, $folder, $profile, $manager, $assignment, $document, $file, $pending);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();
        $revisionId = $pending->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('setActiveRevision', ['folderId' => $folderId, 'id' => $documentId, 'revisionId' => $revisionId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getActiveRevision());
    }

    public function testSetActiveRevisionWithNoRevisionIdClearsIt(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $manager = $this->teacher('responsable');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $document   = $this->documentWithApprovedRevision($folder, $manager);

        $this->persist($centre, $section, $folder, $profile, $manager, $assignment, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('setActiveRevision', ['folderId' => $folderId, 'id' => $documentId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getActiveRevision());
    }

    // ── saveEditRevision ─────────────────────────────────────────────────────

    public function testSaveEditRevisionRejectsAVersionAlreadyInUse(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $manager = $this->teacher('responsable');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);

        $document = new Document($folder, 'Doc');
        $file1    = new DocumentFile(hash('sha256', '1'), '1', 'text/plain', 'f1.txt', 1);
        $rev1     = new DocumentRevision($document, 1, $file1, false, $manager);
        $document->getRevisions()->add($rev1);
        $document->setActiveRevision($rev1);
        $file2 = new DocumentFile(hash('sha256', '2'), '2', 'text/plain', 'f2.txt', 1);
        $rev2  = new DocumentRevision($document, 2, $file2, false, $manager);
        $document->getRevisions()->add($rev2);

        $this->persist($centre, $section, $folder, $profile, $manager, $assignment, $document, $file1, $rev1, $file2, $rev2);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('startEditRevision', ['folderId' => $folderId, 'id' => $documentId, 'revisionId' => $rev2->getId()->toRfc4122()]);
        $component->set('editVersionValue', '1')->call('saveEditRevision', ['folderId' => $folderId, 'id' => $documentId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasVersion(1));
        self::assertTrue($reloaded->hasVersion(2), 'the rejected rename must leave the original version untouched');
    }

    public function testSaveEditRevisionOnlyLetsAQualityManagerRewriteUploaderAndDate(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $manager = $this->teacher('responsable');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $document   = $this->documentWithApprovedRevision($folder, $manager);
        $revision   = $document->getActiveRevision();
        self::assertNotNull($revision);

        $this->persist($centre, $section, $folder, $profile, $manager, $assignment, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('startEditRevision', ['folderId' => $folderId, 'id' => $documentId, 'revisionId' => $revision->getId()->toRfc4122()]);
        $component->set('editVersionValue', '3')->call('saveEditRevision', ['folderId' => $folderId, 'id' => $documentId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasVersion(3));
        $reloadedRevision = $reloaded->getActiveRevision();
        self::assertNotNull($reloadedRevision);
        self::assertSame('responsable', $reloadedRevision->getUploadedBy()->getUsername(), 'a plain folder manager cannot rewrite the uploader — it stays whoever it already was');
    }

    // ── deleteRevision ───────────────────────────────────────────────────────

    public function testDeleteRevisionDeniedToAPlainFolderManager(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $manager = $this->teacher('responsable');
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $document   = $this->documentWithApprovedRevision($folder, $manager);
        $this->persist($centre, $section, $folder, $profile, $manager, $assignment, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('deleteRevision', ['folderId' => $folderId, 'id' => $documentId]);
    }

    public function testDeleteRevisionGrantedToAQualityManagerClearsActiveRevision(): void
    {
        $centre = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $qm      = $this->teacher('calidad');
        $centre->getQualityManagers()->add($qm);
        $document = $this->documentWithApprovedRevision($folder, $qm);
        $revision = $document->getActiveRevision();
        self::assertNotNull($revision);
        $this->persist($centre, $section, $folder, $qm, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($qm, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('askDeleteRevision', ['folderId' => $folderId, 'id' => $documentId, 'revisionId' => $revision->getId()->toRfc4122()]);
        $component->call('deleteRevision', ['folderId' => $folderId, 'id' => $documentId]);

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getRevisions());
        self::assertNull($reloaded->getActiveRevision());
    }

    // ── Search ───────────────────────────────────────────────────────────────

    public function testOpenSearchResultNavigatesToTheDocumentsSectionAndHighlightsIt(): void
    {
        $centre   = $this->centre();
        $section  = $this->section($centre);
        $folder   = $this->folder($section);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithApprovedRevision($folder, $uploader, 'Documento buscado');
        $this->persist($centre, $section, $folder, $uploader, $document);
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', ['centre' => $centre], $this->client);
        $component->call('openSearchResult', ['documentId' => $documentId]);

        self::assertSame($section->getId()->toRfc4122(), $this->stringProp($component, 'currentSectionId'));
        self::assertSame($folder->getId()->toRfc4122(), $this->stringProp($component, 'expandedFolderId'));
        self::assertSame($documentId, $this->stringProp($component, 'highlightedDocumentId'));
    }

    public function testSearchResultsAreEmptyBelowTwoCharacters(): void
    {
        $centre   = $this->centre();
        $section  = $this->section($centre);
        $folder   = $this->folder($section);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithApprovedRevision($folder, $uploader, 'Documento');
        $this->persist($centre, $section, $folder, $uploader, $document);

        $this->loginAs($uploader, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', ['centre' => $centre], $this->client);
        /** @var SectionBrowserComponent $instance */
        $instance  = $component->set('searchQuery', 'D')->component();

        self::assertSame([], $instance->getSearchResults());
    }

    public function testGetSearchResultsFiltersOutDocumentsTheTeacherCannotView(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $visibilityProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Visible');
        $folder->addVisibilityProfile($visibilityProfile);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithApprovedRevision($folder, $uploader, 'Documento buscable');
        $stranger = $this->teacher('ajeno');
        $this->persist($centre, $section, $folder, $visibilityProfile, $uploader, $stranger, $document);

        $this->loginAs($stranger, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', ['centre' => $centre], $this->client);
        /** @var SectionBrowserComponent $instance */
        $instance  = $component->set('searchQuery', 'buscable')->component();

        self::assertSame([], $instance->getSearchResults(), 'a document in a visibility-restricted folder must never show up in search for a teacher without that profile');
    }

    public function testOpenSearchResultDeniedWhenVisibilityRestrictsTheTeacher(): void
    {
        $centre  = $this->centre();
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $visibilityProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Visible');
        $folder->addVisibilityProfile($visibilityProfile);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithApprovedRevision($folder, $uploader, 'Documento restringido');
        $stranger = $this->teacher('ajeno');
        $this->persist($centre, $section, $folder, $visibilityProfile, $uploader, $stranger, $document);
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($stranger, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', ['centre' => $centre], $this->client);

        $this->expectException(AccessDeniedException::class);
        $component->call('openSearchResult', ['documentId' => $documentId]);
    }

    // ── Folder description ──────────────────────────────────────────────────

    public function testToggleFolderSettingsPrefillsTheDescriptionField(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $section = $this->section($centre);
        $folder  = $this->folder($section)->setDescription('<p>Normativa vigente</p>');
        $this->persist($centre, $manager, $section, $folder);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('toggleFolderSettings', ['id' => $folderId]);

        self::assertSame('<p>Normativa vigente</p>', $this->stringProp($component, 'editDescriptionValue'));
    }

    public function testSaveFolderProfilesPersistsTheDescription(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $this->persist($centre, $manager, $section, $folder);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('toggleFolderSettings', ['id' => $folderId]);
        $component->set('editDescriptionValue', '<p>Consultar antes de subir</p>')->call('saveFolderProfiles', ['id' => $folderId]);

        $this->em->clear();
        $reloaded = $this->em->find(Folder::class, $folderId);
        self::assertSame('<p>Consultar antes de subir</p>', $reloaded->getDescription());
    }

    public function testSaveFolderProfilesStoresABlankDescriptionAsNull(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $section = $this->section($centre);
        $folder  = $this->folder($section)->setDescription('<p>Vieja</p>');
        $this->persist($centre, $manager, $section, $folder);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('toggleFolderSettings', ['id' => $folderId]);
        $component->set('editDescriptionValue', '   ')->call('saveFolderProfiles', ['id' => $folderId]);

        $this->em->clear();
        $reloaded = $this->em->find(Folder::class, $folderId);
        self::assertNull($reloaded->getDescription());
    }

    // ── Allowed file formats ──────────────────────────────────────────────────

    public function testToggleFolderSettingsPrefillsTheAllowedFormats(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $section = $this->section($centre);
        $folder  = $this->folder($section)->setAllowedFormats([AllowedFileFormat::Image, AllowedFileFormat::NonEditableDocument]);
        $this->persist($centre, $manager, $section, $folder);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('toggleFolderSettings', ['id' => $folderId]);

        $keys = $this->stringListProp($component, 'allowedFormatKeys');
        sort($keys);
        self::assertSame(['image', 'non_editable_document'], $keys);
    }

    public function testSaveFolderProfilesPersistsTheAllowedFormats(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $this->persist($centre, $manager, $section, $folder);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('toggleFolderSettings', ['id' => $folderId]);
        $component->set('allowedFormatKeys', ['spreadsheet', 'text'])->call('saveFolderProfiles', ['id' => $folderId]);

        $this->em->clear();
        /** @var Folder $reloaded */
        $reloaded = $this->em->find(Folder::class, $folderId);
        self::assertSame([AllowedFileFormat::Spreadsheet, AllowedFileFormat::Text], $reloaded->getAllowedFormats());
    }

    public function testSaveFolderProfilesClearsTheAllowedFormatsWhenNoneAreChecked(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $section = $this->section($centre);
        $folder  = $this->folder($section)->setAllowedFormats([AllowedFileFormat::Image]);
        $this->persist($centre, $manager, $section, $folder);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('toggleFolderSettings', ['id' => $folderId]);
        $component->set('allowedFormatKeys', [])->call('saveFolderProfiles', ['id' => $folderId]);

        $this->em->clear();
        /** @var Folder $reloaded */
        $reloaded = $this->em->find(Folder::class, $folderId);
        self::assertFalse($reloaded->isFormatRestricted());
    }

    /**
     * saveFolderProfiles() must not blow up on a stale/tampered request whose allowedFormatKeys
     * includes a value outside AllowedFileFormat's fixed set — it should just be dropped, the same
     * defensive stance as an unrecognised profile key.
     */
    public function testSaveFolderProfilesIgnoresAnUnrecognisedFormatKey(): void
    {
        $centre  = $this->centre();
        $manager = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $this->persist($centre, $manager, $section, $folder);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $component = $this->createLiveComponent('SectionBrowserComponent', $this->inSection($section, $centre), $this->client);
        $component->call('toggleFolderSettings', ['id' => $folderId]);
        $component->set('allowedFormatKeys', ['image', 'no-existe'])->call('saveFolderProfiles', ['id' => $folderId]);

        $this->em->clear();
        /** @var Folder $reloaded */
        $reloaded = $this->em->find(Folder::class, $folderId);
        self::assertSame([AllowedFileFormat::Image], $reloaded->getAllowedFormats());
    }

    public function testExpandedFolderShowsTheSanitizedDescription(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $section = $this->section($centre);
        $folder  = $this->folder($section)->setDescription('<p>Ver la <strong>normativa</strong></p><script>alert(1)</script>');
        $this->persist($centre, $teacher, $section, $folder);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent(
            'SectionBrowserComponent',
            array_merge($this->inSection($section, $centre), ['initialFolderId' => $folder->getId()->toRfc4122()]),
            $this->client,
        );

        $html = (string) $component->render()->crawler()->html();
        self::assertStringContainsString('Ver la <strong>normativa</strong>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testAFolderWithNoDescriptionShowsNoDescriptionBlock(): void
    {
        $centre  = $this->centre();
        $teacher = $this->teacher('docente');
        $section = $this->section($centre);
        $folder  = $this->folder($section);
        $this->persist($centre, $teacher, $section, $folder);

        $this->loginAs($teacher, $centre);
        $component = $this->createLiveComponent(
            'SectionBrowserComponent',
            array_merge($this->inSection($section, $centre), ['initialFolderId' => $folder->getId()->toRfc4122()]),
            $this->client,
        );

        $html = (string) $component->render()->crawler()->html();
        self::assertStringNotContainsString('prose', $html);
    }
}
