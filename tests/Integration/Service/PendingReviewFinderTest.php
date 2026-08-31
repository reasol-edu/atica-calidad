<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Document;
use App\Entity\DocumentFile;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\PendingReviewFinder;
use App\Tests\Integration\RepositoryTestCase;

final class PendingReviewFinderTest extends RepositoryTestCase
{
    private function centre(): EducationalCentre
    {
        return (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
    }

    private function teacher(string $username): Teacher
    {
        return (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
    }

    private function file(): DocumentFile
    {
        return new DocumentFile(hash('sha256', uniqid('', true)), 'x', 'text/plain', 'f.txt', 1);
    }

    private function finder(): PendingReviewFinder
    {
        /** @var PendingReviewFinder $finder */
        $finder = self::getContainer()->get(PendingReviewFinder::class);

        return $finder;
    }

    public function testAQualityManagerSeesAPendingRevisionInAnyFolder(): void
    {
        $centre    = $this->centre();
        $manager   = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $document = new Document($folder, 'Acta');
        $file     = $this->file();
        $revision = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $manager, $uploader, $section, $folder, $document, $file, $revision);

        $pending = $this->finder()->forTeacher($manager, $centre);

        self::assertCount(1, $pending);
        self::assertSame($revision->getId()->toRfc4122(), $pending[0]->getId()->toRfc4122());
    }

    public function testATeacherWithNoReviewAccessSeesNothing(): void
    {
        $centre   = $this->centre();
        $outsider = $this->teacher('sin-acceso');
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $document = new Document($folder, 'Acta');
        $file     = $this->file();
        $revision = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $outsider, $uploader, $section, $folder, $document, $file, $revision);

        self::assertSame([], $this->finder()->forTeacher($outsider, $centre));
    }

    public function testAnAlreadyReviewedRevisionIsNotPending(): void
    {
        $centre   = $this->centre();
        $manager  = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $document = new Document($folder, 'Acta');
        $file     = $this->file();
        $revision = new DocumentRevision($document, 1, $file, false, $uploader);
        $this->persist($centre, $manager, $uploader, $section, $folder, $document, $file, $revision);

        self::assertSame([], $this->finder()->forTeacher($manager, $centre));
    }
}
