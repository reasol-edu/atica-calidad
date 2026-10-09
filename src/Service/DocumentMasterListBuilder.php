<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FolderResponsibleProfile;
use App\Model\DocumentMasterListRow;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\FolderRepository;

/**
 * The centre's document master list — every controlled document of the quality system with the
 * version in force, as the first thing an audit asks for — in the same order as the document tree
 * (sections depth-first, then folders, then documents, each by position). Left out: obsolete
 * folders, and the folders backing an activity, whose documents are submissions (records, one per
 * teacher and year) rather than controlled documents.
 */
final class DocumentMasterListBuilder
{
    /** Horizon of the "document reviews" report: everything overdue, plus what's due within this many days. */
    public const int REVIEW_HORIZON_DAYS = 60;

    public function __construct(
        private readonly DocumentSectionRepository $sections,
        private readonly FolderRepository $folders,
        private readonly DocumentRepository $documents,
        private readonly DocumentReviewSchedule $schedule,
    ) {}

    /** @return list<DocumentMasterListRow> */
    public function build(EducationalCentre $centre): array
    {
        // Whole tree in three queries (sections, folders, documents), stitched together in memory.
        $sectionsByParent = [];
        foreach ($this->sections->findAllByCentre($centre) as $section) {
            $sectionsByParent[$section->getParent()?->getId()->toRfc4122() ?? ''][] = $section;
        }
        $foldersBySection = [];
        foreach ($this->folders->findAllByCentreWithResponsibles($centre) as $folder) {
            $foldersBySection[$folder->getDocumentSection()->getId()->toRfc4122()][] = $folder;
        }
        $documentsByFolder = [];
        foreach ($this->documents->findAllByCentreForMasterList($centre) as $document) {
            $documentsByFolder[$document->getFolder()->getId()->toRfc4122()][] = $document;
        }

        $rows = [];
        foreach ($sectionsByParent[''] ?? [] as $root) {
            $this->addSection($root, [], $sectionsByParent, $foldersBySection, $documentsByFolder, $rows);
        }

        return $rows;
    }

    /**
     * Documents whose review is overdue or due within REVIEW_HORIZON_DAYS, soonest first.
     *
     * @param list<DocumentMasterListRow>|null $rows the centre's already built master list, to skip building it again
     *
     * @return list<DocumentMasterListRow>
     */
    public function reviewsDue(EducationalCentre $centre, ?array $rows = null): array
    {
        $limit = $this->schedule->today()->modify('+' . self::REVIEW_HORIZON_DAYS . ' days');
        $due   = array_values(array_filter(
            $rows ?? $this->build($centre),
            static fn (DocumentMasterListRow $row): bool => $row->nextReviewAt !== null && $row->nextReviewAt <= $limit,
        ));
        usort($due, static fn (DocumentMasterListRow $a, DocumentMasterListRow $b): int => $a->nextReviewAt <=> $b->nextReviewAt);

        return $due;
    }

    /**
     * @param list<string>                      $trail             names of the ancestor sections
     * @param array<string, list<DocumentSection>> $sectionsByParent
     * @param array<string, list<Folder>>          $foldersBySection
     * @param array<string, list<Document>>        $documentsByFolder
     * @param list<DocumentMasterListRow>          $rows
     */
    private function addSection(DocumentSection $section, array $trail, array $sectionsByParent, array $foldersBySection, array $documentsByFolder, array &$rows): void
    {
        $trail[] = $section->getName();
        $path    = implode(' › ', $trail);

        foreach ($foldersBySection[$section->getId()->toRfc4122()] ?? [] as $folder) {
            if ($folder->isObsolete() || $folder->getActivity() !== null) {
                continue;
            }
            $responsibles = $this->responsibles($folder);
            foreach ($documentsByFolder[$folder->getId()->toRfc4122()] ?? [] as $document) {
                $rows[] = $this->row($document, $path, $folder, $responsibles);
            }
        }

        foreach ($sectionsByParent[$section->getId()->toRfc4122()] ?? [] as $child) {
            $this->addSection($child, $trail, $sectionsByParent, $foldersBySection, $documentsByFolder, $rows);
        }
    }

    private function row(Document $document, string $sectionPath, Folder $folder, string $responsibles): DocumentMasterListRow
    {
        $active  = $document->getActiveRevision();
        $pending = $document->isPendingApproval();

        return new DocumentMasterListRow(
            $sectionPath,
            $folder->getName(),
            $document->getName(),
            match (true) {
                $active !== null && $pending => DocumentMasterListRow::STATUS_UPDATING,
                $active !== null             => DocumentMasterListRow::STATUS_ACTIVE,
                $pending                     => DocumentMasterListRow::STATUS_PENDING,
                default                      => DocumentMasterListRow::STATUS_NONE,
            },
            $active?->getVersion(),
            $active?->getRevisedAt(),
            $active === null ? null : $active->getUploadedBy()->getName()->getLastName() . ', ' . $active->getUploadedBy()->getName()->getFirstName(),
            $responsibles,
            $document->getNextReviewAt(),
            $this->schedule->stateOf($document->getNextReviewAt()),
        );
    }

    private function responsibles(Folder $folder): string
    {
        return implode(', ', array_map(
            static fn (FolderResponsibleProfile $r): string => $r->getSpecificProfile()->getName() . ($r->getListItem() !== null ? ' ' . $r->getListItem()->getName() : ''),
            $folder->getResponsibleProfiles()->toArray(),
        ));
    }
}
