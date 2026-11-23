<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DocumentReviewNotificationKind;
use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Service\Form\FormApprovalChain;
use App\Service\Form\FormModelService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Approves or rejects a revision pending review — one at a time from a folder or an activity
 * submission (FolderController), or several of an activity's submissions at once
 * (ActivityController::bulkReview()) — always with the same effects: the revision's state, the
 * document's version in force, the activity log entry and the email to whoever uploaded it.
 * Permission checks are the caller's (FolderVoter::REVIEW on the revision's folder).
 */
final class DocumentRevisionReviewer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActivityLogger $activityLogger,
        private readonly DocumentReviewOutcomeNotifier $outcomeNotifier,
        private readonly FormApprovalChain $chain,
        private readonly FormModelService $models,
    ) {}

    /**
     * A revision delivered through a model with an approval chain is approved step by step: this decides the current step (for
     * whoever may) and only the last approval approves the revision.
     */
    public function approve(DocumentRevision $revision, Teacher $reviewer, ?string $result, EducationalCentre $centre): void
    {
        if ($this->chain->hasOpenChain($revision)
            && $this->chain->decide($revision, $reviewer, true, $this->normalise($result)) === FormApprovalChain::NEXT
        ) {
            $this->activityLogger->record('form_submission.step_approved', [
                'folder'   => $revision->getDocument()->getFolder()->getName(),
                'document' => $revision->getDocument()->getName(),
                'version'  => $revision->getVersion(),
            ], $centre);

            return;
        }

        $document = $revision->getDocument();
        $revision->approve($reviewer, $this->normalise($result));
        $document->setActiveRevision($revision);
        $this->models->refreshBlankPdf($revision);

        $this->finish($revision, 'document.revision_approve', DocumentReviewNotificationKind::Approved, $centre);
    }

    public function reject(DocumentRevision $revision, Teacher $reviewer, ?string $result, EducationalCentre $centre): void
    {
        if ($this->chain->hasOpenChain($revision)) {
            $this->chain->decide($revision, $reviewer, false, $this->normalise($result));
        }

        $document = $revision->getDocument();
        $revision->reject($reviewer, $this->normalise($result));
        if ($document->getActiveRevision() === $revision) {
            $document->setActiveRevision(null);
        }

        $this->finish($revision, 'document.revision_reject', DocumentReviewNotificationKind::Rejected, $centre);
    }

    private function finish(DocumentRevision $revision, string $actionType, DocumentReviewNotificationKind $kind, EducationalCentre $centre): void
    {
        $this->em->flush();

        $document = $revision->getDocument();
        $this->activityLogger->record($actionType, [
            'folder'   => $document->getFolder()->getName(),
            'document' => $document->getName(),
            'version'  => $revision->getVersion(),
        ], $centre);
        $this->outcomeNotifier->notifyOutcome($revision, $kind);
    }

    private function normalise(?string $result): ?string
    {
        $result = trim((string) $result);

        return $result !== '' ? $result : null;
    }
}
