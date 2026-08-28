<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\FolderResponsibleProfile;
use App\Entity\FolderReviewProfile;
use App\Entity\FolderUploadProfile;
use App\Entity\FolderVisibilityProfile;
use App\Entity\Teacher;
use App\Model\ProfileAssignmentRow;
use App\Repository\DocumentRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\FolderRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Security\Voter\FolderVoter;
use App\Service\DocumentFileGarbageCollector;
use App\Service\DocumentTreeAccessChecker;
use App\Service\ProfileAssignmentRowBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The "Ver" tab of Árbol documental: browse the section tree (any teacher, respecting each
 * section's own profile restrictions), and within a section manage its folders and browse/act on
 * their documents. Folder structural configuration (create/rename/reorder/delete, the three
 * switches, the four profile lists) is reserved to responsable de calidad/equipo
 * directivo/administración (EducationalCentreVoter::RESPONSIBILITIES) — once a folder exists, its
 * own responsible/upload/review profiles govern permissions over its *content* (documents and
 * revisions) via FolderVoter/DocumentTreeAccessChecker, available to any teacher who qualifies.
 */
#[AsLiveComponent]
class SectionBrowserComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    /** '' means the root level. */
    #[LiveProp(writable: true)]
    public string $currentSectionId = '';

    #[LiveProp(writable: true)]
    public string $expandedFolderId = '';

    #[LiveProp(writable: true)]
    public bool $showObsoleteFolders = false;

    // ── Folder structure (RESPONSIBILITIES only) ────────────────────────────

    #[LiveProp(writable: true)]
    public bool $addingFolder = false;

    #[LiveProp(writable: true)]
    public string $addFolderName = '';

    #[LiveProp(writable: true)]
    public string $renamingFolderId = '';

    #[LiveProp(writable: true)]
    public string $renameFolderName = '';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteFolderId = '';

    #[LiveProp(writable: true)]
    public string $folderSettingsPanelId = '';

    #[LiveProp(writable: true)]
    public string $confirmingObsoleteFolderId = '';

    /** @var string[] */
    #[LiveProp(writable: true)]
    public array $responsibleProfileKeys = [];

    /** @var string[] */
    #[LiveProp(writable: true)]
    public array $uploadProfileKeys = [];

    /** @var string[] */
    #[LiveProp(writable: true)]
    public array $visibilityProfileKeys = [];

    /** @var string[] */
    #[LiveProp(writable: true)]
    public array $reviewProfileKeys = [];

    // ── Document content ──────────────────────────────────────────────────

    #[LiveProp(writable: true)]
    public string $renamingDocumentId = '';

    #[LiveProp(writable: true)]
    public string $renameDocumentName = '';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteDocumentId = '';

    #[LiveProp(writable: true)]
    public string $movingDocumentId = '';

    #[LiveProp(writable: true)]
    public string $revisionPanelDocumentId = '';

    /** A document just landed on via search — briefly pulses instead of opening its revision panel. */
    #[LiveProp(writable: true)]
    public string $highlightedDocumentId = '';

    #[LiveProp(writable: true)]
    public string $editingRevisionId = '';

    #[LiveProp(writable: true)]
    public string $editVersionValue = '';

    /** Only editable by an admin/responsable de calidad — see canEditRevisionMetadata(). */
    #[LiveProp(writable: true)]
    public string $editUploadedById = '';

    /** Datetime-local input value ('Y-m-d\TH:i'). Only editable by an admin/responsable de calidad. */
    #[LiveProp(writable: true)]
    public string $editRevisedAtValue = '';

    /** Only deletable by an admin/responsable de calidad — see canEditRevisionMetadata(). */
    #[LiveProp(writable: true)]
    public string $confirmingDeleteRevisionId = '';

    // ── Search ───────────────────────────────────────────────────────────────

    #[LiveProp(writable: true)]
    public string $searchQuery = '';

    /** Filters the current section's folders' document lists — unlike searchQuery, never leaves the section. */
    #[LiveProp(writable: true)]
    public string $localSearchQuery = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly DocumentSectionRepository $sections,
        private readonly FolderRepository $folders,
        private readonly DocumentRepository $documents,
        private readonly DocumentRevisionRepository $revisions,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ProfileAssignmentRowBuilder $rowBuilder,
        private readonly DocumentFileGarbageCollector $garbageCollector,
        private readonly TeacherRepository $teachers,
    ) {}

    public function mount(
        EducationalCentre $centre,
        string $initialSectionId = '',
        string $initialFolderId = '',
        string $initialDocumentId = '',
        string $initialSettingsFolderId = '',
        string $initialHighlightDocumentId = '',
    ): void {
        $this->centre = $centre;
        $this->applyLocation($initialSectionId, $initialFolderId, $initialDocumentId, $initialSettingsFolderId, $initialHighlightDocumentId);
    }

    /**
     * Resolves a (section, folder, document, settings-folder, highlighted-document) tuple — from
     * the initial page load's query string or from a browser back/forward navigation — into the
     * browsing state, silently falling back to whatever part is still valid (e.g. a folder that
     * got deleted since the URL was recorded, or a settings folder the current teacher can no
     * longer edit). $documentId opens that document's revision panel (the clock icon); the
     * separate $highlightDocumentId instead just flashes it (search results land here without
     * forcing the revision panel open — see openSearchResult()).
     */
    private function applyLocation(string $sectionId, string $folderId, string $documentId, string $settingsFolderId = '', string $highlightDocumentId = ''): void
    {
        $this->currentSectionId = $sectionId !== '' && $this->sections->findByIdAndCentre($sectionId, $this->centre) !== null
            ? $sectionId
            : '';

        $section = $this->getCurrentSection();
        if ($section === null) {
            return;
        }

        if ($folderId !== '') {
            $folder = $this->folders->findByIdAndSection($folderId, $section);
            if ($folder !== null && $this->access->canViewFolder($this->teacher(), $folder)) {
                $this->expandedFolderId = $folderId;
                if ($documentId !== '' && $this->documents->findByIdAndFolder($documentId, $folder) !== null) {
                    $this->revisionPanelDocumentId = $documentId;
                }
                if ($highlightDocumentId !== '' && $this->documents->findByIdAndFolder($highlightDocumentId, $folder) !== null) {
                    $this->highlightedDocumentId = $highlightDocumentId;
                }
            }
        }

        if ($settingsFolderId !== '' && $this->canEditStructure()) {
            $settingsFolder = $this->folders->findByIdAndSection($settingsFolderId, $section);
            if ($settingsFolder !== null) {
                $this->openFolderSettings($settingsFolder);
            }
        }
    }

    /**
     * Restores browsing state from the URL after the user hits the browser's back/forward button —
     * the counterpart to dispatchLocation()'s pushState on the JS side. Never dispatches a location
     * event itself, or every back/forward press would push a new (forward) history entry.
     */
    #[LiveAction]
    public function syncFromUrl(#[LiveArg] string $section = '', #[LiveArg] string $folder = '', #[LiveArg] string $document = '', #[LiveArg] string $settings = '', #[LiveArg] string $highlight = ''): void
    {
        $this->resetTransientState();
        $this->applyLocation($section, $folder, $document, $settings, $highlight);
    }

    /** Tells the document-tree-url Stimulus controller to reflect the current location in the URL (pushState). */
    private function dispatchLocation(): void
    {
        $this->dispatchBrowserEvent('document-tree:location', [
            'section'  => $this->currentSectionId,
            'folder'   => $this->expandedFolderId,
            'document' => $this->revisionPanelDocumentId,
            'settings' => $this->folderSettingsPanelId,
        ]);
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    public function canEditStructure(): bool
    {
        return $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
    }

    public function canManageFolder(Folder $folder): bool
    {
        return $this->access->canManageFolder($this->teacher(), $folder);
    }

    public function canUploadToFolder(Folder $folder): bool
    {
        return $this->access->canUploadToFolder($this->teacher(), $folder);
    }

    /** Folder responsible, or (the sole exception) the teacher who uploaded this document's current active revision. */
    public function canManageDocumentAsUploader(Document $document): bool
    {
        return $this->access->canManageDocumentAsUploader($this->teacher(), $document);
    }

    public function canReviewFolder(Folder $folder): bool
    {
        return $this->access->canReviewFolder($this->teacher(), $folder);
    }

    /** @return ProfileAssignmentRow[] for the inline upload-profile picker next to each staged file. */
    public function getAllowedUploadProfileRows(Folder $folder): array
    {
        return $this->access->allowedUploadProfileRows($this->teacher(), $folder);
    }

    /** Narrower than canManageFolder(): only admin/responsable de calidad may rewrite who uploaded a revision and when — not every folder-level responsible. */
    public function canEditRevisionMetadata(): bool
    {
        return $this->access->isAdminOrQualityManager($this->teacher(), $this->centre);
    }

    /** @return Teacher[] ordered by name, for the "editar docente" picker on a revision. */
    public function getCentreTeachers(): array
    {
        $year = $this->centre->getActiveAcademicYear();

        return $year === null ? [] : $this->teachers->findByAcademicYearOrderedByName($year);
    }

    // ── Section navigation ───────────────────────────────────────────────────

    public function getCurrentSection(): ?DocumentSection
    {
        if ($this->currentSectionId === '') {
            return null;
        }

        return $this->sections->findByIdAndCentre($this->currentSectionId, $this->centre);
    }

    /** @return DocumentSection[] */
    public function getVisibleSections(): array
    {
        $parent   = $this->getCurrentSection();
        $siblings = $parent === null
            ? $this->sections->findRootsByCentre($this->centre)
            : $this->sections->findChildrenByParent($parent);

        $teacher = $this->teacher();

        return array_values(array_filter(
            $siblings,
            fn (DocumentSection $s): bool => $this->access->canViewSection($teacher, $s)
        ));
    }

    /** @return DocumentSection[] root-first path of ancestors down to (and including) the current section */
    public function getBreadcrumb(): array
    {
        $trail = [];
        for ($item = $this->getCurrentSection(); $item !== null; $item = $item->getParent()) {
            array_unshift($trail, $item);
        }

        return $trail;
    }

    #[LiveAction]
    public function openLevel(#[LiveArg] string $id): void
    {
        $this->currentSectionId = $id;
        $this->resetTransientState();
        $this->dispatchLocation();
    }

    private function resetTransientState(): void
    {
        $this->expandedFolderId          = '';
        $this->addingFolder              = false;
        $this->renamingFolderId          = '';
        $this->confirmingDeleteFolderId  = '';
        $this->folderSettingsPanelId     = '';
        $this->confirmingObsoleteFolderId = '';
        $this->renamingDocumentId        = '';
        $this->confirmingDeleteDocumentId = '';
        $this->movingDocumentId          = '';
        $this->revisionPanelDocumentId   = '';
        $this->highlightedDocumentId     = '';
        $this->editingRevisionId         = '';
        $this->confirmingDeleteRevisionId = '';
        $this->localSearchQuery          = '';
        $this->errors                    = [];
    }

    // ── Folders ──────────────────────────────────────────────────────────────

    /** @return Folder[] */
    public function getVisibleFolders(): array
    {
        $section = $this->getCurrentSection();
        if ($section === null) {
            return [];
        }

        $teacher     = $this->teacher();
        $canRevealObsolete = $this->access->isAdminOrQualityManager($teacher, $this->centre) && $this->showObsoleteFolders;

        $folders = [];
        foreach ($this->folders->findBySection($section) as $folder) {
            // A folder whose settings panel is open stays visible even if it just became
            // obsolete — otherwise marking it obsolete makes it (and the toggle to undo it)
            // vanish from view mid-edit.
            $isOpenForSettings = $folder->getId()->toRfc4122() === $this->folderSettingsPanelId;
            if ($folder->isObsolete() && !$canRevealObsolete && !$isOpenForSettings) {
                continue;
            }
            if (!$this->access->canViewFolder($teacher, $folder)) {
                continue;
            }
            $folders[] = $folder;
        }

        return $folders;
    }

    #[LiveAction]
    public function toggleFolder(#[LiveArg] string $id): void
    {
        $this->expandedFolderId = $this->expandedFolderId === $id ? '' : $id;
        $this->renamingDocumentId         = '';
        $this->confirmingDeleteDocumentId = '';
        $this->movingDocumentId           = '';
        $this->revisionPanelDocumentId    = '';
        $this->highlightedDocumentId      = '';
        $this->editingRevisionId          = '';
        $this->confirmingDeleteRevisionId = '';
        $this->dispatchLocation();
    }

    #[LiveAction]
    public function toggleShowObsoleteFolders(): void
    {
        $this->showObsoleteFolders = !$this->showObsoleteFolders;
    }

    #[LiveAction]
    public function startAddFolder(): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $this->addingFolder = true;
        $this->addFolderName = '';
    }

    #[LiveAction]
    public function cancelAddFolder(): void
    {
        $this->addingFolder = false;
    }

    #[LiveAction]
    public function addFolder(): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $section = $this->getCurrentSection();
        $name    = trim($this->addFolderName);
        if ($section === null || $name === '') {
            $this->errors = ['addFolder' => $this->t('folder.error.name_required')];

            return;
        }

        $folder = (new Folder())
            ->setDocumentSection($section)
            ->setName($name)
            ->setPosition($this->folders->nextPosition($section));
        $this->em->persist($folder);
        $this->em->flush();

        $this->addingFolder  = false;
        $this->addFolderName = '';
        $this->errors        = [];
    }

    #[LiveAction]
    public function startRenameFolder(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $folder = $this->requireFolder($id);
        $this->renamingFolderId = $id;
        $this->renameFolderName = $folder->getName();
    }

    #[LiveAction]
    public function cancelRenameFolder(): void
    {
        $this->renamingFolderId = '';
    }

    #[LiveAction]
    public function saveRenameFolder(): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $folder = $this->requireFolder($this->renamingFolderId);
        $name   = trim($this->renameFolderName);
        if ($name === '') {
            $this->errors = ['renameFolder' => $this->t('folder.error.name_required')];

            return;
        }

        $folder->setName($name);
        $this->em->flush();
        $this->renamingFolderId = '';
        $this->errors           = [];
    }

    #[LiveAction]
    public function askDeleteFolder(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $this->confirmingDeleteFolderId = $id;
    }

    #[LiveAction]
    public function cancelDeleteFolder(): void
    {
        $this->confirmingDeleteFolderId = '';
    }

    #[LiveAction]
    public function deleteFolder(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $folder = $this->requireFolder($id);
        if (!$folder->isLeaf()) {
            $this->errors = ['deleteFolder' => $this->t('folder.error.delete_has_documents')];

            return;
        }

        $this->confirmingDeleteFolderId = '';
        $this->em->remove($folder);
        $this->em->flush();
        $this->errors = [];
        $this->flashSuccess($this->t('folder.flash.deleted'));
    }

    #[LiveAction]
    public function moveFolderUp(#[LiveArg] string $id): void
    {
        $this->moveFolder($id, -1);
    }

    #[LiveAction]
    public function moveFolderDown(#[LiveArg] string $id): void
    {
        $this->moveFolder($id, 1);
    }

    private function moveFolder(string $id, int $direction): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $target = $this->requireFolder($id);
        $siblings = array_values($this->folders->findBySection($target->getDocumentSection()));

        $index = null;
        foreach ($siblings as $i => $sibling) {
            if ($sibling === $target) {
                $index = $i;

                break;
            }
        }

        $swapWith = $index === null ? null : ($siblings[$index + $direction] ?? null);
        if ($swapWith === null) {
            return;
        }

        $targetPosition = $target->getPosition();
        $target->setPosition($swapWith->getPosition());
        $swapWith->setPosition($targetPosition);
        $this->em->flush();
    }

    #[LiveAction]
    public function toggleFolderSettings(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        if ($this->folderSettingsPanelId === $id) {
            $this->folderSettingsPanelId = '';
        } else {
            $this->openFolderSettings($this->requireFolder($id));
        }
        $this->dispatchLocation();
    }

    private function openFolderSettings(Folder $folder): void
    {
        $this->folderSettingsPanelId  = $folder->getId()->toRfc4122();
        $this->responsibleProfileKeys = $this->keysFor($folder->getResponsibleProfiles());
        $this->uploadProfileKeys      = $this->keysFor($folder->getUploadProfiles());
        $this->visibilityProfileKeys  = $this->keysFor($folder->getVisibilityProfiles());
        $this->reviewProfileKeys      = $this->keysFor($folder->getReviewProfiles());
    }

    /**
     * @param iterable<FolderResponsibleProfile|FolderUploadProfile|FolderVisibilityProfile|FolderReviewProfile> $restrictions
     *
     * @return string[]
     */
    private function keysFor(iterable $restrictions): array
    {
        $keys = [];
        foreach ($restrictions as $restriction) {
            $keys[] = ProfileAssignmentRow::keyFor($restriction->getSpecificProfile(), $restriction->getListItem());
        }

        return $keys;
    }

    #[LiveAction]
    public function toggleGroupByProfile(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $folder = $this->requireFolder($id);
        $folder->setGroupByProfile(!$folder->isGroupByProfile());
        $this->em->flush();
    }

    #[LiveAction]
    public function toggleAutoArchive(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $folder = $this->requireFolder($id);
        $folder->setAutoArchive(!$folder->isAutoArchive());
        $this->em->flush();
    }

    #[LiveAction]
    public function askMarkObsolete(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $this->confirmingObsoleteFolderId = $id;
    }

    #[LiveAction]
    public function cancelMarkObsolete(): void
    {
        $this->confirmingObsoleteFolderId = '';
    }

    #[LiveAction]
    public function toggleObsolete(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $folder = $this->requireFolder($id);
        $folder->setObsolete(!$folder->isObsolete());
        $this->confirmingObsoleteFolderId = '';
        $this->em->flush();
    }

    /**
     * Options for the folder profile-restriction pickers: a list-associated profile also gets one
     * extra "whole profile" row (every subperfil counts) ahead of its per-subperfil rows.
     *
     * @return ProfileAssignmentRow[]
     */
    public function getAvailableProfileRows(): array
    {
        return $this->rowBuilder->buildActiveRowsWithWholeProfileOption($this->centre);
    }

    /** Replaces a folder's four profile-restriction lists with whatever the pickers currently hold. */
    #[LiveAction]
    public function saveFolderProfiles(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $folder = $this->requireFolder($id);

        $rowsByKey = [];
        foreach ($this->getAvailableProfileRows() as $row) {
            $rowsByKey[$row->key()] = $row;
        }

        $this->syncResponsibleProfiles($folder, $this->responsibleProfileKeys, $rowsByKey);
        $this->syncUploadProfiles($folder, $this->uploadProfileKeys, $rowsByKey);
        $this->syncVisibilityProfiles($folder, $this->visibilityProfileKeys, $rowsByKey);
        $this->syncReviewProfiles($folder, $this->reviewProfileKeys, $rowsByKey);

        $this->em->flush();
        $this->flashSuccess($this->t('folder.flash.profiles_saved'));
    }

    /**
     * @param string[]                        $keys
     * @param array<string, ProfileAssignmentRow> $rowsByKey
     */
    private function syncResponsibleProfiles(Folder $folder, array $keys, array $rowsByKey): void
    {
        foreach (iterator_to_array($folder->getResponsibleProfiles()) as $restriction) {
            $key = ProfileAssignmentRow::keyFor($restriction->getSpecificProfile(), $restriction->getListItem());
            if (!in_array($key, $keys, true)) {
                $folder->removeResponsibleProfile($restriction);
            }
        }
        foreach ($keys as $key) {
            $row = $rowsByKey[$key] ?? null;
            if ($row !== null && !$folder->hasResponsibleProfile($row->profile, $row->listItem)) {
                $folder->addResponsibleProfile($row->profile, $row->listItem);
            }
        }
    }

    /**
     * @param string[]                        $keys
     * @param array<string, ProfileAssignmentRow> $rowsByKey
     */
    private function syncUploadProfiles(Folder $folder, array $keys, array $rowsByKey): void
    {
        foreach (iterator_to_array($folder->getUploadProfiles()) as $restriction) {
            $key = ProfileAssignmentRow::keyFor($restriction->getSpecificProfile(), $restriction->getListItem());
            if (!in_array($key, $keys, true)) {
                $folder->removeUploadProfile($restriction);
            }
        }
        foreach ($keys as $key) {
            $row = $rowsByKey[$key] ?? null;
            if ($row !== null && !$folder->hasUploadProfile($row->profile, $row->listItem)) {
                $folder->addUploadProfile($row->profile, $row->listItem);
            }
        }
    }

    /**
     * @param string[]                        $keys
     * @param array<string, ProfileAssignmentRow> $rowsByKey
     */
    private function syncVisibilityProfiles(Folder $folder, array $keys, array $rowsByKey): void
    {
        foreach (iterator_to_array($folder->getVisibilityProfiles()) as $restriction) {
            $key = ProfileAssignmentRow::keyFor($restriction->getSpecificProfile(), $restriction->getListItem());
            if (!in_array($key, $keys, true)) {
                $folder->removeVisibilityProfile($restriction);
            }
        }
        foreach ($keys as $key) {
            $row = $rowsByKey[$key] ?? null;
            if ($row !== null && !$folder->hasVisibilityProfile($row->profile, $row->listItem)) {
                $folder->addVisibilityProfile($row->profile, $row->listItem);
            }
        }
    }

    /**
     * @param string[]                        $keys
     * @param array<string, ProfileAssignmentRow> $rowsByKey
     */
    private function syncReviewProfiles(Folder $folder, array $keys, array $rowsByKey): void
    {
        foreach (iterator_to_array($folder->getReviewProfiles()) as $restriction) {
            $key = ProfileAssignmentRow::keyFor($restriction->getSpecificProfile(), $restriction->getListItem());
            if (!in_array($key, $keys, true)) {
                $folder->removeReviewProfile($restriction);
            }
        }
        foreach ($keys as $key) {
            $row = $rowsByKey[$key] ?? null;
            if ($row !== null && !$folder->hasReviewProfile($row->profile, $row->listItem)) {
                $folder->addReviewProfile($row->profile, $row->listItem);
            }
        }
    }

    // ── Documents ────────────────────────────────────────────────────────────

    /**
     * The folder's documents, grouped and ordered for display. Ungrouped folders: one implicit
     * group, documents in position order. Grouped folders: one group per upload profile/subperfil
     * actually in use, groups ordered alphabetically by profile name then subperfil name, documents
     * within a group in position order — manual reordering only ever happens within a group.
     *
     * @return list<array{label: ?string, rowKey: ?string, documents: list<Document>}>
     */
    public function getFolderDocumentGroups(Folder $folder): array
    {
        $all = $this->documents->findByFolder($folder);
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
     * matches the section-scoped search box (name, upload profile/subperfil, active revision's
     * uploader) — matching the profile or subperfil surfaces every document tagged with it, not
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

        $files = [];
        foreach ($document->getRevisions() as $revision) {
            $files[] = $revision->getFile();
        }

        $this->em->remove($document);
        $this->em->flush();

        foreach ($files as $file) {
            $this->garbageCollector->deleteIfOrphaned($file);
        }

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

        // Siblings are those in the same group (same upload profile/subperfil, or all of them when
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

        $index = null;
        foreach ($siblings as $i => $sibling) {
            if ($sibling === $document) {
                $index = $i;

                break;
            }
        }

        $swapWith = $index === null ? null : ($siblings[$index + $direction] ?? null);
        if ($swapWith === null) {
            return;
        }

        $position = $document->getPosition();
        $document->setPosition($swapWith->getPosition());
        $swapWith->setPosition($position);
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

    #[LiveAction]
    public function toggleRevisionPanel(#[LiveArg] string $id): void
    {
        $this->revisionPanelDocumentId = $this->revisionPanelDocumentId === $id ? '' : $id;
        $this->dispatchLocation();
    }

    #[LiveAction]
    public function setActiveRevision(#[LiveArg] string $folderId, #[LiveArg] string $id, #[LiveArg] string $revisionId = ''): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $folder);

        if ($revisionId === '') {
            $document->setActiveRevision(null);
            $this->em->flush();

            return;
        }

        $revision = $this->revisions->findByIdAndDocument($revisionId, $document);
        if ($revision === null || !$revision->isApproved()) {
            $this->errors = ['activeRevision' => $this->t('document.error.revision_not_approved')];

            return;
        }

        $document->setActiveRevision($revision);
        $this->em->flush();
        $this->errors = [];
    }

    #[LiveAction]
    public function startEditRevision(#[LiveArg] string $folderId, #[LiveArg] string $id, #[LiveArg] string $revisionId): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $folder);

        $revision = $this->revisions->findByIdAndDocument($revisionId, $document);
        if ($revision === null) {
            throw $this->createNotFoundException();
        }

        $this->editingRevisionId = $revisionId;
        $this->editVersionValue  = (string) $revision->getVersion();
        if ($this->canEditRevisionMetadata()) {
            $this->editUploadedById   = $revision->getUploadedBy()->getId()->toRfc4122();
            $this->editRevisedAtValue = $revision->getRevisedAt()->format('Y-m-d\TH:i');
        }
    }

    #[LiveAction]
    public function cancelEditRevision(): void
    {
        $this->editingRevisionId = '';
        $this->errors            = [];
    }

    #[LiveAction]
    public function saveEditRevision(#[LiveArg] string $folderId, #[LiveArg] string $id): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $folder);

        $revision = $this->revisions->findByIdAndDocument($this->editingRevisionId, $document);
        if ($revision === null) {
            throw $this->createNotFoundException();
        }

        $newVersion = (int) trim($this->editVersionValue);
        if ($newVersion < 1) {
            $this->errors = ['editRevision' => $this->t('document.error.invalid_version')];

            return;
        }
        if ($newVersion !== $revision->getVersion() && $document->hasVersion($newVersion)) {
            $this->errors = ['editRevision' => $this->t('document.error.version_in_use')];

            return;
        }

        if ($this->canEditRevisionMetadata()) {
            $year    = $this->centre->getActiveAcademicYear();
            $teacher = $year === null ? null : $this->teachers->findByAcademicYearAndId($year, $this->editUploadedById);
            if ($teacher === null) {
                $this->errors = ['editRevision' => $this->t('document.error.uploaded_by_invalid')];

                return;
            }

            $revisedAt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $this->editRevisedAtValue);
            if ($revisedAt === false) {
                $this->errors = ['editRevision' => $this->t('document.error.invalid_revised_at')];

                return;
            }

            $revision->setUploadedBy($teacher);
            $revision->setRevisedAt($revisedAt);
        }

        $revision->setVersion($newVersion);
        $this->em->flush();

        $this->editingRevisionId = '';
        $this->errors            = [];
    }

    #[LiveAction]
    public function askDeleteRevision(#[LiveArg] string $folderId, #[LiveArg] string $id, #[LiveArg] string $revisionId): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $id);
        if (!$this->canEditRevisionMetadata()) {
            throw $this->createAccessDeniedException();
        }
        if ($this->revisions->findByIdAndDocument($revisionId, $document) === null) {
            throw $this->createNotFoundException();
        }

        $this->confirmingDeleteRevisionId = $revisionId;
    }

    #[LiveAction]
    public function cancelDeleteRevision(): void
    {
        $this->confirmingDeleteRevisionId = '';
    }

    /** Only an admin/responsable de calidad may delete a revision outright — see canEditRevisionMetadata(). */
    #[LiveAction]
    public function deleteRevision(#[LiveArg] string $folderId, #[LiveArg] string $id): void
    {
        $folder   = $this->requireFolder($folderId);
        $document = $this->requireDocument($folder, $id);
        if (!$this->canEditRevisionMetadata()) {
            throw $this->createAccessDeniedException();
        }

        $revision = $this->revisions->findByIdAndDocument($this->confirmingDeleteRevisionId, $document);
        if ($revision === null) {
            throw $this->createNotFoundException();
        }

        $this->confirmingDeleteRevisionId = '';

        if ($document->getActiveRevision() === $revision) {
            $document->setActiveRevision(null);
        }

        $file = $revision->getFile();
        $document->getRevisions()->removeElement($revision);
        $this->em->remove($revision);
        $this->em->flush();

        $this->garbageCollector->deleteIfOrphaned($file);

        $this->flashSuccess($this->t('document.flash.revision_deleted'));
    }

    // ── Search ───────────────────────────────────────────────────────────────

    /**
     * Documents matching the current search query anywhere in the centre's tree, each paired with
     * its section-path/folder breadcrumb for display — access-filtered the same way section/folder
     * browsing is (DocumentTreeAccessChecker), so search never surfaces something the teacher
     * couldn't otherwise reach.
     *
     * @return list<array{document: Document, path: string}>
     */
    public function getSearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->documents->searchByCentre($this->centre, $query) as $document) {
            if (!$this->access->canViewDocument($teacher, $document)) {
                continue;
            }
            $results[] = ['document' => $document, 'path' => $this->documentPath($document)];
        }

        return $results;
    }

    /**
     * Folders whose own name matches the current search query anywhere in the centre's tree, each
     * paired with its section-path breadcrumb — same access filtering as document search. Shown as
     * a visually distinct group from document results (see the "search.folders_heading" label) so
     * it's clear a hit is a folder, not a document.
     *
     * @return list<array{folder: Folder, path: string}>
     */
    public function getFolderSearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->folders->searchByCentre($this->centre, $query) as $folder) {
            if (!$this->access->canViewFolder($teacher, $folder)) {
                continue;
            }
            $results[] = ['folder' => $folder, 'path' => $this->folderPath($folder)];
        }

        return $results;
    }

    /** @return list<string> root-first names of a section's ancestor trail, including the section itself */
    private function sectionTrail(DocumentSection $section): array
    {
        $trail = [];
        for ($s = $section; $s !== null; $s = $s->getParent()) {
            array_unshift($trail, $s->getName());
        }

        return $trail;
    }

    private function documentPath(Document $document): string
    {
        $folder  = $document->getFolder();
        $trail   = $this->sectionTrail($folder->getDocumentSection());
        $trail[] = $folder->getName();

        return implode(' › ', $trail);
    }

    private function folderPath(Folder $folder): string
    {
        return implode(' › ', $this->sectionTrail($folder->getDocumentSection()));
    }

    #[LiveAction]
    public function clearSearch(): void
    {
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function clearLocalSearch(): void
    {
        $this->localSearchQuery = '';
    }

    /** Jumps straight to a search result: opens its section, expands its folder, and briefly highlights the document — never forces its revision panel open. */
    #[LiveAction]
    public function openSearchResult(#[LiveArg] string $documentId): void
    {
        $teacher  = $this->teacher();
        $document = $this->documents->findById($documentId);
        if ($document === null || $document->getFolder()->getEducationalCentre() !== $this->centre) {
            throw $this->createNotFoundException();
        }
        if (!$this->access->canViewDocument($teacher, $document)) {
            throw $this->createAccessDeniedException();
        }

        $folder = $document->getFolder();
        $this->currentSectionId = $folder->getDocumentSection()->getId()->toRfc4122();
        $this->resetTransientState();
        $this->expandedFolderId       = $folder->getId()->toRfc4122();
        $this->highlightedDocumentId  = $documentId;
        $this->searchQuery            = '';
        $this->dispatchLocation();
    }

    /** Jumps straight to a folder search result: opens its section and expands it. */
    #[LiveAction]
    public function openFolderSearchResult(#[LiveArg] string $folderId): void
    {
        $teacher = $this->teacher();
        $folder  = $this->folders->findById($folderId);
        if ($folder === null || $folder->getEducationalCentre() !== $this->centre) {
            throw $this->createNotFoundException();
        }
        if (!$this->access->canViewFolder($teacher, $folder)) {
            throw $this->createAccessDeniedException();
        }

        $this->currentSectionId = $folder->getDocumentSection()->getId()->toRfc4122();
        $this->resetTransientState();
        $this->expandedFolderId = $folder->getId()->toRfc4122();
        $this->searchQuery      = '';
        $this->dispatchLocation();
    }

    private function requireDocument(Folder $folder, string $id): Document
    {
        $document = $this->documents->findByIdAndFolder($id, $folder);
        if ($document === null) {
            throw $this->createNotFoundException();
        }

        return $document;
    }

    private function requireFolder(string $id): Folder
    {
        $section = $this->getCurrentSection();
        $folder  = $section === null ? null : $this->folders->findByIdAndSection($id, $section);
        if ($folder === null) {
            throw $this->createNotFoundException();
        }

        return $folder;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    private function flashSuccess(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $message]);
    }
}
