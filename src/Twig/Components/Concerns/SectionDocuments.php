<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\Folder;
use App\Model\ProfileAssignmentRow;
use App\Security\Voter\FolderVoter;
use App\Service\ActivityFolderCycleFilter;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Documents inside a folder: listing, rename, move, reorder, delete and the academic-year selector.
 */
trait SectionDocuments
{
    #[LiveProp(writable: true)]
    public string $renamingDocumentId = '';

    #[LiveProp(writable: true)]
    public string $renameDocumentName = '';

    /** The document's next review date while it's being edited ("Y-m-d", "" = none) — only offered outside activity folders. */
    #[LiveProp(writable: true)]
    public string $renameNextReview = '';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteDocumentId = '';

    #[LiveProp(writable: true)]
    public string $movingDocumentId = '';

    /**
     * Which academic year's submissions an expanded activity folder shows: "" for the current
     * one, ActivityFolderCycleFilter::ALL for every year, or a cycle key ("2025") — keyed by
     * folder id, since several folders can be expanded (and so selecting independently) at once.
     * Also what that folder's ZIP download holds. Back to "" whenever the folder is toggled.
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $folderCycles = [];

    // ── Documents ────────────────────────────────────────────────────────────

    /**
     * The folder's documents, grouped and ordered for display. Ungrouped folders: one implicit
     * group, documents in position order. Grouped folders: one group per upload profile/subprofile
     * actually in use, groups ordered alphabetically by profile name then subprofile name, documents
     * within a group in position order — manual reordering only ever happens within a group.
     *
     * @return list<array{label: ?string, rowKey: ?string, documents: list<Document>}>
     */
    public function getFolderDocumentGroups(Folder $folder): array
    {
        $fid       = $folder->getId()->toRfc4122();
        $selection = in_array($fid, $this->expandedFolderIds, true) ? ($this->folderCycles[$fid] ?? '') : '';
        $all       = $this->cycleFilter->filter($folder, $this->documents->findByFolder($folder), $selection);
        if ($all === []) {
            return [];
        }

        if (!$folder->isGroupByProfile()) {
            return [['label' => null, 'rowKey' => null, 'documents' => $all]];
        }

        $groups = [];
        foreach ($all as $document) {
            $profile = $document->getUploadProfile();
            $key     = $profile === null ? '' : ProfileAssignmentRow::keyFor($profile, $document->getUploadListItem());
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label'     => $profile?->getName() . ($document->getUploadListItem() !== null ? ' ' . $document->getUploadListItem()->getName() : ''),
                    'rowKey'    => $key === '' ? null : $key,
                    'documents' => [],
                ];
            }
            $groups[$key]['documents'][] = $document;
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));

        return $groups;
    }

    /**
     * Like getFolderDocumentGroups(), but each group's documents are narrowed down to whatever
     * matches the section-scoped search box (name, upload profile/subprofile, active revision's
     * uploader) — matching the profile or subprofile surfaces every document tagged with it, not
     * just ones whose own name happens to contain the query.
     * Display-only — reorder/sort LiveActions always call getFolderDocumentGroups() directly so
     * they keep operating on the full, unfiltered set regardless of what's currently searched.
     *
     * @return list<array{label: ?string, rowKey: ?string, documents: list<Document>}>
     */
    public function getVisibleFolderDocumentGroups(Folder $folder): array
    {
        $query = trim($this->localSearchQuery);
        // The folder's own title matching is itself the hit (see the highlighted folder name in
        // _folder.html.twig) — show everything inside it rather than also filtering documents down
        // to whichever ones separately happen to match too.
        if ($query === '' || str_contains(mb_strtolower($folder->getName()), mb_strtolower($query))) {
            return $this->getFolderDocumentGroups($folder);
        }

        $groups = [];
        foreach ($this->getFolderDocumentGroups($folder) as $group) {
            $matching = array_values(array_filter(
                $group['documents'],
                fn (Document $d): bool => $this->documentMatchesQuery($d, $query),
            ));
            if ($matching === []) {
                continue;
            }
            $groups[] = ['label' => $group['label'], 'rowKey' => $group['rowKey'], 'documents' => $matching];
        }

        return $groups;
    }

    private function documentMatchesQuery(Document $document, string $query): bool
    {
        $needle = mb_strtolower($query);
        if (str_contains(mb_strtolower($document->getName()), $needle)) {
            return true;
        }

        $profile = $document->getUploadProfile();
        if ($profile !== null && str_contains(mb_strtolower($profile->getName()), $needle)) {
            return true;
        }

        $listItem = $document->getUploadListItem();
        if ($listItem !== null && str_contains(mb_strtolower($listItem->getName()), $needle)) {
            return true;
        }

        $uploader = $document->getActiveRevision()?->getUploadedBy();
        if ($uploader !== null) {
            $fullName = $uploader->getName()->getFirstName() . ' ' . $uploader->getName()->getLastName();
            if (str_contains(mb_strtolower($fullName), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return DocumentRevision[] most recent first */
    public function getDocumentRevisions(Document $document): array
    {
        return $this->revisions->findByDocument($document);
    }

    /**
     * Other folders in the same section this teacher can move a document into.
     *
     * @return Folder[]
     */
    public function getMoveDestinationFolders(Document $document): array
    {
        $teacher = $this->teacher();

        return array_values(array_filter(
            $this->folders->findBySection($document->getFolder()->getDocumentSection()),
            fn (Folder $f): bool => $f !== $document->getFolder() && $this->access->canManageFolder($teacher, $f)
        ));
    }

    #[LiveAction]
    public function startRenameDocument(#[LiveArg] string $folderId, #[LiveArg] string $id): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $folder);
        $this->renamingDocumentId = $id;
        $this->renameDocumentName = $document->getName();
        $this->renameNextReview   = $document->getNextReviewAt()?->format('Y-m-d') ?? '';
    }

    #[LiveAction]
    public function cancelRenameDocument(): void
    {
        $this->renamingDocumentId = '';
    }

    #[LiveAction]
    public function saveRenameDocument(#[LiveArg] string $folderId): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $this->renamingDocumentId);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $folder);

        $name = trim($this->renameDocumentName);
        if ($name === '') {
            $this->errors = ['renameDocument' => $this->t('document.error.name_required')];

            return;
        }

        // A submission of an activity has no review cycle of its own: the field isn't offered there.
        if ($folder->getActivity() === null) {
            $nextReview = trim($this->renameNextReview);
            $date       = $nextReview === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $nextReview);
            if ($date === false || ($date !== null && $date->format('Y-m-d') !== $nextReview)) {
                $this->errors = ['renameDocument' => $this->t('document.error.invalid_next_review')];

                return;
            }
            $document->setNextReviewAt($date);
        }

        $document->setName($name);
        $this->em->flush();
        $this->renamingDocumentId = '';
        $this->errors             = [];
    }

    #[LiveAction]
    public function askDeleteDocument(#[LiveArg] string $folderId, #[LiveArg] string $id): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $id);
        if (!$this->access->canManageDocumentAsUploader($this->teacher(), $document)) {
            throw $this->createAccessDeniedException();
        }

        $this->confirmingDeleteDocumentId = $id;
    }

    #[LiveAction]
    public function cancelDeleteDocument(): void
    {
        $this->confirmingDeleteDocumentId = '';
    }

    #[LiveAction]
    public function deleteDocument(#[LiveArg] string $folderId, #[LiveArg] string $id): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $id);
        if (!$this->access->canManageDocumentAsUploader($this->teacher(), $document)) {
            throw $this->createAccessDeniedException();
        }

        $this->confirmingDeleteDocumentId = '';

        $this->trash->trashDocument($document, $this->teacher());

        $this->errors = [];
        $this->flashSuccess($this->t('document.flash.deleted'));
    }

    #[LiveAction]
    public function startMoveDocument(#[LiveArg] string $id): void
    {
        $this->movingDocumentId = $id;
    }

    #[LiveAction]
    public function cancelMoveDocument(): void
    {
        $this->movingDocumentId = '';
    }

    #[LiveAction]
    public function moveDocument(#[LiveArg] string $folderId, #[LiveArg] string $id, #[LiveArg] string $destinationFolderId): void
    {
        $folder      = $this->requireFolder($folderId);
        $document    = $this->requireDocument($folder, $id);
        $destination = $this->requireFolder($destinationFolderId);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $folder);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $destination);

        $document->setFolder($destination);
        $document->setPosition($this->documents->nextPosition($destination));
        $this->em->flush();

        $this->movingDocumentId = '';
        $this->flashSuccess($this->t('document.flash.moved'));
    }

    #[LiveAction]
    public function moveDocumentUp(#[LiveArg] string $folderId, #[LiveArg] string $id): void
    {
        $this->reorderDocument($folderId, $id, -1);
    }

    #[LiveAction]
    public function moveDocumentDown(#[LiveArg] string $folderId, #[LiveArg] string $id): void
    {
        $this->reorderDocument($folderId, $id, 1);
    }

    private function reorderDocument(string $folderId, string $id, int $direction): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $folder);

        // Siblings are those in the same group (same upload profile/subprofile, or all of them when
        // the folder isn't grouped) — reordering never crosses a group boundary.
        $siblings = null;
        foreach ($this->getFolderDocumentGroups($folder) as $group) {
            if (in_array($document, $group['documents'], true)) {
                $siblings = $group['documents'];

                break;
            }
        }
        if ($siblings === null) {
            return;
        }

        // Normalise to a clean 0..n sequence in the current display order first: documents
        // uploaded before positions were assigned all share position 0, so a raw position swap
        // between two of them would be a silent no-op.
        foreach ($siblings as $i => $sibling) {
            $sibling->setPosition($i);
        }

        $index    = (int) array_search($document, $siblings, true);
        $swapWith = $siblings[$index + $direction] ?? null;
        if ($swapWith === null) {
            $this->em->flush();

            return;
        }

        $document->setPosition($swapWith->getPosition());
        $swapWith->setPosition($index);
        $this->em->flush();
    }

    #[LiveAction]
    public function sortDocumentsAlphabetically(#[LiveArg] string $folderId, #[LiveArg] string $rowKey = ''): void
    {
        $folder = $this->requireFolder($folderId);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $folder);

        foreach ($this->getFolderDocumentGroups($folder) as $group) {
            if (($group['rowKey'] ?? '') !== $rowKey) {
                continue;
            }

            $documents = $group['documents'];
            usort($documents, static fn (Document $a, Document $b): int => strcmp($a->getName(), $b->getName()));
            foreach ($documents as $position => $document) {
                $document->setPosition($position);
            }
            $this->em->flush();

            return;
        }
    }
}
