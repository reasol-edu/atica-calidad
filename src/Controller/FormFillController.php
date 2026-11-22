<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\FormSubmission;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Model\ActivityWindowBlock;
use App\Repository\ActivityRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityWindowChecker;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormException;
use App\Service\Form\FormResolver;
use App\Service\Form\FormSlotAccess;
use App\Service\Form\FormSubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An activity delivered by filling a model in: the page of a slot (the form while there is a draft, the delivered document
 * otherwise), the autosave, delivering, starting a correction and the web view of a delivered revision. Who may fill is the rule
 * of uploading a file (FormSlotAccess); the activity's window (start/end dates) applies as it does to an upload.
 */
class FormFillController extends AbstractController
{
    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly FormSubmissionService $service,
        private readonly FormSlotAccess $slotAccess,
        private readonly ActivityWindowChecker $windows,
        private readonly FormResolver $resolver,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/actividades/{activityId}/formulario/{slotKey}', name: 'app_form_fill', methods: ['GET'])]
    public function fill(string $activityId, string $slotKey, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot, $teacher] = $this->context($activityId, $slotKey, $centre);
        $window = $this->windows->for($activity, $teacher, $slot->nameListItem);

        try {
            $submission = $this->service->open($activity, $slot);
        } catch (FormException $e) {
            $this->addFlash('error', $this->translator->trans('fill.' . $e->getMessage(), [], 'forms'));

            return $this->backToActivity($activity);
        }

        $last   = $this->service->latestDelivered($submission);
        $token  = 'form_fill_' . $submission->getId()->toRfc4122();
        $folder = $activity->getFolder();

        $view = [
            'centre'     => $centre,
            'activity'   => $activity,
            'slot'       => $slot,
            'submission' => $submission,
            'window'     => $window,
            'backUrl'    => $this->backUrl($activity),
            'title'      => $slot->displayName,
            'canEdit'    => !$window->blocked,
            'token'      => $token,
            'last'       => $last,
        ];

        if ($submission->hasDraft()) {
            $revision = $submission->getModelRevision();
            $id       = $submission->getId()->toRfc4122();
            $base     = ['activityId' => $activityId, 'slotKey' => $slotKey];

            // One person at a time: whoever opens a free draft takes it (and keeps it with the heartbeat); somebody else sees it read-only.
            $holder        = $this->service->lockHolder($submission);
            $lockedByOther = $holder !== null && !$holder->getId()->equals($teacher->getId());
            if (!$lockedByOther && !$window->blocked) {
                $this->service->takeOver($submission, $teacher);
            }
            $readOnly = $window->blocked || $lockedByOther;

            $view += [
                'editor' => [
                    'definition'   => $revision->getDefinition(),
                    'data'         => $submission->getDraftData(),
                    'version'      => $submission->getDraftVersion(),
                    'saveUrl'      => $this->generateUrl('app_form_save', $base),
                    'heartbeatUrl' => $this->generateUrl('app_form_heartbeat', $base),
                    'historyUrl'   => $this->generateUrl('app_form_history', $base),
                    'csrf'         => $this->csrf->getToken($token)->getValue(),
                    'readOnly'     => $readOnly,
                    'context'      => $this->service->contextFor($activity, $slot, $submission->getCycleYear()),
                    'id'           => $id,
                ],
                'lockedBy'    => $lockedByOther ? $holder : null,
                'lockedUntil' => $submission->getLockedUntil(),
                'canEdit'     => !$readOnly,
                'problems'    => $readOnly ? [] : $this->problemLists($submission)['problems'],
                'warnings'    => $readOnly ? [] : $this->problemLists($submission)['warnings'],
            ];
        } elseif ($last !== null) {
            $definition = $last->getModelRevision()->getDefinition();
            $view      += [
                'sections'      => $this->resolver->resolve($definition, $last->getData(), $this->service->contextFor($activity, $slot, $submission->getCycleYear())),
                'lastRevision'  => $last->getRevision(),
                'folderId'      => $folder?->getId()->toRfc4122(),
                'documentId'    => $submission->getDocument()?->getId()->toRfc4122(),
            ];
        }

