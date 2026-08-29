<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Repository\ActivityRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\DocumentCreationService;
use App\Service\DocumentTreeAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Actividades": the index page (mounts ActivityBrowserComponent/Admin:ActivityCategoryTreeComponent,
 * same Ver/Editar categorías split as DocumentTreeController) plus the one genuinely new upload
 * route this feature needs. Everything that happens to a submission's Document once it exists —
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
    ) {}

    #[Route('', name: 'app_activities')]
    public function index(Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        $canEdit      = $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre);
        $requestedTab = $request->query->getString('tab');
        $tab          = $canEdit && $requestedTab === 'edit' ? 'edit' : 'view';

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

            $this->documentCreation->createWithFirstRevision($folder, $slot->displayName, $slot->profile, $slot->listItem, $file, $teacher);
            ++$created;
        }

        if ($created === 0) {
            $this->addFlash('error', $this->t('upload.error.no_file'));

            return $this->redirectToActivity($activity);
        }

        $this->em->flush();
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
