<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityWindowBlock;
use App\Repository\ActivityRepository;
use App\Repository\DocumentRevisionRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Security\Voter\FolderVoter;
use App\Service\ActivityLogger;
use App\Service\ActivityPendingOwnersFinder;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\ActivityWindowChecker;
use App\Service\DocumentCreationService;
use App\Service\DocumentRevisionReviewer;
use App\Service\DocumentTreeAccessChecker;
use App\Service\NotificationMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * "Actividades": the index page (mounts ActivityBrowserComponent/Admin:ActivityCategoryTreeComponent,
 * same Ver/Editar categorías split as DocumentTreeController), the upload route, and the two
 * actions on the activity as a whole: reviewing several submissions at once and reminding whoever
 * is still pending. Everything that happens to one submission's Document once it exists —
 * new revision, download, approve, reject — reuses FolderController's own routes unchanged (see
 * its class docblock): a submission IS a Document in the activity's folder, not a separate kind of
 * thing, so there's nothing document-specific to duplicate here beyond creating the first one with
 * a server-computed name instead of one typed by the uploader.
 */
#[Route('/actividades')]
class ActivityController extends AbstractController
{
    use TranslatorTrait;
    use UploadSizeGuardTrait;

    private const MAX_SUBMISSION_SIZE = 20 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ActivityRepository $activities,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ActivitySubmissionSlotBuilder $slotBuilder,
        private readonly DocumentCreationService $documentCreation,
        private readonly ActivityLogger $activityLogger,
        private readonly ActivityWindowChecker $windowChecker,
        private readonly DocumentRevisionRepository $revisions,
        private readonly DocumentRevisionReviewer $reviewer,
        private readonly ActivityPendingOwnersFinder $pendingOwners,
        private readonly NotificationMailer $mailer,
        private readonly Environment $twig,
        private readonly RateLimiterFactoryInterface $activityReminderLimiter,
    ) {}

    /**
     * Approves or rejects, in one go, the pending submissions ticked in the activity's review
     * box (_activity_bulk_review.html.twig), with one shared comment. Only revisions still pending
     * in the activity's own folder are touched — any other id is ignored, never trusted.
     */
    #[Route('/{activityId}/revisar', name: 'app_activity_bulk_review', methods: ['POST'])]
    public function bulkReview(string $activityId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $activity = $this->requireActivity($activityId, $centre);
        $teacher  = $this->requireTeacher();
        $folder   = $activity->getFolder();
        if ($folder === null) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(FolderVoter::REVIEW, $folder);

        if (!$this->isCsrfTokenValid('activity_bulk_review_' . $activityId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $decision = $request->request->getString('decision');
        if (!\in_array($decision, ['approve', 'reject'], true)) {
            throw $this->createNotFoundException();
        }

        $selected = array_filter($request->request->all('revisions'), 'is_string');
        $result   = $request->request->getString('reviewResult');
        $count    = 0;
        foreach ($this->revisions->findPendingReviewByFolder($folder) as $revision) {
            if (!\in_array($revision->getId()->toRfc4122(), $selected, true)) {
                continue;
            }
            $decision === 'approve'
                ? $this->reviewer->approve($revision, $teacher, $result, $centre)
                : $this->reviewer->reject($revision, $teacher, $result, $centre);
            ++$count;
        }

        $count === 0
            ? $this->addFlash('error', $this->t('bulk_review.flash.none'))
            : $this->addFlash('success', $this->translator->trans('bulk_review.flash.' . $decision, ['%count%' => $count], 'activity_content'));

        return $this->redirectToActivity($activity);
    }

    /**
     * "Recordar a pendientes": one email to each teacher who still has something to do for the
     * activity (ActivityPendingOwnersFinder), listing just their own pending obligations in it. For
     * the centre's responsibility managers and whoever manages or reviews the activity's folder; at
     * most once an hour per activity (rate limiter "activity_reminder").
     */
    #[Route('/{activityId}/recordar-pendientes', name: 'app_activity_remind_pending', methods: ['POST'])]
    public function remindPending(string $activityId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $activity = $this->requireActivity($activityId, $centre);
        $sender   = $this->requireTeacher();
        if (!$this->canRemind($sender, $activity, $centre)) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('activity_remind_pending_' . $activityId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Nobody to write to without an address (e.g. an account created without one).
        $pending = array_values(array_filter($this->pendingOwners->find($activity), static fn (array $p): bool => $p['teacher']->getEmail() !== null));
        if ($pending === []) {
            $this->addFlash('success', $this->t('remind_pending.flash.nobody'));

            return $this->redirectToActivity($activity);
        }

        if (!$this->activityReminderLimiter->create($activityId)->consume()->isAccepted()) {
            $this->addFlash('error', $this->t('remind_pending.flash.too_soon'));

            return $this->redirectToActivity($activity);
        }

        $url = $this->generateUrl('app_activities', [
            'category' => $activity->getCategory()->getId()->toRfc4122(),
            'activity' => $activity->getId()->toRfc4122(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($pending as ['teacher' => $recipient, 'items' => $items]) {
            $this->mailer->send(
                $recipient,
                $centre,
                'activity_manual_reminder',
                $this->translator->trans('emails.activity_manual_reminder.subject', ['%title%' => $activity->getTitle()], 'emails'),
                $this->translator->trans('emails.activity_manual_reminder.heading', ['%title%' => $activity->getTitle()], 'emails'),
                $this->twig->render('email/_activity_manual_reminder_body.html.twig', ['sender' => $sender, 'activity' => $activity, 'items' => $items]),
                $url,
                $this->translator->trans('emails.activity_manual_reminder.cta', [], 'emails'),
            );
        }

        $this->activityLogger->record('activity.remind_pending', ['activity' => $activity->getTitle(), 'recipients' => \count($pending)], $centre);
        $this->addFlash('success', $this->translator->trans('remind_pending.flash.sent', ['%count%' => \count($pending)], 'activity_content'));

        return $this->redirectToActivity($activity);
    }

    /** Same rule as ActivityBrowserComponent::canRemindPending(), which shows the button. */
    private function canRemind(Teacher $teacher, Activity $activity, EducationalCentre $centre): bool
    {
        $folder = $activity->getFolder();

        return $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre)
            || ($folder !== null && ($this->access->canManageFolder($teacher, $folder) || $this->access->canReviewFolder($teacher, $folder)));
    }

    #[Route('', name: 'app_activities')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $canEdit      = $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
        $requestedTab = $request->query->getString('tab');
        // A link carrying category/activity (the dashboard widget, the calendar, the notification
        // bell, search results…) always means "open this specific activity in the tree", so it
        // forces the "view" tab even without an explicit ?tab= — only a bare visit to /actividades
        // defaults to "mine", the personal at-a-glance list most teachers actually want first.
        $hasDeepLink  = $request->query->get('category') !== null || $request->query->get('activity') !== null;
        $tab          = match (true) {
            $canEdit && $requestedTab === 'edit' => 'edit',
            $requestedTab === 'view' => 'view',
            $requestedTab === 'mine' => 'mine',
            $hasDeepLink => 'view',
            default => 'mine',
        };

        return $this->render('activity/index.html.twig', [
            'centre'  => $centre,
            'canEdit' => $canEdit,
            'tab'     => $tab,
        ]);
    }

    /**
     * Uploads one or more submissions in a single step, one per dropzone row that had a file
     * staged when "Enviar entregas" was pressed (see assets/controllers/activity_submissions_controller.js).
     * Each file names its target row via a slot key (ActivitySubmissionSlot::key()); the expected
     * slots are recomputed server-side and every key is revalidated against them — never trusted
     * from the request — before anything is created.
     */
    #[Route('/{activityId}/entregas/subir', name: 'app_activity_submission_upload', methods: ['POST'])]
    public function uploadSubmissions(string $activityId, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $activity = $this->requireActivity($activityId, $centre);
        $teacher  = $this->requireTeacher();
        $folder   = $activity->getFolder();
        if ($folder === null) {
            throw $this->createNotFoundException();
        }

        if ($this->isUploadTooLarge($request)) {
            $this->addFlash('error', $this->t('upload.error.too_large'));

            return $this->redirectToActivity($activity);
        }

        if (!$this->isCsrfTokenValid('activity_submission_upload_' . $activityId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $window = $this->windowChecker->for($activity, $teacher);
        if ($window->blocked) {
            $this->addFlash('error', $this->t($window->reason === ActivityWindowBlock::BeforeStart
                ? 'submission.error.before_start'
                : 'submission.error.after_end'));

            return $this->redirectToActivity($activity);
        }

        // Both files[N] and items[N][slotKey] use the SAME explicit N (see
        // _activity_submission_row.html.twig) rather than files[]/items[] — a plain files[]
        // gets renumbered by PHP to only the parts actually present in the request body, and
        // some browsers (mobile Safari included) omit untouched <input type="file"> entirely,
        // which would silently desync file N from the row it was really staged in.
        $uploadedFiles = $request->files->all('files');
        if ($uploadedFiles === []) {
            $this->addFlash('error', $this->t('upload.error.no_file'));

            return $this->redirectToActivity($activity);
        }

        $slotsByKey = [];
        foreach ($this->slotBuilder->buildSlots($activity) as $slot) {
            $slotsByKey[$slot->key()] = $slot;
        }

        $canManage = $this->access->canManageFolder($teacher, $folder);
        /** @var array<int, array{slotKey?: string}> $items */
        $items = $request->request->all('items');

        $created = 0;
        foreach ($uploadedFiles as $i => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }
            if ($file->getSize() > self::MAX_SUBMISSION_SIZE) {
                $this->addFlash('error', $this->translator->trans('upload.error.file_too_large', [
                    '%filename%' => $file->getClientOriginalName(),
                ], 'activity_content'));

                return $this->redirectToActivity($activity);
            }
            if (!$folder->acceptsFile($file->getClientOriginalName(), $file->getMimeType() ?? '')) {
                $this->addFlash('error', $this->translator->trans('upload.error.file_format_not_allowed', [
                    '%filename%' => $file->getClientOriginalName(),
                ], 'activity_content'));

                return $this->redirectToActivity($activity);
            }

            $slotKey = (string) ($items[$i]['slotKey'] ?? '');
            $slot    = $slotsByKey[$slotKey] ?? null;
            if ($slot === null) {
                continue;
            }

            $canSubmit = $canManage
                || ($slot->teacher !== null
                    ? $slot->teacher === $teacher
                    : $this->access->holdsProfile($teacher, $slot->profile, $slot->listItem));
            if (!$canSubmit) {
                continue;
            }

            if ($this->slotBuilder->resolveSlot($activity, $slot) !== null) {
                // Already covered — the UI shouldn't offer a dropzone for a filled slot, but
                // revalidate defensively instead of creating a duplicate.
                continue;
            }

            // resolveSlot() looks up an Individual-scope slot's document by its assigned
            // teacher ($slot->teacher), not by whoever is actually submitting — so a manager
            // uploading on someone else's behalf must still record that assigned teacher as the
            // uploader, or the document it creates would never match this slot again (it'd
            // permanently look unsubmitted here while sitting, orphaned, in the folder). For
            // ByProfile-scope slots $slot->teacher is always null, so this is just $teacher.
            $this->documentCreation->createWithFirstRevision($folder, $slot->displayName, $slot->profile, $slot->listItem, $file, $slot->teacher ?? $teacher);
            ++$created;
        }

        if ($created === 0) {
            $this->addFlash('error', $this->t('upload.error.no_file'));

            return $this->redirectToActivity($activity);
        }

        $this->em->flush();
        $logData = ['activity' => $activity->getTitle(), 'count' => $created];
        if ($window->late) {
            $logData['late'] = true;
        }
        $this->activityLogger->record('activity.submission_upload', $logData, $centre);
        $this->addFlash('success', $this->translator->trans('submission.flash.uploaded', ['%count%' => $created], 'activity_content'));

        return $this->redirectToActivity($activity);
    }

    private function requireActivity(string $activityId, EducationalCentre $centre): Activity
    {
        $activity = $this->activities->findById($activityId);
        if ($activity === null || $activity->getCategory()->getEducationalCentre()->getId()->toRfc4122() !== $centre->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return $activity;
    }

    private function requireTeacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function redirectToActivity(Activity $activity): Response
    {
        return $this->redirectToRoute('app_activities', [
            'category' => $activity->getCategory()->getId()->toRfc4122(),
            'activity' => $activity->getId()->toRfc4122(),
        ]);
    }

    private function translationDomain(): string
    {
        return 'activity_content';
    }
}
