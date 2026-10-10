<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ReviewQueueItem;
use App\Repository\DocumentRevisionRepository;
use App\Security\Voter\FolderVoter;
use App\Service\DocumentRevisionReviewer;
use App\Service\RejectionReasonProvider;
use App\Service\ReviewQueueBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The review queue ("Cola de revisión"): the revisions waiting for the current teacher, most
 * pressing first (ReviewQueueBuilder), each approvable in one click or rejectable with a reason —
 * typed or picked from the usual ones — without leaving the list. Administration and quality
 * managers can switch to the whole centre's queue.
 */
#[AsLiveComponent]
class ReviewQueueComponent extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    /** 'mine' or 'all' (the latter only honoured for whoever may review anything). */
    #[LiveProp]
    public string $scope = 'mine';

    /** The revision whose rejection form is open ('' for none). */
    #[LiveProp]
    public string $rejectingId = '';

    #[LiveProp(writable: true)]
    public string $rejectReason = '';

    #[LiveProp]
    public string $rejectError = '';

    public function __construct(
        private readonly ReviewQueueBuilder $queue,
        private readonly DocumentRevisionRepository $revisions,
        private readonly DocumentRevisionReviewer $reviewer,
        private readonly TranslatorInterface $translator,
        private readonly RejectionReasonProvider $reasonProvider,
    ) {}

    /**
     * Opens on the centre's whole queue when whoever may see it has nothing of their own to review
     * but others do: "Las mías" saying "¡Todo revisado!" while the dashboard counts pending reviews
     * read as a contradiction.
     */
    public function mount(EducationalCentre $centre): void
    {
        $this->centre = $centre;

        if ($this->canSeeAll() && $this->queue->build($this->teacher(), $centre, false) === [] && $this->getOthersCount() > 0) {
            $this->scope = 'all';
        }
    }

    /** How many reviews are pending in the whole centre, for someone who may see them all and is looking at their own queue. */
    public function getOthersCount(): int
    {
        return $this->canSeeAll() ? \count($this->queue->build($this->teacher(), $this->centre, true)) : 0;
    }

    public function canSeeAll(): bool
    {
        return $this->queue->canSeeAll($this->teacher(), $this->centre);
    }

    /** @return list<ReviewQueueItem> */
    public function getItems(): array
    {
        return $this->queue->build($this->teacher(), $this->centre, $this->scope === 'all');
    }

    #[LiveAction]
    public function setScope(#[LiveArg] string $scope): void
    {
        $this->scope = $scope === 'all' && $this->canSeeAll() ? 'all' : 'mine';
        $this->closeRejection();
    }

    #[LiveAction]
    public function approve(#[LiveArg] string $id): void
    {
        $revision = $this->requireReviewable($id);
        $this->reviewer->approve($revision, $this->teacher(), null, $this->centre);
        $this->closeRejection();
        $this->flash('success', 'review.flash.approved');
    }

    #[LiveAction]
    public function startReject(#[LiveArg] string $id): void
    {
        $this->requireReviewable($id);
        $this->rejectingId  = $id;
        $this->rejectReason = '';
        $this->rejectError  = '';
    }

    #[LiveAction]
    public function cancelReject(): void
    {
        $this->closeRejection();
    }

    /**
     * The centre's ready-made rejection reasons (its own, or the standard set), picked with one click.
     *
     * @return list<string>
     */
    public function getReasons(): array
    {
        return $this->reasonProvider->forCentre($this->centre);
    }

    /** Fills the rejection comment with one of the ready-made reasons, by its place in the list (added after what's typed already). */
    #[LiveAction]
    public function useReason(#[LiveArg] int $index): void
    {
        $text = $this->getReasons()[$index] ?? null;
        if ($text === null) {
            return;
        }

        $this->rejectReason = trim($this->rejectReason) === '' ? $text : rtrim($this->rejectReason, " \n.") . '. ' . $text;
    }

    #[LiveAction]
    public function confirmReject(): void
    {
        $revision = $this->requireReviewable($this->rejectingId);

        // A rejection without a reason only leaves the uploader guessing.
        if (trim($this->rejectReason) === '') {
            $this->rejectError = $this->translator->trans('review.error.reason_required', [], 'dashboard');

            return;
        }

        $this->reviewer->reject($revision, $this->teacher(), $this->rejectReason, $this->centre);
        $this->closeRejection();
        $this->flash('success', 'review.flash.rejected');
    }

    private function closeRejection(): void
    {
        $this->rejectingId  = '';
        $this->rejectReason = '';
        $this->rejectError  = '';
    }

    /** The revision, if it is still waiting, belongs to this centre and the teacher may review its folder — the id is client-supplied. */
    private function requireReviewable(string $id): DocumentRevision
    {
        $revision = $id === '' ? null : $this->revisions->find($id);
        if ($revision === null
            || !$revision->isPendingReview()
            || $revision->getDocument()->getFolder()->getDocumentSection()->getEducationalCentre()->getId()->toRfc4122() !== $this->centre->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(FolderVoter::REVIEW, $revision->getDocument()->getFolder());

        return $revision;
    }

    private function flash(string $type, string $key): void
    {
        // Only this fragment re-renders, so the flash goes out as a browser event the layout shows.
        $this->dispatchBrowserEvent('flash:show', ['type' => $type, 'message' => $this->translator->trans($key, [], 'dashboard')]);
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
