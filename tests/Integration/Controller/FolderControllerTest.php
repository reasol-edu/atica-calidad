<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Repository\EmailNotificationLogRepository;
use App\Repository\FolderRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FolderControllerTest extends ControllerTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function folder(EducationalCentre $centre): Folder
    {
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
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

    /**
     * Requires an active session (from loginAs()) — call only after logging in. Between requests,
     * KernelBrowser's request stack is empty (the previous request already finished handling), so
     * the CSRF token storage — which reads/writes the session via the CURRENT request on the stack
     * — has nothing to attach to; briefly pushing the last known request back onto the stack gives
     * it one, exactly like an in-flight request would have.
     */
    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
            // The session object on this already-finished request never gets a fresh
            // kernel.response pass to persist it — save explicitly, or the token this just wrote
            // never reaches the storage backend the NEXT (genuinely new) request reads from.
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function uploadedFile(string $content, string $originalName = 'archivo.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'folder_controller_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $originalName, 'application/pdf', null, true);
    }

    // ── upload() ──────────────────────────────────────────────────────────────

    public function testUploadCreatesADocumentAndRedirects(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $admin  = $this->admin();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $admin);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/arbol-documental/carpetas/{$folderId}/subir", [
            '_token' => $this->csrfToken('folder_upload_' . $folderId),
            'items'  => [0 => ['version' => '1']],
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var FolderRepository $folders */
        $folders  = self::getContainer()->get(FolderRepository::class);
        $reloaded = $folders->findById($folderId);
        self::assertNotNull($reloaded);
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $docs      = $documents->findByFolder($reloaded);
        self::assertCount(1, $docs);
        self::assertSame('archivo', $docs[0]->getName());
    }

    public function testUploadDeniedWithoutUploadPermission(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $teacher = $this->teacher('sin_permiso');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $teacher);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', "/arbol-documental/carpetas/{$folderId}/subir", [
            '_token' => $this->csrfToken('folder_upload_' . $folderId),
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadRejectedWithInvalidCsrfToken(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $admin  = $this->admin();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $admin);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/arbol-documental/carpetas/{$folderId}/subir", [
            '_token' => 'token-invalido',
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadWithNoFilesRedirectsWithoutCreatingAnything(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $admin  = $this->admin();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $admin);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/arbol-documental/carpetas/{$folderId}/subir", [
            '_token' => $this->csrfToken('folder_upload_' . $folderId),
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        self::assertCount(0, $documents->findByFolder($folder));
    }

    public function testUploadRejectedWhenContentLengthExceedsPostMaxSize(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $admin  = $this->admin();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $admin);
        $folderId = $folder->getId()->toRfc4122();

        $postMaxSize = self::parseIniSizeForTest((string) ini_get('post_max_size'));
        self::assertGreaterThan(0, $postMaxSize, 'this test requires a finite post_max_size to be meaningful');

        $this->loginAs($admin, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/subir",
            ['_token' => $this->csrfToken('folder_upload_' . $folderId)],
            ['files' => [0 => $this->uploadedFile('contenido')]],
            ['CONTENT_LENGTH' => (string) ($postMaxSize + 1)],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        self::assertCount(0, $documents->findByFolder($folder), 'the too-large guard must short-circuit before creating anything');
    }

    private static function parseIniSizeForTest(string $value): int
    {
        $value  = trim($value);
        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => $number,
        };
    }

    public function testUploadTagsWithAllowedProfileWhenGroupByProfileIsEnabled(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $folder->setGroupByProfile(true);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretario/a');
        $folder->addUploadProfile($profile);
        $teacher    = $this->teacher('secretario');
        $assignment = new SpecificProfileAssignment($profile, null, $teacher);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $teacher, $assignment);
        $folderId = $folder->getId()->toRfc4122();
        $rowKey   = $profile->getId()->toRfc4122();

        $this->loginAs($teacher, $centre);
        $this->client->request('POST', "/arbol-documental/carpetas/{$folderId}/subir", [
            '_token' => $this->csrfToken('folder_upload_' . $folderId),
            'items'  => [0 => ['profileKey' => $rowKey]],
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        /** @var FolderRepository $folders */
        $folders  = self::getContainer()->get(FolderRepository::class);
        $reloaded = $folders->findById($folderId);
        self::assertNotNull($reloaded);
        $docs = $documents->findByFolder($reloaded);
        self::assertCount(1, $docs);
        $uploadProfile = $docs[0]->getUploadProfile();
        self::assertNotNull($uploadProfile);
        self::assertSame($profile->getId()->toRfc4122(), $uploadProfile->getId()->toRfc4122());
    }

    // ── uploadRevision() ─────────────────────────────────────────────────────

    private function documentWithFirstRevision(Folder $folder, Teacher $uploader, string $name = 'Doc'): Document
    {
        $document = new Document($folder, $name);
        $file     = new DocumentFile(hash('sha256', $name . random_int(1, PHP_INT_MAX)), 'v1', 'text/plain', 'v1.txt', 2);
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->em->persist($file);
        $this->em->persist($document);
        $this->em->persist($revision);

        return $document;
    }

    public function testUploadRevisionGrantedToTheActiveRevisionsUploaderEvenWithoutManagePermission(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithFirstRevision($folder, $uploader);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $uploader, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones",
            ['_token' => $this->csrfToken('folder_document_revision_' . $documentId)],
            ['file' => $this->uploadedFile('v2')],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasVersion(2));
    }

    public function testUploadRevisionDeniedToAStrangerWhoIsNeitherManagerNorUploader(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $uploader = $this->teacher('subidor');
        $stranger = $this->teacher('ajeno');
        $document = $this->documentWithFirstRevision($folder, $uploader);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $uploader, $stranger, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($stranger, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones",
            ['_token' => $this->csrfToken('folder_document_revision_' . $documentId)],
            ['file' => $this->uploadedFile('v2')],
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadRevisionRejectedWithInvalidCsrfToken(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithFirstRevision($folder, $uploader);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $uploader, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones",
            ['_token' => 'invalido'],
            ['file' => $this->uploadedFile('v2')],
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testUploadRevisionByManagerCanSetAnExplicitVersion(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $manager    = $this->teacher('responsable');
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $original   = $this->teacher('otro');
        $document   = $this->documentWithFirstRevision($folder, $original);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $manager, $assignment, $original, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones",
            ['_token' => $this->csrfToken('folder_document_revision_' . $documentId), 'version' => '5'],
            ['file' => $this->uploadedFile('v5')],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->hasVersion(5));
    }

    public function testUploadRevisionByManagerRejectsAVersionAlreadyInUse(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Responsable');
        $folder->addResponsibleProfile($profile);
        $manager    = $this->teacher('responsable');
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $document   = $this->documentWithFirstRevision($folder, $manager);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $manager, $assignment, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($manager, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones",
            ['_token' => $this->csrfToken('folder_document_revision_' . $documentId), 'version' => '1'],
            ['file' => $this->uploadedFile('duplicado')],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getRevisions(), 'the duplicate-version attempt must not add a second revision');
    }

    // ── download() ────────────────────────────────────────────────────────────

    public function testDownloadReturnsTheFileContentWhenPermitted(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithFirstRevision($folder, $uploader);
        $revision = $document->getActiveRevision();
        self::assertNotNull($revision);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $uploader, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();
        $revisionId = $revision->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request('GET', "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones/{$revisionId}/descargar");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('v1', $this->getStreamedContent());
    }

    public function testDownloadDeniedWhenFolderVisibilityRestrictsTheTeacher(): void
    {
        $centre             = $this->centre();
        $folder             = $this->folder($centre);
        $visibilityProfile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Visible');
        $folder->addVisibilityProfile($visibilityProfile);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithFirstRevision($folder, $uploader);
        $revision = $document->getActiveRevision();
        self::assertNotNull($revision);
        $stranger = $this->teacher('ajeno');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $visibilityProfile, $uploader, $stranger, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();
        $revisionId = $revision->getId()->toRfc4122();

        $this->loginAs($stranger, $centre);
        $this->client->request('GET', "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones/{$revisionId}/descargar");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testDownloadOfAnUnknownRevisionIs404(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithFirstRevision($folder, $uploader);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $uploader, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request('GET', "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones/00000000-0000-0000-0000-000000000000/descargar");

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ── downloadZip() ────────────────────────────────────────────────────────

    /** @return array<string, string> entry path => content */
    private function readZipResponse(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'folder_zip_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $this->getStreamedContent());

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $out = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $out[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $out;
    }

    public function testDownloadZipBundlesEachDocumentsActiveRevision(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $uploader = $this->teacher('subidor');
        $doc1     = $this->documentWithFirstRevision($folder, $uploader, 'Manual de calidad');
        $doc2     = $this->documentWithFirstRevision($folder, $uploader, 'Política');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $uploader, $doc1, $doc2);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request('GET', "/arbol-documental/carpetas/{$folderId}/descargar-zip");

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/zip', $this->client->getResponse()->headers->get('Content-Type'));

        $files = $this->readZipResponse();
        ksort($files);
        self::assertSame(['Manual de calidad.txt' => 'v1', 'Política.txt' => 'v1'], $files);
    }

    public function testDownloadZipSkipsDocumentsWithoutAnActiveRevision(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $uploader = $this->teacher('subidor');
        $published = $this->documentWithFirstRevision($folder, $uploader, 'Aprobado');

        $pending  = new Document($folder, 'Pendiente');
        $file     = new DocumentFile(hash('sha256', 'pendiente'), 'p', 'text/plain', 'p.txt', 1);
        $revision = new DocumentRevision($pending, 1, $file, true, $uploader);
        $pending->getRevisions()->add($revision);
        $this->em->persist($file);
        $this->em->persist($pending);
        $this->em->persist($revision);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $uploader, $published);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request('GET', "/arbol-documental/carpetas/{$folderId}/descargar-zip");

        self::assertSame(['Aprobado.txt' => 'v1'], $this->readZipResponse());
    }

    public function testDownloadZipPutsEachUploadProfileInItsOwnSubdirectoryWhenGrouped(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $folder->setGroupByProfile(true);
        $profileA = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretaría');
        $profileB = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura de estudios');
        $uploader = $this->teacher('subidor');

        $docA = $this->documentWithFirstRevision($folder, $uploader, 'Acta');
        $docA->setUploadProfile($profileA);
        $docB = $this->documentWithFirstRevision($folder, $uploader, 'Horario');
        $docB->setUploadProfile($profileB);
        $docNone = $this->documentWithFirstRevision($folder, $uploader, 'Sin perfil');

        $this->persist($centre, $folder->getDocumentSection(), $folder, $profileA, $profileB, $uploader, $docA, $docB, $docNone);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request('GET', "/arbol-documental/carpetas/{$folderId}/descargar-zip");

        $files = $this->readZipResponse();
        ksort($files);
        self::assertSame([
            'Jefatura de estudios/Horario.txt' => 'v1',
            'Secretaría/Acta.txt'              => 'v1',
            'Sin perfil.txt'                   => 'v1',
        ], $files);
    }

    public function testDownloadZipIgnoresProfileGroupingWhenTheFolderIsNotGroupedByProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Secretaría');
        $uploader = $this->teacher('subidor');

        $doc = $this->documentWithFirstRevision($folder, $uploader, 'Acta');
        $doc->setUploadProfile($profile);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $uploader, $doc);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request('GET', "/arbol-documental/carpetas/{$folderId}/descargar-zip");

        self::assertSame(['Acta.txt' => 'v1'], $this->readZipResponse());
    }

    public function testDownloadZipReplacesDangerousCharactersInTheProfileSubdirectoryName(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $folder->setGroupByProfile(true);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Jefatura/Depto: "Mates"');
        $uploader = $this->teacher('subidor');

        $doc = $this->documentWithFirstRevision($folder, $uploader, 'Programación');
        $doc->setUploadProfile($profile);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $uploader, $doc);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request('GET', "/arbol-documental/carpetas/{$folderId}/descargar-zip");

        $names = array_keys($this->readZipResponse());
        self::assertCount(1, $names);
        self::assertSame(1, substr_count($names[0], '/'), 'only the subdirectory separator may remain');
        self::assertStringNotContainsString(':', $names[0]);
        self::assertStringNotContainsString('"', $names[0]);
        self::assertStringStartsWith('Jefatura_Depto_ _Mates_/', $names[0]);
    }

    public function testDownloadZipDeniedWhenFolderVisibilityRestrictsTheTeacher(): void
    {
        $centre            = $this->centre();
        $folder            = $this->folder($centre);
        $visibilityProfile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Visible');
        $folder->addVisibilityProfile($visibilityProfile);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithFirstRevision($folder, $uploader, 'Doc');
        $stranger = $this->teacher('ajeno');
        $this->persist($centre, $folder->getDocumentSection(), $folder, $visibilityProfile, $uploader, $stranger, $document);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($stranger, $centre);
        $this->client->request('GET', "/arbol-documental/carpetas/{$folderId}/descargar-zip");

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ── approve() / reject() ─────────────────────────────────────────────────

    public function testApproveActivatesThePendingRevision(): void
    {
        $centre           = $this->centre();
        $folder           = $this->folder($centre);
        $reviewerProfile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($reviewerProfile);
        $reviewer   = $this->teacher('revisor');
        $assignment = new SpecificProfileAssignment($reviewerProfile, null, $reviewer);
        $submitter  = $this->teacher('subidor');

        $document = new Document($folder, 'Doc');
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $submitter);
        $document->getRevisions()->add($revision);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $reviewerProfile, $reviewer, $assignment, $submitter, $document, $file, $revision);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();
        $revisionId = $revision->getId()->toRfc4122();

        $this->loginAs($reviewer, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones/{$revisionId}/aprobar",
            ['_token' => $this->csrfToken('folder_document_revision_review_' . $revisionId), 'reviewResult' => 'Correcto'],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getActiveRevision());
        self::assertFalse($reloaded->getActiveRevision()->isPendingReview());
    }

    public function testApproveDeniedWithoutReviewPermission(): void
    {
        $centre   = $this->centre();
        $folder   = $this->folder($centre);
        $stranger = $this->teacher('ajeno');
        $document = new Document($folder, 'Doc');
        $file     = new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
        $revision = new DocumentRevision($document, 1, $file, true, $stranger);
        $document->getRevisions()->add($revision);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $stranger, $document, $file, $revision);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();
        $revisionId = $revision->getId()->toRfc4122();

        $this->loginAs($stranger, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones/{$revisionId}/aprobar",
            ['_token' => $this->csrfToken('folder_document_revision_review_' . $revisionId)],
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testRejectClearsTheActiveRevisionWhenItWasTheRejectedOne(): void
    {
        $centre           = $this->centre();
        $folder           = $this->folder($centre);
        $reviewerProfile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($reviewerProfile);
        $reviewer   = $this->teacher('revisor');
        $assignment = new SpecificProfileAssignment($reviewerProfile, null, $reviewer);
        $submitter  = $this->teacher('subidor');
        $document   = $this->documentWithFirstRevision($folder, $submitter);
        $revision   = $document->getActiveRevision();
        self::assertNotNull($revision);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $reviewerProfile, $reviewer, $assignment, $submitter, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();
        $revisionId = $revision->getId()->toRfc4122();

        $this->loginAs($reviewer, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones/{$revisionId}/rechazar",
            ['_token' => $this->csrfToken('folder_document_revision_review_' . $revisionId), 'reviewResult' => 'No vale'],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($documentId);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getActiveRevision());
    }

    public function testRejectRejectedWithInvalidCsrfToken(): void
    {
        $centre           = $this->centre();
        $folder           = $this->folder($centre);
        $reviewerProfile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($reviewerProfile);
        $reviewer   = $this->teacher('revisor');
        $assignment = new SpecificProfileAssignment($reviewerProfile, null, $reviewer);
        $submitter  = $this->teacher('subidor');
        $document   = $this->documentWithFirstRevision($folder, $submitter);
        $revision   = $document->getActiveRevision();
        self::assertNotNull($revision);

        $this->persist($centre, $folder->getDocumentSection(), $folder, $reviewerProfile, $reviewer, $assignment, $submitter, $document);
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();
        $revisionId = $revision->getId()->toRfc4122();

        $this->loginAs($reviewer, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones/{$revisionId}/rechazar",
            ['_token' => 'invalido'],
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    // ── redirectToFolder() referer awareness ─────────────────────────────────

    public function testRedirectFollowsASameOriginReferer(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $admin  = $this->admin();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $admin);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/subir",
            ['_token' => $this->csrfToken('folder_upload_' . $folderId)],
            [],
            ['HTTP_REFERER' => 'http://localhost/actividades?category=x'],
        );

        self::assertSame('http://localhost/actividades?category=x', $this->client->getResponse()->headers->get('Location'));
    }

    public function testRedirectFallsBackToTheDocumentTreeWithoutAUsableReferer(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $admin  = $this->admin();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $admin);
        $folderId  = $folder->getId()->toRfc4122();
        $sectionId = $folder->getDocumentSection()->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        // The test client otherwise auto-fills Referer from browsing history (loginAs()'s own GET
        // '/'); override it explicitly to reproduce a request with no usable referer at all.
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/subir",
            ['_token' => $this->csrfToken('folder_upload_' . $folderId)],
            [],
            ['HTTP_REFERER' => ''],
        );

        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringContainsString('/arbol-documental', $location);
        self::assertStringContainsString($sectionId, $location);
        self::assertStringContainsString($folderId, $location);
    }

    public function testRedirectIgnoresACrossOriginReferer(): void
    {
        $centre = $this->centre();
        $folder = $this->folder($centre);
        $admin  = $this->admin();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $admin);
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/subir",
            ['_token' => $this->csrfToken('folder_upload_' . $folderId)],
            [],
            ['HTTP_REFERER' => 'http://evil.example.com/phishing'],
        );

        $location = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($location);
        self::assertStringNotContainsString('evil.example.com', $location);
    }

    // ── Review notifications ─────────────────────────────────────────────────

    /** @return list<SettingDefinition> */
    private function reviewNotificationSettingsEnabled(): array
    {
        return [
            (new SettingDefinition())->setKey('notifications.pending_review_notification_mode')->setType(SettingType::Choice)->setDefaultValue('individual')->setChoices('disabled,individual,daily_digest')->setTeacherScope(true),
            (new SettingDefinition())->setKey('notifications.email_notifications_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setTeacherScope(true),
            (new SettingDefinition())->setKey('notifications.email_log_enabled')->setType(SettingType::Boolean)->setDefaultValue('true')->setCentreScope(true),
        ];
    }

    public function testUploadNotifiesTheFoldersReviewersWhenTheFolderRequiresReview(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile);
        $reviewer = $this->teacher('revisor')->setEmail('revisor@example.com');
        $assign   = new SpecificProfileAssignment($profile, null, $reviewer);
        $admin    = $this->admin();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $reviewer, $assign, $admin, ...$this->reviewNotificationSettingsEnabled());
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/arbol-documental/carpetas/{$folderId}/subir", [
            '_token' => $this->csrfToken('folder_upload_' . $folderId),
            'items'  => [0 => ['version' => '1']],
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);
        $logEntries = $logs->findAll();
        self::assertCount(1, $logEntries);
        self::assertSame('pending_review_reminder', $logEntries[0]->getEventKey());
    }

    public function testUploadRevisionNotifiesTheFoldersReviewers(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile);
        $reviewer = $this->teacher('revisor')->setEmail('revisor@example.com');
        $assign   = new SpecificProfileAssignment($profile, null, $reviewer);
        $uploader = $this->teacher('subidor');
        $document = $this->documentWithFirstRevision($folder, $uploader);
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $reviewer, $assign, $uploader, $document, ...$this->reviewNotificationSettingsEnabled());
        $folderId   = $folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();

        $this->loginAs($uploader, $centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones",
            ['_token' => $this->csrfToken('folder_document_revision_' . $documentId)],
            ['file' => $this->uploadedFile('v2')],
        );

        self::assertTrue($this->client->getResponse()->isRedirect());

        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);
        $logEntries = $logs->findAll();
        self::assertCount(1, $logEntries);
        self::assertSame('pending_review_reminder', $logEntries[0]->getEventKey());
    }

    public function testUploadDoesNotNotifyAnAdminWithoutTheReviewProfile(): void
    {
        $centre  = $this->centre();
        $folder  = $this->folder($centre);
        $profile = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor');
        $folder->addReviewProfile($profile);
        $admin = $this->admin();
        $this->persist($centre, $folder->getDocumentSection(), $folder, $profile, $admin, ...$this->reviewNotificationSettingsEnabled());
        $folderId = $folder->getId()->toRfc4122();

        $this->loginAs($admin, $centre);
        $this->client->request('POST', "/arbol-documental/carpetas/{$folderId}/subir", [
            '_token' => $this->csrfToken('folder_upload_' . $folderId),
            'items'  => [0 => ['version' => '1']],
        ], [
            'files' => [0 => $this->uploadedFile('contenido')],
        ]);

        self::assertTrue($this->client->getResponse()->isRedirect());

        /** @var EmailNotificationLogRepository $logs */
        $logs = self::getContainer()->get(EmailNotificationLogRepository::class);
        self::assertSame([], $logs->findAll());
    }
}
