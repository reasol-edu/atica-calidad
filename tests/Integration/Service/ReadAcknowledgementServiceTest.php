<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\ActivityCategory;
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
use App\Service\ReadAcknowledgementService;
use App\Tests\Integration\RepositoryTestCase;

final class ReadAcknowledgementServiceTest extends RepositoryTestCase
{
    private EducationalCentre $centre;
    private AcademicYear $year;
    private Folder $folder;
    private Teacher $author;
    private Teacher $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centre = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->year   = (new AcademicYear())->setName('2026-2027')->setEducationalCentre($this->centre);
        $this->centre->setActiveAcademicYear($this->year);
        $section      = (new DocumentSection())->setEducationalCentre($this->centre)->setName('Sección');
        $this->folder = (new Folder())->setDocumentSection($section)->setName('Políticas')->setRequiresReadAcknowledgement(true);
        $this->author = $this->teacher('autora');
        $this->reader = $this->teacher('lector');
        $this->persist($this->centre, $this->year, $section, $this->folder, $this->author, $this->reader);
    }

    private function service(): ReadAcknowledgementService
    {
        // A fresh one each time: it remembers each folder's readers for the rest of the request.
        return new ReadAcknowledgementService(
            self::getContainer()->get(\App\Repository\DocumentRepository::class),
            self::getContainer()->get(\App\Repository\DocumentReadAcknowledgementRepository::class),
            self::getContainer()->get(\App\Repository\TeacherRepository::class),
            self::getContainer()->get(\App\Service\DocumentTreeAccessChecker::class),
            $this->em,
            self::getContainer()->get(\Symfony\Component\Clock\ClockInterface::class),
        );
    }

    private function teacher(string $username, bool $inYear = true): Teacher
    {
        $teacher = (new Teacher(new PersonName('Nombre', $username)))->setUsername($username);
        if ($inYear) {
            $teacher->addAcademicYear($this->year);
        }

        return $teacher;
    }

    private function document(Folder $folder, string $name = 'Política de calidad', int $version = 1): Document
    {
        $document = new Document($folder, $name);
        $this->persist($document);
        $this->newVersion($document, $version);

        return $document;
    }

    private function newVersion(Document $document, int $version): DocumentRevision
    {
        $file     = new DocumentFile(hash('sha256', $document->getName() . $version), 'x', 'text/plain', 'x.txt', 1);
        $revision = new DocumentRevision($document, $version, $file, false, $this->author);
        $document->getRevisions()->add($revision);
        $document->setActiveRevision($revision);
        $this->persist($file, $revision);

        return $revision;
    }

    public function testAReaderHasItPendingUntilTheyAcknowledgeIt(): void
    {
        $document = $this->document($this->folder);

        self::assertTrue($this->service()->mustRead($this->reader, $document));
        self::assertSame([$document], $this->service()->pendingFor($this->reader, $this->centre));

        $this->service()->acknowledge($this->reader, $document);

        self::assertSame([], $this->service()->pendingFor($this->reader, $this->centre));
        $status = $this->service()->statusOf([$document])[$document->getId()->toRfc4122()];
        self::assertSame(1, $status->readCount());
        self::assertSame(1, $status->total(), 'the author does not count');
        self::assertTrue($status->isComplete());
    }

    public function testAcknowledgingTwiceKeepsTheFirstOne(): void
    {
        $document = $this->document($this->folder);

        $first = $this->service()->acknowledge($this->reader, $document);
        self::assertSame($first, $this->service()->acknowledge($this->reader, $document));
    }

    public function testANewVersionInForceHasToBeReadAgain(): void
    {
        $document = $this->document($this->folder);
        $this->service()->acknowledge($this->reader, $document);

        $this->newVersion($document, 2);
        $this->em->flush();

        self::assertSame([$document], $this->service()->pendingFor($this->reader, $this->centre));
        self::assertNull($this->service()->acknowledgementOf($this->reader, $document));
    }

    public function testWhoUploadedTheVersionInForceDoesNotHaveToReadIt(): void
    {
        $document = $this->document($this->folder);

        self::assertFalse($this->service()->mustRead($this->author, $document));
        self::assertSame([], $this->service()->pendingFor($this->author, $this->centre));
    }

    public function testOnlyWhoCanSeeARestrictedFolderHasToReadIt(): void
    {
        $profile = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Jefatura');
        $this->folder->addVisibilityProfile($profile);
        $head = $this->teacher('jefa');
        $this->persist($profile, $head, new SpecificProfileAssignment($profile, null, $head));
        $document = $this->document($this->folder);

        self::assertTrue($this->service()->mustRead($head, $document));
        self::assertFalse($this->service()->mustRead($this->reader, $document));
        self::assertSame(['jefa'], array_map(
            static fn (Teacher $t): string => $t->getUsername(),
            $this->service()->statusOf([$document])[$document->getId()->toRfc4122()]->pending,
        ));
    }

    public function testOnlyTeachersOfTheActiveYear(): void
    {
        $former = $this->teacher('antigua', inYear: false);
        $this->persist($former);
        $document = $this->document($this->folder);

        self::assertFalse($this->service()->mustRead($former, $document));
    }

    public function testNothingToReadInAFolderThatDoesNotRequireIt(): void
    {
        $this->folder->setRequiresReadAcknowledgement(false);
        $document = $this->document($this->folder);

        self::assertFalse($this->service()->mustRead($this->reader, $document));
        self::assertSame([], $this->service()->statusOf([$document]));

        $this->expectException(\LogicException::class);
        $this->service()->acknowledge($this->reader, $document);
    }

    /** An activity's folder holds submissions, not documents to read — even with the flag left on. */
    public function testNeverInAnActivitysFolder(): void
    {
        $category = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 9)->setEnd(30, 6)->setFolder($this->folder);
        $this->persist($category, $activity);
        $document = $this->document($this->folder);

        self::assertFalse($this->service()->mustRead($this->reader, $document));
        self::assertSame([], $this->service()->pendingFor($this->reader, $this->centre));
    }
}
