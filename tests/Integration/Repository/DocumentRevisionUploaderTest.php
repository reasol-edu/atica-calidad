<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Repository\DocumentRepository;
use App\Tests\Integration\RepositoryTestCase;

/** Covers DocumentRevision::$uploadedBy, added so each revision (not just the document) records who uploaded it. */
final class DocumentRevisionUploaderTest extends RepositoryTestCase
{
    public function testActiveRevisionExposesItsOwnUploader(): void
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);

        $firstUploader  = (new Teacher(new PersonName('Primero', 'Docente')))->setUsername('primero');
        $secondUploader = (new Teacher(new PersonName('Segundo', 'Docente')))->setUsername('segundo');

        $document = new Document($folder, 'Doc');
        $content  = "v1\n";
        $file1    = new DocumentFile(hash('sha256', $content), $content, 'text/plain', 'v1.txt', strlen($content));
        $rev1     = new DocumentRevision($document, 1, $file1, false, $firstUploader);
        $document->setActiveRevision($rev1);

        $this->persist($centre, $section, $folder, $firstUploader, $secondUploader, $document, $file1, $rev1);

        $content2 = "v2\n";
        $file2    = new DocumentFile(hash('sha256', $content2), $content2, 'text/plain', 'v2.txt', strlen($content2));
        $rev2     = new DocumentRevision($document, 2, $file2, false, $secondUploader);
        $document->setActiveRevision($rev2);
        $this->persist($file2, $rev2);

        $this->em->clear();

        /** @var DocumentRepository $documents */
        $documents = self::getContainer()->get(DocumentRepository::class);
        $reloaded  = $documents->findById($document->getId()->toRfc4122());
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getActiveRevision());
        self::assertSame('segundo', $reloaded->getActiveRevision()->getUploadedBy()->getUsername());
        self::assertSame(2, $reloaded->getActiveRevision()->getVersion());
    }
}
