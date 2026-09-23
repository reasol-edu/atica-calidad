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
        $rows = [];
        foreach ($this->sections->findRootsByCentre($centre) as $root) {
            $this->addSection($root, [], $rows);
        }

        return $rows;
    }

    /**
     * Documents whose review is overdue or due within REVIEW_HORIZON_DAYS, soonest first.
     *
     * @return list<DocumentMasterListRow>
     */
    public function reviewsDue(EducationalCentre $centre): array
    {
        $limit = $this->schedule->today()->modify('+' . self::REVIEW_HORIZON_DAYS . ' days');
        $due   = array_values(array_filter(
            $this->build($centre),
            static fn (DocumentMasterListRow $row): bool => $row->nextReviewAt !== null && $row->nextReviewAt <= $limit,
        ));
        usort($due, static fn (DocumentMasterListRow $a, DocumentMasterListRow $b): int => $a->nextReviewAt <=> $b->nextReviewAt);

        return $due;
    }

    /**
     * @param list<string>               $trail names of the ancestor sections
     * @param list<DocumentMasterListRow> $rows
     */
    private function addSection(DocumentSection $section, array $trail, array &$rows): void
    {
        $trail[] = $section->getName();
        $path    = implode(' › ', $trail);

        foreach ($this->folders->findBySection($section) as $folder) {
            if ($folder->isObsolete() || $folder->getActivity() !== null) {
                continue;
            }
            $responsibles = $this->responsibles($folder);
            foreach ($this->documents->findByFolder($folder) as $document) {
                $rows[] = $this->row($document, $path, $folder, $responsibles);
            }
        }

        foreach ($this->sections->findChildrenByParent($section) as $child) {
            $this->addSection($child, $trail, $rows);
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
