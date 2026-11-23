<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\EducationalCentre;
use App\Entity\FormApprovalStepState;
use App\Entity\FormComment;
use App\Entity\FormSubmissionRevision;
use App\Entity\Teacher;
use App\Repository\FormCommentRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Security\Voter\FolderVoter;
use App\Service\DocumentRevisionReviewer;
use App\Service\DocumentTreeAccessChecker;
use App\Service\Form\FormApprovalChain;
use App\Service\Form\FormDiff;
use App\Service\Form\FormResolver;
use App\Service\Form\FormSlotAccess;
use App\Service\Form\FormSubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The page of a delivered filled document: the document as it was delivered, the steps of its approval chain and what each decided,
 * the comments reviewers left on its parts, and what changed since the previous revision. Whoever can see the folder reads it; who
 * decides a step, comments or replies is decided here (a step: whoever holds its profile or manages the folder; a comment: the same,
 * the folder's reviewers, and the people who deliver the slot).
 */
class FormReviewController extends AbstractController
{
    public function __construct(
        private readonly FormSubmissionRevisionRepository $revisions,
        private readonly FormCommentRepository $comments,
        private readonly FormApprovalChain $chain,
        private readonly DocumentRevisionReviewer $reviewer,
        private readonly FormResolver $resolver,
        private readonly \App\Service\Form\FormOfficeWriter $office,
        private readonly \App\Service\Form\FormLayoutResolver $layouts,
        private readonly \App\Service\Form\FormListOptions $lists,
        private readonly \App\Service\Form\FormIndicatorCalculator $indicators,
        private readonly FormDiff $diff,
        private readonly FormSubmissionService $service,
        private readonly FormSlotAccess $slotAccess,
        private readonly DocumentTreeAccessChecker $access,
        private readonly EntityManagerInterface $em,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/formularios/revision/{revisionId}', name: 'app_form_revision_view', methods: ['GET'])]
    public function view(string $revisionId, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $filled   = $this->requireRevision($revisionId, $centre);
        $revision = $filled->getRevision();
        $folder   = $revision->getDocument()->getFolder();
        $teacher  = $this->teacher();
        $activity = $folder->getActivity();
        $token    = $this->csrf->getToken('form_review_' . $revisionId)->getValue();

        $steps   = $this->chain->stepsOf($revision);
        $current = $this->chain->currentStep($revision);
        $canDecide = $current !== null
            ? $this->chain->canDecide($teacher, $current)
            : ($revision->isPendingReview() && $this->isGranted(FolderVoter::REVIEW, $folder));

        $grouped = [];
        foreach ($this->comments->findByRevision($filled) as $comment) {
            $grouped[$comment->getPath() . '|' . $comment->getAnchorKey()][] = [
                'id'       => $comment->getId()->toRfc4122(),
                'author'   => $comment->getAuthor() === null ? '' : $comment->getAuthor()->getName()->getFirstName() . ' ' . $comment->getAuthor()->getName()->getLastName(),
                'body'     => $comment->getBody(),
                'at'       => $comment->getCreatedAt(),
                'resolved' => $comment->isResolved(),
            ];
        }

        $others = [];
        foreach ($this->revisions->findBySubmission($filled->getSubmission()) as $other) {
            if ($other->getId()->toRfc4122() !== $filled->getId()->toRfc4122()) {
                $others[] = $other->getRevision();
            }
        }

        $sections = $this->resolver->resolve($this->lists->delivered($filled), $filled->getData(), $this->context($filled));

        return $this->render('form/revision.html.twig', [
            'annexes'    => count(\App\Service\Form\FormAttachments::fromResolved($sections)),
            'centre'     => $centre,
            'title'      => $revision->getDocument()->getName(),
            'code'       => $filled->getModelRevision()->getCode(),
            'revision'   => $revision,
            'folder'     => $folder,
            'steps'      => $steps,
            'current'    => $current,
            'canDecide'  => $canDecide,
            'others'     => $others,
            'token'      => $token,
            'sections'   => $sections,
            'ctx'        => ['comments' => $grouped, 'canComment' => $this->canParticipate($teacher, $filled), 'token' => $token, 'postUrl' => $this->generateUrl('app_form_comment', ['revisionId' => $revisionId])],
            'backUrl'    => $activity === null ? $this->generateUrl('app_document_tree') : $this->generateUrl('app_activities', ['category' => $activity->getCategory()->getId()->toRfc4122(), 'activity' => $activity->getId()->toRfc4122()]),
            'documentId' => $revision->getDocument()->getId()->toRfc4122(),
        ]);
    }

    /** The delivered document as a Word or OpenDocument file, to keep working on it outside the application. */
    #[Route('/formularios/revision/{revisionId}/exportar.{_format}', name: 'app_form_revision_export', requirements: ['_format' => 'docx|odt'], methods: ['GET'])]
    public function export(string $revisionId, string $_format, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $filled     = $this->requireRevision($revisionId, $centre);
        $title      = $filled->getRevision()->getDocument()->getName();
        $modelRev   = $filled->getModelRevision();
        $modelTitle = $modelRev->getModel()->getDocument()->getName();
        // The layout is the one the revision of the model was sent with; the header says the model's title, the body this document's.
        $layout = $this->layouts->resolve(
            $modelRev->getDefinition(),
            $centre,
            $this->layouts->values($centre, $modelTitle, $modelRev->getCode(), $modelRev->getRevision()->getVersion(), $modelRev->getRevision()->getRevisedAt()),
        );
        $body = $this->office->build(
            $_format,
            $title,
            $this->resolver->resolve($this->lists->delivered($filled), $filled->getData(), $this->context($filled)),
            $layout,
            $title !== $modelTitle ? $title : null,
        );

        $response = new Response($body);
        $response->headers->set('Content-Type', $_format === 'odt' ? 'application/vnd.oasis.opendocument.text' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . (preg_replace('/[^A-Za-z0-9._-]+/', '-', $title) ?: 'documento') . '.' . $_format . '"');

        return $response;
    }

    /** Approves or rejects: the current step of the chain, or the revision itself when it has no chain. */
    #[Route('/formularios/revision/{revisionId}/decidir', name: 'app_form_decide', methods: ['POST'])]
    public function decide(string $revisionId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $filled   = $this->requireRevision($revisionId, $centre);
        $revision = $filled->getRevision();
        $folder   = $revision->getDocument()->getFolder();
        $teacher  = $this->teacher();
        $this->checkToken($request, $revisionId);

        $current = $this->chain->currentStep($revision);
        $allowed = $current !== null ? $this->chain->canDecide($teacher, $current) : $this->isGranted(FolderVoter::REVIEW, $folder);
        if (!$allowed || !$revision->isPendingReview()) {
            throw $this->createAccessDeniedException();
        }

        $approve = $request->request->getString('decision') === 'approve';
        $note    = trim($request->request->getString('note'));
        if (!$approve && $note === '') {
            $this->addFlash('error', $this->translator->trans('review.error.note_required', [], 'forms'));

            return $this->redirectToRoute('app_form_revision_view', ['revisionId' => $revisionId]);
        }

        $approve
            ? $this->reviewer->approve($revision, $teacher, $note === '' ? null : $note, $centre)
            : $this->reviewer->reject($revision, $teacher, $note, $centre);
        // An approval changes what the indicators fed from this activity's documents say.
        if ($approve && $revision->isApproved() && $folder->getActivity() !== null) {
            $this->indicators->recalculateForActivity($folder->getActivity());
        }
        $this->addFlash('success', $this->translator->trans($approve ? 'review.flash.approved' : 'review.flash.rejected', [], 'forms'));

        return $this->redirectToRoute('app_form_revision_view', ['revisionId' => $revisionId]);
    }

    #[Route('/formularios/revision/{revisionId}/comentarios', name: 'app_form_comment', methods: ['POST'])]
    public function comment(string $revisionId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $filled  = $this->requireRevision($revisionId, $centre);
        $teacher = $this->teacher();
        $this->checkToken($request, $revisionId);
        if (!$this->canParticipate($teacher, $filled)) {
            throw $this->createAccessDeniedException();
        }

        $body   = trim($request->request->getString('body'));
        $anchor = $request->request->getString('anchor');
        if ($body === '' || $anchor === '' || strlen($anchor) > 40) {
            $this->addFlash('error', $this->translator->trans('review.error.comment_empty', [], 'forms'));
        } else {
            $this->em->persist(new FormComment($filled, $anchor, mb_substr($request->request->getString('path'), 0, 255), $teacher, mb_substr($body, 0, 2000)));
            $this->em->flush();
        }

        return $this->redirect($this->generateUrl('app_form_revision_view', ['revisionId' => $revisionId]) . '#blk-' . $request->request->getString('path') . '-' . $anchor);
    }

    #[Route('/formularios/revision/{revisionId}/comentarios/{commentId}/resolver', name: 'app_form_comment_resolve', methods: ['POST'])]
    public function resolve(string $revisionId, string $commentId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $filled  = $this->requireRevision($revisionId, $centre);
        $teacher = $this->teacher();
        $this->checkToken($request, $revisionId);
        if (!$this->canParticipate($teacher, $filled)) {
            throw $this->createAccessDeniedException();
        }
        $comment = $this->comments->findByIdAndRevision($commentId, $filled);
        if ($comment === null) {
            throw $this->createNotFoundException();
        }
        $comment->toggleResolved();
        $this->em->flush();

        return $this->redirectToRoute('app_form_revision_view', ['revisionId' => $revisionId]);
    }

    /** What changed between two revisions of the same document: the older to the newer. */
    #[Route('/formularios/revision/{revisionId}/comparar/{otherId}', name: 'app_form_compare', methods: ['GET'])]
    public function compare(string $revisionId, string $otherId, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $a = $this->requireRevision($revisionId, $centre);
        $b = $this->requireRevision($otherId, $centre);
        if ($a->getSubmission()->getId()->toRfc4122() !== $b->getSubmission()->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        // Always from the older to the newer, whichever way the link was followed.
        [$before, $after] = $a->getRevision()->getVersion() <= $b->getRevision()->getVersion() ? [$a, $b] : [$b, $a];

        return $this->render('form/compare.html.twig', [
            'centre'  => $centre,
            'title'   => $after->getRevision()->getDocument()->getName(),
            'before'  => $before->getRevision(),
            'after'   => $after->getRevision(),
            'changes' => $this->diff->compare(
                $this->lists->delivered($before),
                $before->getData(),
                $this->lists->delivered($after),
                $after->getData(),
                $this->context($after),
            ),
            'backUrl' => $this->generateUrl('app_form_revision_view', ['revisionId' => $after->getRevision()->getId()->toRfc4122()]),
        ]);
    }

    private function requireRevision(string $revisionId, EducationalCentre $centre): FormSubmissionRevision
    {
        $filled = $this->revisions->findByRevisionId($revisionId);
        if ($filled === null) {
            throw $this->createNotFoundException();
        }
        $folder = $filled->getRevision()->getDocument()->getFolder();
        if ($folder->getEducationalCentre()->getId()->toRfc4122() !== $centre->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(FolderVoter::VIEW, $folder);

        return $filled;
    }

    /**
     * Whoever may comment on a delivered document: the folder's reviewers and managers, the people of any step of its chain, and
     * the people who deliver the slot (to answer).
     */
    private function canParticipate(Teacher $teacher, FormSubmissionRevision $filled): bool
    {
        $folder = $filled->getRevision()->getDocument()->getFolder();
        if ($this->access->canReviewFolder($teacher, $folder)) {
            return true;
        }
        foreach ($this->chain->stepsOf($filled->getRevision()) as $step) {
            if ($step->getProfile() !== null && $this->access->holdsProfile($teacher, $step->getProfile(), null)) {
                return true;
            }
        }
        $submission = $filled->getSubmission();
        $slot       = $this->service->slotFor($submission->getActivity(), $submission->getSlotKey());

        return $slot !== null && $this->slotAccess->canFill($teacher, $submission->getActivity(), $slot);
    }

    /** @return array<string, string> */
    private function context(FormSubmissionRevision $filled): array
    {
        $submission = $filled->getSubmission();

        return $this->service->contextFor($submission->getActivity(), $this->service->slotFor($submission->getActivity(), $submission->getSlotKey()), $submission->getCycleYear());
    }

    private function checkToken(Request $request, string $revisionId): void
    {
        if (!$this->isCsrfTokenValid('form_review_' . $revisionId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
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
