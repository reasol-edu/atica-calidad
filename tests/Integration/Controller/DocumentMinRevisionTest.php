<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\CentreSettingValue;
use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\GlobalSettingValue;
use App\Entity\PersonName;
use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Repository\FolderRepository;
use App\Tests\Integration\ControllerTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The setting `documents.min_revision` (0 or 1, global and per centre): the lowest revision number a document may have. It sets the
 * version proposed on an upload and the floor of every number typed, and never touches the revisions that already exist.
 */
final class DocumentMinRevisionTest extends ControllerTestCase
{
    private EducationalCentre $centre;
    private Folder $folder;
    private Teacher $admin;
    private SettingDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centre     = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section          = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $this->folder     = (new Folder())->setDocumentSection($section)->setName('Carpeta');
        $this->admin      = (new Teacher(new PersonName('Nombre', 'admin')))->setUsername('admin')->setAdmin(true);
        $this->definition = (new SettingDefinition())->setKey('documents.min_revision')->setType(SettingType::Choice)->setChoices('0,1')
            ->setDefaultValue('1')->setGlobalScope(true)->setCentreScope(true);
        $this->persist($this->centre, $section, $this->folder, $this->admin, $this->definition);
    }

    private function setGlobal(string $value, bool $locked = false): void
    {
        $this->persist((new GlobalSettingValue())->setDefinition($this->definition)->setValue($value)->setLocked($locked));
    }

    private function setForCentre(string $value): void
    {
        $this->persist((new CentreSettingValue())->setDefinition($this->definition)->setCentre($this->centre)->setValue($value));
    }

    private function csrfToken(string $id): string
    {
        /** @var \Symfony\Component\HttpFoundation\RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $request      = $this->client->getRequest();
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
            $request->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }

    private function uploadedFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'min_revision_test_');
        self::assertNotFalse($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, 'archivo.pdf', 'application/pdf', null, true);
    }

    /** @return list<int> the versions of the folder's documents, one per document (its active revision) */
    private function versionsInFolder(): array
    {
        $this->em->clear();
        /** @var FolderRepository $folders */
        $folders = self::getContainer()->get(FolderRepository::class);
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $folder    = $folders->findById($this->folder->getId()->toRfc4122());
        self::assertNotNull($folder);

        return array_values(array_map(static fn (Document $d): int => $d->getActiveRevision()?->getVersion() ?? -1, $documents->findByFolder($folder)));
    }

    /** Posts a single file with the given text in the version field of the upload form. */
    private function upload(?string $version): void
    {
        $folderId = $this->folder->getId()->toRfc4122();
        $this->loginAs($this->admin, $this->centre);
        $this->client->request('POST', "/arbol-documental/carpetas/{$folderId}/subir", [
            '_token' => $this->csrfToken('folder_upload_' . $folderId),
            'items'  => [0 => $version === null ? [] : ['version' => $version]],
        ], ['files' => [0 => $this->uploadedFile('contenido')]]);
        self::assertTrue($this->client->getResponse()->isRedirect());
    }

    public function testWithNothingConfiguredTheFirstRevisionIsV1AndAnUploadCannotGoBelowIt(): void
    {
        $this->upload('0');

        self::assertSame([1], $this->versionsInFolder());
    }

    public function testWithAMinimumOfZeroAnUploadMayStartAtV0(): void
    {
        $this->setGlobal('0');

        $this->upload('0');
        self::assertSame([0], $this->versionsInFolder());
    }

    public function testAnUploadWithNoVersionAtAllStartsAtTheCentresMinimum(): void
    {
        $this->setGlobal('0');

        $this->upload(null);

        self::assertSame([0], $this->versionsInFolder());
    }

    public function testTheCentresOwnValueOverridesTheGlobalOne(): void
    {
        $this->setGlobal('1');
        $this->setForCentre('0');

        $this->upload('');

        self::assertSame([0], $this->versionsInFolder());
    }

    public function testALockedGlobalValueWinsOverTheCentres(): void
    {
        $this->setGlobal('1', locked: true);
        $this->setForCentre('0');

        $this->upload('0');

        self::assertSame([1], $this->versionsInFolder());
    }

    public function testATypedStartingVersionAboveTheMinimumIsKept(): void
    {
        $this->setGlobal('0');

        $this->upload('3');

        self::assertSame([3], $this->versionsInFolder());
    }

    /** A document with a single approved revision numbered $version. */
    private function documentAt(int $version): Document
    {
        $document = new Document($this->folder, 'Doc');
        $file     = new DocumentFile(hash('sha256', 'v' . $version), 'contenido', 'text/plain', 'v.txt', 9);
        $revision = new DocumentRevision($document, $version, $file, false, $this->admin);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->persist($file, $document, $revision);

        return $document;
    }

    /** @return list<int> every revision number of the document, ascending */
    private function revisionNumbers(Document $document): array
    {
        $this->em->clear();
        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($document->getId()->toRfc4122());
        self::assertNotNull($reloaded);
        $numbers = array_map(static fn (DocumentRevision $r): int => $r->getVersion(), $reloaded->getRevisions()->toArray());
        sort($numbers);

        return $numbers;
    }

    private function postRevision(Document $document, string $version): void
    {
        $folderId   = $this->folder->getId()->toRfc4122();
        $documentId = $document->getId()->toRfc4122();
        $this->loginAs($this->admin, $this->centre);
        $this->client->request(
            'POST',
            "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones",
            ['_token' => $this->csrfToken('folder_document_revision_' . $documentId), 'version' => $version],
            ['file' => $this->uploadedFile('nueva')],
        );
    }

    public function testAManagerMayChooseV0ForANewRevisionWhenTheMinimumIsZero(): void
    {
        $this->setGlobal('0');
        $document = $this->documentAt(1);

        $this->postRevision($document, '0');

        self::assertSame([0, 1], $this->revisionNumbers($document));
    }

    public function testAManagerCannotChooseV0ForANewRevisionWhenTheMinimumIsOne(): void
    {
        $document = $this->documentAt(1);

        $this->postRevision($document, '0');

        self::assertSame([1], $this->revisionNumbers($document));
    }

    public function testABlankVersionIsNotMistakenForZero(): void
    {
        $this->setGlobal('0');
        $document = $this->documentAt(1);

        $this->postRevision($document, '');

        self::assertSame([1], $this->revisionNumbers($document));
    }

    public function testWithoutTypingANumberTheNextOneIsStillTheHighestPlusOne(): void
    {
        $this->setGlobal('0');
        $document = $this->documentAt(0);
        $folderId = $this->folder->getId()->toRfc4122();

        $this->loginAs($this->admin, $this->centre);
        $documentId = $document->getId()->toRfc4122();
        $this->client->request('POST', "/arbol-documental/carpetas/{$folderId}/documentos/{$documentId}/revisiones", [
            '_token' => $this->csrfToken('folder_document_revision_' . $documentId),
        ], ['file' => $this->uploadedFile('v1')]);

        self::assertSame([0, 1], $this->revisionNumbers($document));
    }
}