        return $this->render('form/fill.html.twig', $view);
    }

    /** The autosave: JSON { version, data } → { version, problems }. */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/guardar', name: 'app_form_save', methods: ['POST'])]
    public function save(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): JsonResponse
    {
        [$activity, $slot, $teacher] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        if (!$this->isCsrfTokenValid('form_fill_' . $submission->getId()->toRfc4122(), $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }
        if ($this->windows->for($activity, $teacher, $slot->nameListItem)->blocked) {
            return new JsonResponse(['error' => 'window'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var array<string, mixed> $data */
            $data = $payload['data'];
            $this->service->save($submission, $data, FormDefinition::int($payload['version'] ?? 0), $teacher);
        } catch (FormException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'message' => $this->translator->trans('fill.' . $e->getMessage(), [], 'forms'), 'version' => $submission->getDraftVersion()], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['version' => $submission->getDraftVersion()] + $this->problemLists($submission));
    }

    /** Keeps the edit lock while the page is open and nobody is typing: 200 while it is the teacher's, 409 once somebody took it. */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/latido', name: 'app_form_heartbeat', methods: ['POST'])]
    public function heartbeat(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): JsonResponse
    {
        [$activity, $slot, $teacher] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        if (!$this->isCsrfTokenValid('form_fill_' . $submission->getId()->toRfc4122(), $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }
        try {
            $this->service->acquireLock($submission, $teacher);
            $this->em->flush();
        } catch (FormException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'message' => $this->translator->trans('fill.' . $e->getMessage(), [], 'forms')], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['ok' => true]);
    }

    /** "Take control": the editor the page shows read-only because somebody else holds the lock. */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/tomar', name: 'app_form_take', methods: ['POST'])]
    public function take(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot, $teacher] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        $this->checkToken($request, $submission->getId()->toRfc4122(), 'take');
        $this->service->takeOver($submission, $teacher);

        return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
    }

    /**
     * The history of one value: ?block=&path=&row=&col= → [{ at, by, old, new }], newest first. Values come as the JSON they were
     * stored in. Only for whoever can fill the slot (the history of a draft is the authors' business).
     */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/historial', name: 'app_form_history', methods: ['GET'])]
    public function history(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): JsonResponse
    {
        [$activity, $slot] = $this->context($activityId, $slotKey, $centre);
        $submission = $this->service->open($activity, $slot);

        $row    = $request->query->getString('row');
        $column = $request->query->getString('col');
        $changes = [];
        foreach ($this->service->historyOf($submission, $request->query->getString('block'), $request->query->getString('path'), $row === '' ? null : $row, $column === '' ? null : $column) as $change) {
            $author    = $change->getChangedBy();
            $changes[] = [
                'at'  => $change->getChangedAt()->format(\DATE_ATOM),
                'by'  => $author === null ? '' : $author->getName()->getFirstName() . ' ' . $author->getName()->getLastName(),
                'old' => $change->getOldValue() === null ? null : json_decode($change->getOldValue(), true),
                'new' => $change->getNewValue() === null ? null : json_decode($change->getNewValue(), true),
            ];
        }

        return new JsonResponse(['changes' => $changes]);
    }

    #[Route('/actividades/{activityId}/formulario/{slotKey}/entregar', name: 'app_form_deliver', methods: ['POST'])]
    public function deliver(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot, $teacher] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        $this->checkToken($request, $submission->getId()->toRfc4122(), 'deliver');

        $window = $this->windows->for($activity, $teacher, $slot->nameListItem);
        if ($window->blocked) {
            $this->addFlash('error', $this->translator->trans($window->reason === ActivityWindowBlock::BeforeStart ? 'fill.error.before_start' : 'fill.error.after_end', [], 'forms'));

            return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
        }

        try {
            $this->service->deliver($submission, $slot, $teacher);
        } catch (FormException $e) {
            $this->addFlash('error', $this->translator->trans('fill.' . $e->getMessage(), [], 'forms'));

            return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
        }

        $this->addFlash('success', $this->translator->trans('fill.flash.delivered', [], 'forms'));

        return $this->backToActivity($activity);
    }

    #[Route('/actividades/{activityId}/formulario/{slotKey}/corregir', name: 'app_form_correct', methods: ['POST'])]
    public function correct(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        $this->checkToken($request, $submission->getId()->toRfc4122(), 'correct');
        $this->service->startCorrection($submission);

        return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
    }

    #[Route('/actividades/{activityId}/formulario/{slotKey}/descartar', name: 'app_form_discard', methods: ['POST'])]
    public function discard(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        $this->checkToken($request, $submission->getId()->toRfc4122(), 'discard');
        $this->service->discardDraft($submission);

        return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
    }

    /**
     * @return array{0: Activity, 1: ActivitySubmissionSlot, 2: Teacher}
     */
    private function context(string $activityId, string $slotKey, EducationalCentre $centre, bool $writing = false): array
    {
        $activity = $this->activities->findById($activityId);
        if ($activity === null
            || $activity->getCategory()->getEducationalCentre()->getId()->toRfc4122() !== $centre->getId()->toRfc4122()
            || !$activity->isForm()
            || ($activity->isHidden() && !$this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $centre))
        ) {
            throw $this->createNotFoundException();
        }

        $teacher = $this->getUser();
        if (!$teacher instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        $slot = $this->service->slotFor($activity, $slotKey);
        if ($slot === null) {
            throw $this->createNotFoundException();
        }
        if (!$this->slotAccess->canFill($teacher, $activity, $slot)) {
            throw $this->createAccessDeniedException();
        }

        return [$activity, $slot, $teacher];
    }

    private function checkToken(Request $request, string $submissionId, string $action): void
    {
        if (!$this->isCsrfTokenValid('form_' . $action . '_' . $submissionId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function backUrl(Activity $activity): string
    {
        return $this->generateUrl('app_activities', [
            'category' => $activity->getCategory()->getId()->toRfc4122(),
            'activity' => $activity->getId()->toRfc4122(),
        ]);
    }

    private function backToActivity(Activity $activity): Response
    {
        return $this->redirect($this->backUrl($activity));
    }

    /**
     * What is wrong with a draft, told in words and split into what stops the delivery and what only advises (a rule of the model
     * that is marked as a warning).
     *
     * @return array{problems: list<array{block: string, path: string, text: string}>, warnings: list<array{block: string, path: string, text: string}>}
     */
    private function problemLists(FormSubmission $submission): array
    {
        $lists = ['problems' => [], 'warnings' => []];
        foreach ($this->service->problems($submission) as $p) {
            // A rule carries its own text, written by whoever made the model.
            $text   = $p['code'] === 'rule' ? $p['params']['%message%'] : $this->translator->trans('fill.problem.' . $p['code'], $p['params'], 'forms');
            $lists[$p['severity'] === 'block' ? 'problems' : 'warnings'][] = ['block' => $p['block'], 'path' => $p['path'], 'text' => $text];
        }

        return $lists;
    }
}
