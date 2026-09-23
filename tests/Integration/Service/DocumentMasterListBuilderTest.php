<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

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
use App\Entity\Teacher;
use App\Model\DocumentMasterListRow;
use App\Service\DocumentMasterListBuilder;
use App\Service\DocumentReviewSchedule;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DocumentMasterListBuilderTest extends RepositoryTestCase
{
    use ClockSensitiveTrait;

    private DocumentMasterListBuilder $builder;
    private EducationalCentre $centre;
    private Teacher $uploader;

    protected function setUp(): void
    {
        parent::setUp();
        self::mockTime('2025-10-10 10:00:00');

        /** @var DocumentMasterListBuilder $builder */
        $builder       = self::getContainer()->get(DocumentMasterListBuilder::class);
        $this->builder = $builder;

        $this->centre   = (new EducationalCentre())->setCode('12345678')->setName('Centro')->setCity('Ciudad');
        $this->uploader = (new Teacher(new PersonName('Ana', 'García')))->setUsername('agarcia');
        $this->persist($this->centre, $this->uploader);
    }

    private function section(string $name, int $position, ?DocumentSection $parent = null): DocumentSection
    {
        $section = (new DocumentSection())->setEducationalCentre($this->centre)->setName($name)->setPosition($position);
        if ($parent !== null) {
            $section->setParent($parent);
        }
        $this->persist($section);

        return $section;
    }

    private function folder(DocumentSection $section, string $name, int $position = 0): Folder
    {
        $folder = (new Folder())->setDocumentSection($section)->setName($name)->setPosition($position);
        $this->persist($folder);

        return $folder;
    }

    /** $state: "approved", "pending", "approved+pending" or "rejected" */
    private function document(Folder $folder, string $name, int $position, string $state = 'approved', ?string $nextReview = null): Document
    {
        $document = (new Document($folder, $name))->setPosition($position);
        if ($nextReview !== null) {
            $document->setNextReviewAt(new \DateTimeImmutable($nextReview));
        }
        $entities = [$document];
        $revisions = match ($state) {
            'approved'         => [[1, false]],
            'pending'          => [[1, true]],
            'approved+pending' => [[1, false], [2, true]],
            default            => [[1, true]],
        };
        foreach ($revisions as [$version, $pending]) {
            $file     = new DocumentFile(hash('sha256', $name . $version), 'x', 'application/pdf', 'x.pdf', 1);
            $revision = new DocumentRevision($document, $version, $file, $pending, $this->uploader);
            $document->getRevisions()->add($revision);
            if (!$pending) {
                $document->setActiveRevision($revision);
            }
            if ($state === 'rejected') {
                $revision->reject($this->uploader, null);
            }
            array_push($entities, $file, $revision);
        }
        $this->persist(...$entities);

        return $document;
    }

    public function testListsDocumentsInTreeOrderWithTheirStatusAndResponsibles(): void
    {
        $procedimientos = $this->section('Procedimientos', 0);
        $registros      = $this->section('Registros', 1);
        $sub            = $this->section('Evaluación', 0, $procedimientos);
        $a              = $this->folder($procedimientos, 'Gestión', 0);
        $profile        = (new SpecificProfile())->setEducationalCentre($this->centre)->setName('Coordinación');
        $a->addResponsibleProfile($profile);
        $this->persist($profile);
        $this->document($a, 'PR-02 Compras', 1, 'approved+pending');
        $this->document($a, 'PR-01 Control documental', 0);
        $this->document($this->folder($sub, 'Criterios'), 'Criterios de evaluación', 0, 'pending');
        $this->document($this->folder($registros, 'Actas'), 'Acta rechazada', 0, 'rejected');

        $rows = $this->builder->build($this->centre);

        self::assertSame(
            ['PR-01 Control documental', 'PR-02 Compras', 'Criterios de evaluación', 'Acta rechazada'],
            array_map(static fn (DocumentMasterListRow $r): string => $r->documentName, $rows),
            'sections depth-first, then folders and documents by position',
        );
        self::assertSame('Procedimientos › Evaluación', $rows[2]->sectionPath);
        self::assertSame(
            [DocumentMasterListRow::STATUS_ACTIVE, DocumentMasterListRow::STATUS_UPDATING, DocumentMasterListRow::STATUS_PENDING, DocumentMasterListRow::STATUS_NONE],
            array_map(static fn (DocumentMasterListRow $r): string => $r->status, $rows),
        );
        self::assertSame(1, $rows[0]->version);
        self::assertSame('García, Ana', $rows[0]->uploadedBy);
        self::assertSame('Coordinación', $rows[0]->responsibles);
        self::assertNull($rows[2]->version);
    }

    public function testLeavesOutObsoleteFoldersAndActivitySubmissions(): void
    {
        $section  = $this->section('Sección', 0);
        $obsolete = $this->folder($section, 'Antigua')->setObsolete(true);
        $entregas = $this->folder($section, 'Entregas');
        $category = (new ActivityCategory())->setEducationalCentre($this->centre)->setName('Categoría');
        $activity = (new Activity())->setCategory($category)->setTitle('Memoria')->setStart(1, 10)->setEnd(31, 10)->setFolder($entregas);
        $this->persist($obsolete, $category, $activity);
        $this->document($obsolete, 'Procedimiento derogado', 0);
        $this->document($entregas, 'Tutor/a', 0);
        $this->document($this->folder($section, 'Vigente'), 'Manual', 0);

        $names = array_map(static fn (DocumentMasterListRow $r): string => $r->documentName, $this->builder->build($this->centre));

        self::assertSame(['Manual'], $names);
    }

    public function testReviewsDueListsOverdueAndUpcomingOnesSoonestFirst(): void
    {
        $folder = $this->folder($this->section('Sección', 0), 'Carpeta');
        $this->document($folder, 'Lejana', 0, nextReview: '2026-06-01');
        $this->document($folder, 'En tres semanas', 1, nextReview: '2025-10-31');
        $this->document($folder, 'Vencida', 2, nextReview: '2025-09-01');
        $this->document($folder, 'En siete semanas', 3, nextReview: '2025-11-28');
        $this->document($folder, 'Sin fecha', 4);

        $due = $this->builder->reviewsDue($this->centre);

        self::assertSame(['Vencida', 'En tres semanas', 'En siete semanas'], array_map(static fn (DocumentMasterListRow $r): string => $r->documentName, $due));
        self::assertSame(
            [DocumentReviewSchedule::OVERDUE, DocumentReviewSchedule::SOON, DocumentReviewSchedule::OK],
            array_map(static fn (DocumentMasterListRow $r): ?string => $r->reviewState, $due),
        );
    }
}
