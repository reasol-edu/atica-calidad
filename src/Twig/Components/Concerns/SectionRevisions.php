<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Security\Voter\FolderVoter;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Revision panel of a document: pick the active revision, edit metadata, delete.
 */
trait SectionRevisions
{
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

        $typed      = trim($this->editVersionValue);
        $newVersion = (int) $typed;
        // The floor only applies to a number being changed: a revision that already is "v0" stays editable if the centre later raises its minimum to 1.
        if ($typed === '' || !is_numeric($typed) || ($newVersion !== $revision->getVersion() && $newVersion < $this->getMinRevision())) {
            $this->errors = ['editRevision' => $this->translator->trans('document.error.invalid_version', ['%min%' => $this->getMinRevision()], 'admin')];

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
}
