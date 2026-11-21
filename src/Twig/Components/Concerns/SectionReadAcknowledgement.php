<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\AllowedFileFormat;
use App\Entity\Document;
use App\Entity\DocumentReadAcknowledgement;
use App\Entity\Folder;
use App\Model\ProfileAssignmentRow;
use App\Model\ReadAcknowledgementStatus;
use App\Security\Voter\EducationalCentreVoter;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Read acknowledgement (acuse de lectura) of the section browser: acknowledging and who-has-read panel.
 */
trait SectionReadAcknowledgement
{
    /** Document whose "who has read it" list is open, for a folder manager (read acknowledgement). */
    #[LiveProp(writable: true)]
    public string $readStatusDocumentId = '';

    // ── Read acknowledgement ("acuse de lectura") ─────────────────────────────

    /** Whether the current teacher has to acknowledge $document's version in force. */
    public function mustReadDocument(Document $document): bool
    {
        return $this->readAcknowledgements->mustRead($this->teacher(), $document);
    }

    public function getMyReadAcknowledgement(Document $document): ?DocumentReadAcknowledgement
    {
        return $this->readAcknowledgements->acknowledgementOf($this->teacher(), $document);
    }

    /** Who has read $document's version in force — only for whoever manages its folder. */
    public function getReadStatus(Document $document): ?ReadAcknowledgementStatus
    {
        if (!$this->canManageFolder($document->getFolder())) {
            return null;
        }

        return $this->readAcknowledgements->statusOf([$document])[$document->getId()->toRfc4122()] ?? null;
    }

    #[LiveAction]
    public function acknowledgeDocument(#[LiveArg] string $folderId, #[LiveArg] string $id): void
    {
        $document = $this->requireDocument($this->requireFolder($folderId), $id);
        if (!$this->readAcknowledgements->mustRead($this->teacher(), $document)) {
            throw $this->createAccessDeniedException();
        }

        $this->readAcknowledgements->acknowledge($this->teacher(), $document);
        $this->flashSuccess($this->t('read_ack.flash.acknowledged'));
    }

    #[LiveAction]
    public function toggleReadStatus(#[LiveArg] string $id): void
    {
        $this->readStatusDocumentId = $this->readStatusDocumentId === $id ? '' : $id;
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
     * extra "whole profile" row (every subprofile counts) ahead of its per-subprofile rows, and one
     * row per intermediate node of its list (every subprofile under it counts).
     *
     * @return ProfileAssignmentRow[]
     */
    public function getAvailableProfileRows(): array
    {
        return $this->rowBuilder->buildActiveRowsForPicker($this->centre);
    }

    /**
     * The fixed set of file-format restriction options, for the settings panel's checkbox group.
     *
     * @return AllowedFileFormat[]
     */
    public function getAvailableFormats(): array
    {
        return AllowedFileFormat::cases();
    }

    /**
     * Comma-joined, translated labels of $folder's allowed formats (e.g. "Documento no editable,
     * Imágenes") — for the notice shown above the document listing when uploads are restricted.
     * '' when the folder isn't restricted; callers gate on Folder::isFormatRestricted() first.
     */
    public function getAllowedFormatsLabel(Folder $folder): string
    {
        return implode(', ', array_map(
            fn (AllowedFileFormat $format): string => $this->translator->trans($format->labelKey(), [], 'admin'),
            $folder->getAllowedFormats(),
        ));
    }

    /** Replaces a folder's four profile-restriction lists, allowed formats and description with whatever the settings panel currently holds. */
    #[LiveAction]
    public function saveFolderProfiles(#[LiveArg] string $id): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
        $folder = $this->requireFolder($id);

        $rowsByKey = [];
        foreach ($this->getAvailableProfileRows() as $row) {
            $rowsByKey[$row->key()] = $row;
        }

        $this->folderProfiles->syncAll($folder, $this->responsibleProfileKeys, $this->uploadProfileKeys, $this->visibilityProfileKeys, $this->reviewProfileKeys, $rowsByKey);
        // tryFrom(), not from(): allowedFormatKeys comes straight from client checkbox values —
        // an unrecognised one (stale/tampered request) is silently dropped rather than a 500,
        // same defensive stance as the profile-key lookups above.
        $folder->setAllowedFormats(array_values(array_filter(array_map(
            static fn (string $key): ?AllowedFileFormat => AllowedFileFormat::tryFrom($key),
            $this->allowedFormatKeys,
        ))));

        $description = trim($this->editDescriptionValue);
        $folder->setDescription($description === '' ? null : $description);

        $this->em->flush();
        $this->flashSuccess($this->t('folder.flash.profiles_saved'));
    }
}
