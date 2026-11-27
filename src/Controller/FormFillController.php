<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\CurrentCentre;
use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\FormSubmission;
use App\Entity\FormUpdatePolicy;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Model\ActivityWindowBlock;
use App\Repository\ActivityRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityWindowChecker;
use App\Service\Form\FormDefinition;
use App\Service\Form\FormDeliveredRows;
use App\Service\Form\FormException;
use App\Service\Form\FormResolver;
use App\Service\Form\FormSlotAccess;
use App\Service\Form\FormSubmissionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
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
        private readonly \App\Service\Form\FormListOptions $lists,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\Form\FormAttachmentService $attachments,
        private readonly \App\Service\UploadLimits $uploadLimits,
        private readonly \App\Service\DocumentTreeAccessChecker $treeAccess,
        private readonly FormDeliveredRows $deliveredRows,
        private readonly \App\Service\ActivityPendingOwnersFinder $pendingOwners,
        private readonly \App\Service\ActivityReminderSender $reminderSender,
        private readonly \Symfony\Component\RateLimiter\RateLimiterFactoryInterface $activityReminderLimiter,
    ) {}

    /**
     * Uploads one file for a field «Adjuntos» of the draft: multipart `file` + `field` (the field's key). Answers the value to add to
     * the field ({id, name, size, mime, url}); the page adds it and saves, as with any other value.
     */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/adjuntos', name: 'app_form_attach', methods: ['POST'])]
    public function attach(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): JsonResponse
    {
        [$activity, $slot, $teacher] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        if (!$this->isCsrfTokenValid('form_fill_' . $submission->getId()->toRfc4122(), $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }
        if ($this->windows->for($activity, $teacher, $slot->deadlineItem($activity))->blocked) {
            return new JsonResponse(['error' => 'window'], Response::HTTP_FORBIDDEN);
        }
        $file = $request->files->get('file');
        if ($this->uploadLimits->isRequestTooLarge($request) || !$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
            $max = \App\Service\Form\FormAttachments::size(min(FormDefinition::ATTACHMENT_MAX_BYTES, $this->uploadLimits->maxFileBytes()));

            return new JsonResponse(['error' => 'upload', 'message' => $this->translator->trans('fill.attachment.too_large', ['%max%' => $max], 'forms')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $attachment = $this->attachments->attach($submission, $this->service->definitionOf($submission), $request->request->getString('field'), $file, $teacher);
        } catch (FormException $e) {
            $params = FormDefinition::map(($e->problems[0] ?? [])['params'] ?? null) ?? [];

            return new JsonResponse(['error' => $e->getMessage(), 'message' => $this->translator->trans('fill.' . $e->getMessage(), $params, 'forms')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($attachment->toValue() + ['url' => $this->generateUrl('app_form_attachment', ['id' => $attachment->getId()->toRfc4122()])]);
    }

    #[Route('/actividades/{activityId}/formulario/{slotKey}', name: 'app_form_fill', methods: ['GET'])]
    public function fill(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot, $teacher] = $this->context($activityId, $slotKey, $centre);
        $window = $this->windows->for($activity, $teacher, $slot->deadlineItem($activity));

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

        $force          = $this->service->updates()->pendingUpdate($submission);
        $view['update'] = $force === null ? null : [
            'version'   => $force->getRevision()->getVersion(),
            'mandatory' => $force->getUpdatePolicy() === FormUpdatePolicy::Mandatory,
            'required'  => $this->service->updates()->isUpdateRequired($submission),
            'deadline'  => $force->getUpdateDeadline(),
            'lost'      => $this->service->updates()->lostOnUpdate($submission),
        ];
        $view['prefill'] = $submission->hasDraft() ? $submission->getPrefilledFrom() : null;

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
            // The tables of deliveries are read on opening: stored only by whoever holds the draft (a save by somebody else would make
            // the holder's next autosave stale), shown as read to anybody.
            $data = $this->service->refreshDelivered($submission, !$readOnly)['data'];
            // Computed once: it normalizes and validates the whole draft.
            $pending = $readOnly ? ['problems' => [], 'warnings' => []] : $this->problemLists($submission);
            $definition = $this->service->definitionOf($submission, $data);
            // Delivering found the deliveries had changed (deliver()): what changed, to say it and blink it once.
            $changed = null;
            $session = $request->getSession();
            foreach ($session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get('form_delivered_changed') : [] as $details) {
                $changed = is_array($details) ? ['count' => FormDefinition::int($details['count'] ?? 0), 'cells' => FormDefinition::listOf($details['cells'] ?? [])] : null;
            }

            $view += [
                'deliveredChanged' => $changed,
                'editor'           => [
                    'changed'      => $changed['cells'] ?? [],
                    'definition'   => $definition,
                    'data'         => $data,
                    'refreshUrl'   => $this->generateUrl('app_form_refresh_delivered', $base),
                    'remindUrl'    => $this->generateUrl('app_form_remind_delivery', $base),
                    'delivered'    => $this->deliveredLinks($activity, $definition, $teacher),
                    'version'      => $submission->getDraftVersion(),
                    'saveUrl'      => $this->generateUrl('app_form_save', $base),
                    'heartbeatUrl' => $this->generateUrl('app_form_heartbeat', $base),
                    'historyUrl'   => $this->generateUrl('app_form_history', $base),
                    // The files of the fields «Adjuntos»: where to upload one, and where each is downloaded (__ID__ is its id).
                    'attachUrl'    => $this->generateUrl('app_form_attach', $base),
                    'fileUrl'      => $this->generateUrl('app_form_attachment', ['id' => '__ID__']),
                    'fileMaxBytes' => min(FormDefinition::ATTACHMENT_MAX_BYTES, $this->uploadLimits->maxFileBytes()),
                    'csrf'         => $this->csrf->getToken($token)->getValue(),
                    'readOnly'     => $readOnly,
                    'problems'     => $pending['problems'],
                    'context'      =>$this->service->contextFor($activity, $slot, $submission->getCycleYear()),
                    'id'           => $id,
                ],
                'lockedBy'    => $lockedByOther ? $holder : null,
                'lockedUntil' => $submission->getLockedUntil(),
                'canEdit'     => !$readOnly,
                'problems'    => $pending['problems'],
                'warnings'    => $pending['warnings'],
            ];
        } elseif ($last !== null) {
            $definition = $this->lists->delivered($last);
            $view      += [
                'sections'      => $this->resolver->resolve($definition, $last->getData(), $this->service->contextFor($activity, $slot, $submission->getCycleYear())),
                'lastRevision'  => $last->getRevision(),
                // The deliveries its tables read have newer data: a new revision reads them again.
                'outdated'      => $this->deliveredRows->outdated($last),
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
        if ($this->windows->for($activity, $teacher, $slot->deadlineItem($activity))->blocked) {
            return new JsonResponse(['error' => 'window'], Response::HTTP_FORBIDDEN);
        }

        if (strlen($request->getContent()) > FormDefinition::MAX_REQUEST_BYTES) {
            return new JsonResponse(['error' => 'too_large', 'message' => $this->translator->trans('fill.error.too_large', [], 'forms')], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
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

    /**
     * «Recordar» on a row of a table of deliveries not delivered yet: the e-mail «Recordar a pendientes» of the SOURCE activity, only to
     * whoever owes that delivery (the row's slot), from whoever fills this document in. The row must be one the draft read (its
     * `sources`), and not delivered; once an hour per delivery. JSON { table, row } → { sent } | 409 { error, message }.
     */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/recordar-entrega', name: 'app_form_remind_delivery', methods: ['POST'])]
    public function remindDelivery(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): JsonResponse
    {
        [$activity, $slot, $teacher] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        if (!$this->isCsrfTokenValid('form_fill_' . $submission->getId()->toRfc4122(), $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }
        $payload = json_decode($request->getContent(), true);
        $table   = is_array($payload) ? FormDefinition::str($payload['table'] ?? '') : '';
        $rowKey  = is_array($payload) ? FormDefinition::str($payload['row'] ?? '') : '';
        $source  = $activity->getDeliverySource($table);
        $read    = FormDefinition::map((FormDefinition::map(($submission->getDraftData() ?? [])['sources'] ?? null) ?? [])[$table] ?? null) ?? [];
        $row     = null;
        foreach (FormDefinition::listOf($read['rows'] ?? []) as $candidate) {
            $row = ($candidate['key'] ?? null) === $rowKey ? $candidate : $row;
        }
        if ($source === null || $row === null || ($read['activity'] ?? null) !== $source->getId()->toRfc4122() || ($row['status'] ?? '') === 'delivered') {
            return new JsonResponse(['error' => 'row', 'message' => $this->translator->trans('fill.remind.error.row', [], 'forms')], Response::HTTP_CONFLICT);
        }

        // Who owes that delivery and still has it to do, with an address: only that row of each one's obligations.
        $owed    = FormDefinition::str($row['slot'] ?? '');
        $pending = [];
        foreach ($this->pendingOwners->find($source) as ['teacher' => $owner, 'items' => $items]) {
            $items = array_values(array_filter($items, static fn ($item): bool => $item->slotKey === $owed));
            if ($items !== [] && $owner->getEmail() !== null) {
                $pending[] = ['teacher' => $owner, 'items' => $items];
            }
        }
        if ($pending === []) {
            return new JsonResponse(['error' => 'nobody', 'message' => $this->translator->trans('fill.remind.error.nobody', [], 'forms')], Response::HTTP_CONFLICT);
        }
        if (!$this->activityReminderLimiter->create($source->getId()->toRfc4122() . '|' . $owed)->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_soon', 'message' => $this->translator->trans('fill.remind.error.too_soon', [], 'forms')], Response::HTTP_CONFLICT);
        }
        $sent = $this->reminderSender->send($source, $pending, $teacher, '', $centre, 'activity.remind_delivery');

        return new JsonResponse(['sent' => $sent, 'message' => $this->translator->trans('fill.remind.sent', ['%count%' => $sent], 'forms')]);
    }

    /**
     * «Actualizar» of a table of deliveries: reads the deliveries again and stores the draft (same access, token, lock and version as
     * saving). JSON { version } → { version, tables, sources, changed: {count, cells}, problems, warnings }.
     */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/actualizar-entregas', name: 'app_form_refresh_delivered', methods: ['POST'])]
    public function refreshDelivered(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): JsonResponse
    {
        [$activity, $slot, $teacher] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        if (!$this->isCsrfTokenValid('form_fill_' . $submission->getId()->toRfc4122(), $request->headers->get('X-CSRF-Token', ''))) {
            return new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }
        if ($this->windows->for($activity, $teacher, $slot->deadlineItem($activity))->blocked) {
            return new JsonResponse(['error' => 'window'], Response::HTTP_FORBIDDEN);
        }
        $payload = json_decode($request->getContent(), true);
        $version = is_array($payload) ? FormDefinition::int($payload['version'] ?? 0) : 0;

        try {
            if (!$submission->hasDraft()) {
                throw new FormException('error.no_draft');
            }
            $this->service->acquireLock($submission, $teacher);
            if ($version !== $submission->getDraftVersion()) {
                throw new FormException('error.stale');
            }
            $result = $this->service->refreshDelivered($submission, true);
        } catch (FormException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'message' => $this->translator->trans('fill.' . $e->getMessage(), [], 'forms'), 'version' => $submission->getDraftVersion()], Response::HTTP_CONFLICT);
        }
        $this->em->flush(); // the renewed lock, when nothing else changed

        $tables     = [];
        $rows       = [];
        $definition = $this->service->definitionOf($submission, $result['data']);
        foreach (FormDeliveredRows::tables($definition) as $block) {
            $key          = FormDefinition::str($block['key'] ?? '');
            $tables[$key] = FormDefinition::listOf((FormDefinition::map($result['data']['tables'] ?? null) ?? [])[$key] ?? []);
            $rows[$key]   = FormDefinition::listOf($block['rows'] ?? []);
        }

        return new JsonResponse([
            'version' => $submission->getDraftVersion(),
            'tables'  => $tables,
            'sources' => $result['data']['sources'] ?? new \stdClass(),
            'rows'    => $rows,
            'changed' => $result['changed'],
        ] + $this->problemLists($submission));
    }

    /**
     * For each table of deliveries: whether whoever fills it may open the deliveries it lists (they see the activity's folder), and
     * the address of the page of a delivered revision (__ID__ is its id). Values are shown anyway: the model decides that.
     *
     * @param array<string, mixed> $definition
     *
     * @return array{links: array<string, bool>, activities: array<string, string>, revisionUrl: string}
     */
    private function deliveredLinks(Activity $activity, array $definition, Teacher $teacher): array
    {
        $links = $activities = [];
        foreach (FormDeliveredRows::tables($definition) as $block) {
            $key    = FormDefinition::str($block['key'] ?? '');
            $source = $activity->getDeliverySource($key);
            $folder = $source?->getFolder();
            $links[$key] = $folder !== null && $this->treeAccess->canViewFolder($teacher, $folder);
            if ($source !== null) {
                $activities[$key] = $this->generateUrl('app_activities', ['category' => $source->getCategory()->getId()->toRfc4122(), 'activity' => $source->getId()->toRfc4122()]);
            }
        }

        return ['links' => $links, 'activities' => $activities, 'revisionUrl' => $this->generateUrl('app_form_revision_view', ['revisionId' => '__ID__'])];
    }

    /** The draft as a document, the way it will read once delivered (nothing is delivered or saved by looking at it). */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/previsualizar', name: 'app_form_preview', methods: ['GET'])]
    public function preview(string $activityId, string $slotKey, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot] = $this->context($activityId, $slotKey, $centre);
        $submission = $this->service->open($activity, $slot);
        if (!$submission->hasDraft()) {
            return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
        }
        ['definition' => $definition, 'data' => $data] = $this->service->previewOf($submission);

        return $this->render('form/preview.html.twig', [
            'centre'   => $centre,
            'title'    => $slot->displayName,
            'code'     => $submission->getModelRevision()->getCode(),
            'sections' => $this->resolver->resolve($definition, $data, $this->service->contextFor($activity, $slot, $submission->getCycleYear())),
            'backUrl'  => $this->generateUrl('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]),
            'blank'    => false,
            'badge'    => $this->translator->trans('view.draft_badge', [], 'forms'),
        ]);
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

        $window = $this->windows->for($activity, $teacher, $slot->deadlineItem($activity));
        if ($window->blocked) {
            $this->addFlash('error', $this->translator->trans($window->reason === ActivityWindowBlock::BeforeStart ? 'fill.error.before_start' : 'fill.error.after_end', [], 'forms'));

            return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
        }

        try {
            $this->service->deliver($submission, $slot, $teacher);
        } catch (FormException $e) {
            if ($e->getMessage() === 'error.delivered_rows_changed') {
                // Not an error: the draft already has what is new; the page says how many rows and blinks the cells.
                $this->addFlash('form_delivered_changed', $e->details);

                return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
            }
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

    /** Puts the draft (or, with none, a new draft of the delivered document) on the revision of the model in force. */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/actualizar', name: 'app_form_update', methods: ['POST'])]
    public function update(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        $this->checkToken($request, $submission->getId()->toRfc4122(), 'update');
        if ($this->service->updates()->update($submission)) {
            $this->addFlash('success', $this->translator->trans('fill.flash.updated', [], 'forms'));
        }

        return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
    }

    /** Copies again what the draft is pre-filled from, replacing what was typed. */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/reprerrellenar', name: 'app_form_prefill', methods: ['POST'])]
    public function prefill(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        $this->checkToken($request, $submission->getId()->toRfc4122(), 'prefill');
        $this->service->prefillAgain($submission);

        return $this->redirectToRoute('app_form_fill', ['activityId' => $activityId, 'slotKey' => $slotKey]);
    }

    /** Throws what was pre-filled away and starts a blank draft. */
    #[Route('/actividades/{activityId}/formulario/{slotKey}/desde-cero', name: 'app_form_scratch', methods: ['POST'])]
    public function scratch(string $activityId, string $slotKey, Request $request, #[CurrentCentre] EducationalCentre $centre): Response
    {
        [$activity, $slot] = $this->context($activityId, $slotKey, $centre, true);
        $submission = $this->service->open($activity, $slot);
        $this->checkToken($request, $submission->getId()->toRfc4122(), 'scratch');
        $this->service->startFromScratch($submission);

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
     * Each one says where it is (the block, the copy, and the row and column of a table cell) so the screen can mark that field, and
     * `short` is what to write under the field itself (the full `text` names the field, which is redundant there).
     *
     * @return array{problems: list<array{block: string, path: string, row: ?string, column: ?string, code: string, text: string, short: string}>, warnings: list<array{block: string, path: string, row: ?string, column: ?string, code: string, text: string, short: string}>}
     */
    private function problemLists(FormSubmission $submission): array
    {
        $lists = ['problems' => [], 'warnings' => []];
        foreach ($this->service->problems($submission) as $p) {
            // A rule carries its own text, written by whoever made the model.
            $text   = $p['code'] === 'rule' ? $p['params']['%message%'] : $this->translator->trans('fill.problem.' . $p['code'], $p['params'], 'forms');
            $short  = in_array($p['code'], ['required', 'number_min', 'number_max', 'number_integer', 'number_decimals', 'option_gone'], true) ? $this->translator->trans('fill.error.' . $p['code'], $p['params'], 'forms') : $text;
            $lists[$p['severity'] === 'block' ? 'problems' : 'warnings'][] = [
                'block' => $p['block'], 'path' => $p['path'], 'row' => $p['row'] ?? null, 'column' => $p['column'] ?? null,
                'code' => $p['code'], 'text' => $text, 'short' => $short,
            ];
        }

        return $lists;
    }
}
