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
use App\Entity\SpecificProfile;
use App\Entity\SpecificProfileAssignment;
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

    /**
     * forTeacher() is deliberately narrower than canReviewFolder(): a quality manager is entitled
     * to review anything, but that broader right isn't what "personally pending on me" means — see
     * this class's docblock. Without a review-profile assignment of their own, a manager sees
     * nothing here (only allPendingForCentre() surfaces everything, for the admin-only dashboard
     * section).
     */
    public function testAQualityManagerWithNoReviewProfileOfTheirOwnSeesNothingPersonally(): void
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

        self::assertSame([], $this->finder()->forTeacher($manager, $centre));
        self::assertFalse($this->finder()->hasReviewAccess($manager, $centre));
    }

    public function testAQualityManagerWhoAlsoHoldsAReviewProfileSeesItPersonally(): void
    {
        $centre   = $this->centre();
        $manager  = $this->teacher('gestor');
        $centre->addQualityManager($manager);
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor')->setPosition(0);
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $folder->addReviewProfile($profile, null);
        $assignment = new SpecificProfileAssignment($profile, null, $manager);
        $document   = new Document($folder, 'Acta');
        $file       = $this->file();
        $revision   = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $manager, $profile, $assignment, $uploader, $section, $folder, $document, $file, $revision);

        $pending = $this->finder()->forTeacher($manager, $centre);

        self::assertCount(1, $pending);
        self::assertSame($revision->getId()->toRfc4122(), $pending[0]->getId()->toRfc4122());
    }

    /** allPendingForCentre() is the deliberate "everything, regardless of who's personally on the hook" escape hatch for the admin-only dashboard section — never used by the notification bell. */
    public function testAllPendingForCentreIncludesARevisionNoOnePersonallyHolds(): void
    {
        $centre   = $this->centre();
        $uploader = $this->teacher('subidor');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $document = new Document($folder, 'Acta');
        $file     = $this->file();
        $revision = new DocumentRevision($document, 1, $file, true, $uploader);
        $this->persist($centre, $uploader, $section, $folder, $document, $file, $revision);

        $pending = $this->finder()->allPendingForCentre($centre);

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

    public function testHasReviewAccessIsTrueForAFolderReviewerEvenWithNothingPending(): void
    {
        $centre   = $this->centre();
        $reviewer = $this->teacher('revisor');
        $profile  = (new SpecificProfile())->setEducationalCentre($centre)->setName('Revisor')->setPosition(0);
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $folder->addReviewProfile($profile, null);
        $assignment = new SpecificProfileAssignment($profile, null, $reviewer);
        $this->persist($centre, $reviewer, $profile, $section, $folder, $assignment);

        self::assertTrue($this->finder()->hasReviewAccess($reviewer, $centre));
        self::assertSame([], $this->finder()->forTeacher($reviewer, $centre));
    }

    public function testHasReviewAccessIsFalseForATeacherWithNoReviewProfileAnywhere(): void
    {
        $centre   = $this->centre();
        $outsider = $this->teacher('sin-acceso');
        $section  = (new DocumentSection())->setEducationalCentre($centre)->setName('Sección')->setPosition(0);
        $folder   = (new Folder())->setDocumentSection($section)->setName('Carpeta')->setPosition(0);
        $this->persist($centre, $outsider, $section, $folder);

        self::assertFalse($this->finder()->hasReviewAccess($outsider, $centre));
    }
}
