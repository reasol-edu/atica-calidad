<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\Activity;
use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Security\Voter\FolderVoter;
use App\Service\DocumentTreeAccessChecker;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Revision panel of a submission's document (mirrors SectionRevisions, scoped to an activity's own folder).
 */
trait ActivityRevisions
{
    // ── Revision panel (mirrors SectionBrowserComponent's document-revision LiveProps) ──
    #[LiveProp(writable: true)]
    public string $revisionPanelDocumentId = '';

    #[LiveProp(writable: true)]
    public string $highlightedDocumentId = '';

    /**
     * The submission row a link (dashboard, bell, "Mis actividades") asked to land on: a slot key,
     * or 'next' for the first of the teacher's own that still has nothing accepted or waiting.
     */
    #[LiveProp]
    public string $highlightedSlotKey = '';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteDocumentId = '';

    #[LiveProp(writable: true)]
    public string $editingRevisionId = '';

    #[LiveProp(writable: true)]
    public string $editVersionValue = '';

    #[LiveProp(writable: true)]
    public string $editUploadedById = '';

    #[LiveProp(writable: true)]
    public string $editRevisedAtValue = '';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteRevisionId = '';


    // ── Revision panel (mirrors SectionBrowserComponent's equivalents, scoped to an activity's own folder) ──

    public function canManageFolder(Folder $folder): bool
    {
        return $this->access->canManageFolder($this->teacher(), $folder);
    }

    public function canReviewFolder(Folder $folder): bool
    {
        return $this->access->canReviewFolder($this->teacher(), $folder);
    }

    /** The author of a rejected revision may answer it with a new version — see DocumentTreeAccessChecker::canResubmitRejected(). */
    public function canResubmitRejected(Document $document): bool
    {
        return $this->access->canResubmitRejected($this->teacher(), $document);
    }

    public function canManageDocumentAsUploader(Document $document): bool
    {
        return $this->access->canManageDocumentAsUploader($this->teacher(), $document);
    }

    /**
     * Whether the current teacher may withdraw (delete) this document because it's still entirely
     * their own doing — see DocumentTreeAccessChecker::canWithdrawOwnUnreviewedUpload(). Drives the
     * delete button on a submission row for a teacher who is neither the folder's manager/reviewer
     * nor otherwise covered by canManageDocumentAsUploader(): deleting frees the slot, and the row
     * then shows a fresh dropzone in its place if — and only if — the activity's window still
     * allows submitting (the row already renders read-only otherwise, see
     * _activity_submissions.html.twig), so "replace" needs no separate action or permission.
     */
    public function canWithdrawOwnSubmission(Document $document): bool
    {
        return $this->access->canWithdrawOwnUnreviewedUpload($this->teacher(), $document);
    }

    /** Either reason a document can be deleted from here: full uploader management, or withdrawing one's own not-yet-reviewed submission. */
    private function canDeleteDocument(Document $document): bool
    {
        $teacher = $this->teacher();

        return $this->access->canManageDocumentAsUploader($teacher, $document)
            || $this->access->canWithdrawOwnUnreviewedUpload($teacher, $document);
    }

    /** Narrower than canManageFolder(): only admin/responsable de calidad may rewrite who uploaded a revision and when, or delete one outright. */
    public function canEditRevisionMetadata(): bool
    {
        return $this->access->isAdminOrQualityManager($this->teacher(), $this->centre);
    }

    /** @return Teacher[] ordered by name, for the "edit teacher" picker on a revision. */
    public function getCentreTeachers(): array
    {
        $year = $this->centre->getActiveAcademicYear();

        return $year === null ? [] : $this->teachers->findByAcademicYearOrderedByName($year);
    }

    /** @return DocumentRevision[] most recent first */
    public function getDocumentRevisions(Document $document): array
    {
        return $this->revisions->findByDocument($document);
    }

    #[LiveAction]
    public function toggleRevisionPanel(#[LiveArg] string $id): void
    {
        $this->revisionPanelDocumentId = $this->revisionPanelDocumentId === $id ? '' : $id;
    }

    #[LiveAction]
    public function askDeleteDocument(#[LiveArg] string $id): void
    {
        $document = $this->findDocument($id);
        if ($document === null || !$this->canDeleteDocument($document)) {
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
    public function deleteDocument(#[LiveArg] string $id): void
    {
        $document = $this->findDocument($id);
        if ($document === null || !$this->canDeleteDocument($document)) {
            throw $this->createAccessDeniedException();
        }

        $this->confirmingDeleteDocumentId = '';

        $this->trash->trashDocument($document, $this->teacher());

        $this->flashSuccess($this->t('document.flash.deleted'));
    }

    #[LiveAction]
    public function setActiveRevision(#[LiveArg] string $id, #[LiveArg] string $revisionId = ''): void
    {
        $document = $this->requireDocument($id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $document->getFolder());

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
    public function startEditRevision(#[LiveArg] string $id, #[LiveArg] string $revisionId): void
    {
        $document = $this->requireDocument($id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $document->getFolder());

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
    public function saveEditRevision(#[LiveArg] string $id): void
    {
        $document = $this->requireDocument($id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $document->getFolder());

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
    public function askDeleteRevision(#[LiveArg] string $id, #[LiveArg] string $revisionId): void
    {
        $document = $this->requireDocument($id);
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
    public function deleteRevision(#[LiveArg] string $id): void
    {
        $document = $this->requireDocument($id);
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

    private function requireDocument(string $id): Document
    {
        return $this->findDocument($id) ?? throw $this->createNotFoundException();
    }

    /**
     * Every id reaching this component from the client (LiveArgs, writable LiveProps) goes through
     * these two: a bare findById() would happily return another centre's activity or document, and
     * a permission check against $this->centre (e.g. requireEditPermission()) says nothing about
     * where the entity itself lives.
     */
    private function findActivity(string $id): ?Activity
    {
        $activity = $this->activities->findById($id);
        // By id: the centre of the component and the activity's may be two instances of the same row.
        if ($activity === null || !$activity->getCategory()->getEducationalCentre()->getId()->equals($this->centre->getId())) {
            return null;
        }

        // A hidden activity doesn't exist for anyone but whoever can edit activities.
        return $activity->isHidden() && !$this->canEdit() ? null : $activity;
    }

    private function findDocument(string $id): ?Document
    {
        $document = $this->documents->findById($id);

        return $document !== null && $document->getFolder()->getDocumentSection()->getEducationalCentre() === $this->centre ? $document : null;
    }
}
