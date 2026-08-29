<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\Teacher;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    private function folder(): Folder
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');

        return (new Folder())->setDocumentSection($section)->setName('Carpeta');
    }

    private function teacher(string $username = 'docente'): Teacher
    {
        return (new Teacher(new PersonName('Nombre', 'Apellido')))->setUsername($username);
    }

    private function file(string $content = 'contenido'): DocumentFile
    {
        return new DocumentFile(hash('sha256', $content), $content, 'text/plain', 'f.txt', strlen($content));
    }

    private function revision(Document $document, int $version, bool $pendingReview, ?Teacher $uploader = null): DocumentRevision
    {
        return new DocumentRevision($document, $version, $this->file((string) $version), $pendingReview, $uploader ?? $this->teacher());
    }

    public function testSetActiveRevisionRejectsRevisionFromAnotherDocument(): void
    {
        $document      = new Document($this->folder(), 'Doc');
        $otherDocument = new Document($this->folder(), 'Otro');
        $revision      = $this->revision($otherDocument, 1, false);

        $this->expectException(\LogicException::class);
        $document->setActiveRevision($revision);
    }

    public function testSetActiveRevisionRejectsPendingRevision(): void
    {
        $document = new Document($this->folder(), 'Doc');
        $revision = $this->revision($document, 1, true);

        $this->expectException(\LogicException::class);
        $document->setActiveRevision($revision);
    }

    public function testSetActiveRevisionRejectsRejectedRevision(): void
    {
        $document = new Document($this->folder(), 'Doc');
        $revision = $this->revision($document, 1, false);
        $revision->reject($this->teacher('revisor'), 'No vale');

        $this->expectException(\LogicException::class);
        $document->setActiveRevision($revision);
    }

    public function testSetActiveRevisionAcceptsApprovedRevisionOfSameDocument(): void
    {
        $document = new Document($this->folder(), 'Doc');
        $revision = $this->revision($document, 1, false);

        $document->setActiveRevision($revision);

        self::assertSame($revision, $document->getActiveRevision());
    }

    public function testSetActiveRevisionAcceptsNullToClear(): void
    {
        $document = new Document($this->folder(), 'Doc');
        $revision = $this->revision($document, 1, false);
        $document->setActiveRevision($revision);

        $document->setActiveRevision(null);

        self::assertNull($document->getActiveRevision());
    }

    public function testGetPendingRevisionFindsThePendingOne(): void
    {
        $document = new Document($this->folder(), 'Doc');
        $approved = $this->revision($document, 1, false);
        $pending  = $this->revision($document, 2, true);
        $document->getRevisions()->add($approved);
        $document->getRevisions()->add($pending);

        self::assertTrue($document->isPendingApproval());
        self::assertSame($pending, $document->getPendingRevision());
    }

    public function testGetPendingRevisionReturnsNullWhenNoneArePending(): void
    {
        $document = new Document($this->folder(), 'Doc');
        $document->getRevisions()->add($this->revision($document, 1, false));

        self::assertFalse($document->isPendingApproval());
        self::assertNull($document->getPendingRevision());
    }

    public function testGetNextVersionIsOneForFreshDocument(): void
    {
        $document = new Document($this->folder(), 'Doc');

        self::assertSame(1, $document->getNextVersion());
    }

    public function testGetNextVersionIsMaxPlusOne(): void
    {
        $document = new Document($this->folder(), 'Doc');
        $document->getRevisions()->add($this->revision($document, 1, false));
        $document->getRevisions()->add($this->revision($document, 3, false));

        self::assertSame(4, $document->getNextVersion());
    }

    public function testHasVersion(): void
    {
        $document = new Document($this->folder(), 'Doc');
        $document->getRevisions()->add($this->revision($document, 2, false));

        self::assertTrue($document->hasVersion(2));
        self::assertFalse($document->hasVersion(1));
    }

    public function testSetUploadProfileClearsListItemWhenProfileIsNull(): void
    {
        $document = new Document($this->folder(), 'Doc');
        $centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $profile  = (new \App\Entity\SpecificProfile())->setEducationalCentre($centre)->setName('Perfil');
        $listItem = (new \App\Entity\ListItem())->setEducationalCentre($centre)->setName('Item');

        $document->setUploadProfile($profile, $listItem);
        self::assertSame($profile, $document->getUploadProfile());
        self::assertSame($listItem, $document->getUploadListItem());

        $document->setUploadProfile(null, $listItem);

        self::assertNull($document->getUploadProfile());
        self::assertNull($document->getUploadListItem());
    }
}
