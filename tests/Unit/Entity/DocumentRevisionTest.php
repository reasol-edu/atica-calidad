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

final class DocumentRevisionTest extends TestCase
{
    private function document(): Document
    {
        $centre  = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $section = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección');
        $folder  = (new Folder())->setDocumentSection($section)->setName('Carpeta');

        return new Document($folder, 'Doc');
    }

    private function teacher(string $username = 'docente'): Teacher
    {
        return (new Teacher(new PersonName('Nombre', 'Apellido')))->setUsername($username);
    }

    private function file(): DocumentFile
    {
        return new DocumentFile(hash('sha256', 'x'), 'x', 'text/plain', 'f.txt', 1);
    }

    public function testFreshPendingRevisionIsNotApproved(): void
    {
        $revision = new DocumentRevision($this->document(), 1, $this->file(), true, $this->teacher());

        self::assertTrue($revision->isPendingReview());
        self::assertFalse($revision->isApproved());
        self::assertFalse($revision->isRejected());
    }

    public function testFreshNonPendingRevisionIsApproved(): void
    {
        $revision = new DocumentRevision($this->document(), 1, $this->file(), false, $this->teacher());

        self::assertFalse($revision->isPendingReview());
        self::assertTrue($revision->isApproved());
        self::assertFalse($revision->isRejected());
    }

    public function testApproveClearsPendingAndRejectedAndRecordsReviewer(): void
    {
        $revision = new DocumentRevision($this->document(), 1, $this->file(), true, $this->teacher());
        $reviewer = $this->teacher('revisor');

        $revision->approve($reviewer, 'Todo correcto');

        self::assertFalse($revision->isPendingReview());
        self::assertFalse($revision->isRejected());
        self::assertTrue($revision->isApproved());
        self::assertSame($reviewer, $revision->getReviewedBy());
        self::assertSame('Todo correcto', $revision->getReviewResult());
    }

    public function testRejectClearsPendingAndMarksRejectedAndRecordsReviewer(): void
    {
        $revision = new DocumentRevision($this->document(), 1, $this->file(), true, $this->teacher());
        $reviewer = $this->teacher('revisor');

        $revision->reject($reviewer, 'Faltan datos');

        self::assertFalse($revision->isPendingReview());
        self::assertTrue($revision->isRejected());
        self::assertFalse($revision->isApproved());
        self::assertSame($reviewer, $revision->getReviewedBy());
        self::assertSame('Faltan datos', $revision->getReviewResult());
    }

    public function testRejectAfterApproveOverridesToRejected(): void
    {
        $revision = new DocumentRevision($this->document(), 1, $this->file(), true, $this->teacher());
        $reviewer = $this->teacher('revisor');

        $revision->approve($reviewer, null);
        self::assertTrue($revision->isApproved());

        $revision->reject($reviewer, 'Cambio de opinión');

        self::assertTrue($revision->isRejected());
        self::assertFalse($revision->isApproved());
    }

    public function testApproveAfterRejectOverridesToApproved(): void
    {
        $revision = new DocumentRevision($this->document(), 1, $this->file(), true, $this->teacher());
        $reviewer = $this->teacher('revisor');

        $revision->reject($reviewer, 'No vale');
        self::assertTrue($revision->isRejected());

        $revision->approve($reviewer, 'Reconsiderado');

        self::assertTrue($revision->isApproved());
        self::assertFalse($revision->isRejected());
    }
}
