<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\AllowedFileFormat;
use App\Entity\Folder;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Folders of the current section: structure (create/rename/reorder/delete), switches and profile lists.
 */
trait SectionFolders
{
    /**
     * Several folders can be expanded at once within a section — see toggleFolder().
     *
     * @var string[]
     */
    #[LiveProp(writable: true)]
    public array $expandedFolderIds = [];

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
    public string $editDescriptionValue = '';

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

    /**
     * AllowedFileFormat::value strings currently checked in the settings panel.
     *
     * @var string[]
     */
    #[LiveProp(writable: true)]
    public array $allowedFormatKeys = [];

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

    /** Expanding and collapsing is independent per folder — several can be open in the same section at once. */
    #[LiveAction]
    public function toggleFolder(#[LiveArg] string $id): void
    {
        if (in_array($id, $this->expandedFolderIds, true)) {
            $this->expandedFolderIds = array_values(array_diff($this->expandedFolderIds, [$id]));
        } else {
            $this->expandedFolderIds[] = $id;
        }
        unset($this->folderCycles[$id]);
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
        $siblings = $this->folders->findBySection($target->getDocumentSection());

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
        $this->responsibleProfileKeys = $this->folderProfiles->keysFor($folder->getResponsibleProfiles());
        $this->uploadProfileKeys      = $this->folderProfiles->keysFor($folder->getUploadProfiles());
        $this->visibilityProfileKeys  = $this->folderProfiles->keysFor($folder->getVisibilityProfiles());
        $this->reviewProfileKeys      = $this->folderProfiles->keysFor($folder->getReviewProfiles());
        $this->allowedFormatKeys      = array_map(static fn (AllowedFileFormat $f): string => $f->value, $folder->getAllowedFormats());
        $this->editDescriptionValue   = $folder->getDescription() ?? '';
    }

    #[LiveAction]
    public function toggleReadAcknowledgement(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $folder = $this->requireFolder($id);
        if ($folder->getActivity() !== null) {
            return;
        }
        $folder->setRequiresReadAcknowledgement(!$folder->requiresReadAcknowledgement());
        $this->em->flush();
    }
}
