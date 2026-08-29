<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\PersonName;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\DocumentFileRepository;
use App\Service\DocumentCreationService;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DocumentCreationServiceTest extends RepositoryTestCase
{
    private DocumentCreationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var DocumentCreationService $service */
        $service       = self::getContainer()->get(DocumentCreationService::class);
        $this->service = $service;
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

    private function teacher(string $username = 'docente'): Teacher
    {
        return (new Teacher(new PersonName('Nombre', 'Apellido')))->setUsername($username);
    }

    /** Builds a real temp file wrapped as an UploadedFile in "test mode" (bypasses is_uploaded_file()). */
    private function uploadedFile(string $content, string $originalName = 'archivo.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc_creation_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $originalName, 'application/pdf', null, true);
    }

    public function testStoreFileCreatesANewDocumentFileForNewContent(): void
    {
        $file = $this->service->storeFile('contenido único', 'text/plain', 'a.txt');

        self::assertSame('contenido único', $file->getContent());
        self::assertSame(hash('sha256', 'contenido único'), $file->getHash());
    }

    public function testStoreFileDeduplicatesByContentHash(): void
    {
        $first  = $this->service->storeFile('mismo contenido', 'text/plain', 'a.txt');
        $second = $this->service->storeFile('mismo contenido', 'text/plain', 'b.txt');

        self::assertSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());

        /** @var DocumentFileRepository $files */
        $files = self::getContainer()->get(DocumentFileRepository::class);
        $this->em->clear();
        self::assertNotNull($files->findByHash(hash('sha256', 'mismo contenido')));
    }

    public function testCreateWithFirstRevisionActivatesImmediatelyWhenFolderDoesNotRequireReview(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $teacher = $this->teacher();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher);

        $document = $this->service->createWithFirstRevision($folder, 'Documento', null, null, $this->uploadedFile('contenido'), $teacher);
        $this->em->flush();

        self::assertSame('Documento', $document->getName());
        self::assertCount(1, $document->getRevisions(), 'the bidirectional-sync fix: the fresh revision must be visible without reloading');
        self::assertNotNull($document->getActiveRevision());
        self::assertFalse($document->getActiveRevision()->isPendingReview());
        self::assertSame($teacher, $document->getActiveRevision()->getUploadedBy());
        self::assertSame(1, $document->getActiveRevision()->getVersion());
    }

    public function testCreateWithFirstRevisionStaysPendingWhenFolderRequiresReview(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $reviewer = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($reviewer);
        $teacher = $this->teacher();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $reviewer, $teacher);

        $document = $this->service->createWithFirstRevision($folder, 'Documento', null, null, $this->uploadedFile('contenido'), $teacher);
        $this->em->flush();

        self::assertNull($document->getActiveRevision(), 'a pending revision must never become active');
        self::assertNotNull($document->getPendingRevision());
        self::assertTrue($document->isPendingApproval());
    }

    public function testCreateWithFirstRevisionSetsUploadProfileAndListItem(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $listItem = (new ListItem())->setEducationalCentre($centre)->setName('Item');
        $teacher  = $this->teacher();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $listItem, $teacher);

        $document = $this->service->createWithFirstRevision($folder, 'Documento', $profile, $listItem, $this->uploadedFile('contenido'), $teacher);
        $this->em->flush();

        self::assertSame($profile, $document->getUploadProfile());
        self::assertSame($listItem, $document->getUploadListItem());
    }

    public function testCreateWithFirstRevisionRespectsExplicitVersion(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $teacher = $this->teacher();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher);

        $document = $this->service->createWithFirstRevision($folder, 'Documento', null, null, $this->uploadedFile('contenido'), $teacher, 7);
        $this->em->flush();

        self::assertTrue($document->hasVersion(7));
        self::assertNotNull($document->getActiveRevision());
        self::assertSame(7, $document->getActiveRevision()->getVersion());
    }

    /**
     * createWithFirstRevision() persists but deliberately never flushes on its own — so several
     * documents can be created atomically in one caller-controlled flush (see ActivityController's
     * multi-file submission upload). Reusing the same content across two calls, before any flush,
     * must reuse the same DocumentFile row rather than violating its content-hash uniqueness.
     */
    public function testMultipleDocumentsCanBeBatchedBeforeASingleFlush(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $teacher = $this->teacher();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher);

        $docA = $this->service->createWithFirstRevision($folder, 'A', null, null, $this->uploadedFile('igual'), $teacher);
        $docB = $this->service->createWithFirstRevision($folder, 'B', null, null, $this->uploadedFile('igual'), $teacher);

        $this->em->flush();

        self::assertNotSame($docA->getId()->toRfc4122(), $docB->getId()->toRfc4122());
        $revisionA = $docA->getActiveRevision();
        $revisionB = $docB->getActiveRevision();
        self::assertNotNull($revisionA);
        self::assertNotNull($revisionB);
        self::assertSame($revisionA->getFile()->getId()->toRfc4122(), $revisionB->getFile()->getId()->toRfc4122());
    }
}
