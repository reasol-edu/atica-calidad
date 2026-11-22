<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Activity;
use App\Entity\DocumentRevision;
use App\Entity\FormModelRevision;
use App\Entity\FormSubmission;
use App\Entity\FormSubmissionRevision;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Repository\FormModelRevisionRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Service\ActivityLogger;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\DocumentCreationService;
use App\Service\DocumentReviewNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * An activity delivered by filling a model online: opens the draft of a slot, saves it as it is typed and delivers it — which is
 * the moment the Document (or its next revision) exists, with the PDF generated from the data. From then on it follows the
 * normal flow of any submission: review, approval, the tree, the follow-up of the activity.
 */
final class FormSubmissionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormSubmissionRepository $submissions,
        private readonly FormSubmissionRevisionRepository $submissionRevisions,
        private readonly FormModelRevisionRepository $modelRevisions,
        private readonly FormDataNormalizer $normalizer,
        private readonly FormDataValidator $validator,
        private readonly FormPdfBuilder $pdf,
        private readonly DocumentCreationService $documents,
        private readonly DocumentReviewNotifier $notifier,
        private readonly ActivitySubmissionSlotBuilder $slots,
        private readonly ActivityLogger $logger,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * What the `{{ctx:…}}` references of a document read: the centre, the academic year of the cycle, the activity, what the
     * slot is for, and today.
     *
     * @return array<string, string>
     */
    public function contextFor(Activity $activity, ?ActivitySubmissionSlot $slot, int $cycleYear): array
    {
        return [
            'centre'   => $activity->getCategory()->getEducationalCentre()->getName(),
            'year'     => $cycleYear . '-' . ($cycleYear + 1),
            'activity' => $activity->getTitle(),
            'slot'     => $slot === null ? '' : $slot->displayName,
            'date'     => $this->clock->now()->format('d/m/Y'),
        ];
    }

    public function slotFor(Activity $activity, string $slotKey): ?ActivitySubmissionSlot
    {
        foreach ($this->slots->buildSlots($activity) as $slot) {
            if ($slot->key() === $slotKey) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * The draft of a slot, created the first time (from the revision of the model in force) and kept: opening it again returns
     * the same one. Persists and flushes only when it creates.
     *
     * @throws FormException `error.no_model_in_force` while the model has no approved revision
     */
    public function open(Activity $activity, ActivitySubmissionSlot $slot): FormSubmission
    {
        $cycle    = $this->slots->cycleKeyFor($activity, $slot);
        $existing = $this->submissions->findBySlot($activity, $slot->key(), $cycle);
        if ($existing !== null) {
            return $existing;
        }

        $model = $activity->getFormModel();
        $force = $model === null ? null : $this->modelRevisions->findInForce($model);
        if ($force === null) {
            throw new FormException('error.no_model_in_force');
        }

        $submission = new FormSubmission($activity, $slot->key(), $cycle, $force, $this->normalizer->initial($force->getDefinition()));
        $this->em->persist($submission);
        $this->em->flush();

        return $submission;
    }

    public function latestDelivered(FormSubmission $submission): ?FormSubmissionRevision
    {
        return $this->submissionRevisions->findLatest($submission);
    }

    /**
     * Starts a correction of what was delivered: a new draft with the data of the last delivered revision.
     */
    public function startCorrection(FormSubmission $submission): void
    {
        if ($submission->hasDraft()) {
            return;
        }
        $last = $this->latestDelivered($submission);
        if ($last === null) {
            return;
        }

        $revision = $last->getModelRevision();
        $submission->startDraft($revision, $this->normalizer->normalize($revision->getDefinition(), $last->getData()));
        $this->em->flush();
    }

    /** Throws the draft away: a delivered document keeps its last revision; one never delivered goes back to the initial data. */
    public function discardDraft(FormSubmission $submission): void
    {
        $last = $this->latestDelivered($submission);
        if ($last !== null) {
            $submission->closeDraft();
        } else {
            $revision = $submission->getModelRevision();
            $submission->startDraft($revision, $this->normalizer->initial($revision->getDefinition()));
        }
        $this->em->flush();
    }

    /**
     * Saves what was typed. $baseVersion is the version of the draft the tab started from: when somebody (or another tab)
     * saved in between, nothing is overwritten.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed> the data as it was saved
     *
     * @throws FormException `error.stale` | `error.no_draft`
     */
    public function save(FormSubmission $submission, array $raw, int $baseVersion, Teacher $by): array
    {
        if (!$submission->hasDraft()) {
            throw new FormException('error.no_draft');
        }
        if ($baseVersion !== $submission->getDraftVersion()) {
            throw new FormException('error.stale');
        }

        $data = $this->normalizer->normalize($submission->getModelRevision()->getDefinition(), $raw);
        $submission->saveDraft($data, $by);
        $this->em->flush();

        return $data;
    }

    /**
     * What stops the draft from being delivered.
     *
     * @return list<array{block: string, path: string, row: ?string, column: ?string, code: string, params: array<string, string>}>
     */
    public function problems(FormSubmission $submission): array
    {
        $definition = $submission->getModelRevision()->getDefinition();
        $data       = $this->normalizer->withoutEmptyRows($definition, $this->normalizer->normalize($definition, $submission->getDraftData() ?? []));

        return $this->validator->problems($definition, $data);
    }

    /**
     * Delivers the draft: the PDF is generated and stored, the Document (first delivery) or its next revision is created and the
     * data is frozen with it. Whoever calls has already checked who may deliver and that the activity's window is open.
     *
     * @throws FormException `error.no_draft` | `error.not_ready` (with the problems)
     */
    public function deliver(FormSubmission $submission, ActivitySubmissionSlot $slot, Teacher $by): DocumentRevision
    {
        if (!$submission->hasDraft()) {
            throw new FormException('error.no_draft');
        }
        $problems = $this->problems($submission);
        if ($problems !== []) {
            throw new FormException('error.not_ready', $problems);
        }

        $activity      = $submission->getActivity();
        $folder        = $activity->getFolder();
        $modelRevision = $submission->getModelRevision();
        if ($folder === null) {
            throw new FormException('error.no_folder');
        }

        $definition = $modelRevision->getDefinition();
        $data       = $this->normalizer->withoutEmptyRows($definition, $this->normalizer->normalize($definition, $submission->getDraftData() ?? []));

        $content = $this->pdf->buildForRevision($modelRevision, $data, $folder->getEducationalCentre(), $slot->displayName, false, $this->contextFor($activity, $slot, $submission->getCycleYear()));
        $file    = $this->documents->storeFile($content, 'application/pdf', $slot->displayName . '.pdf');

        $document = $submission->getDocument();
        if ($document === null) {
            // Same identity rule as an uploaded file: whoever is assigned to an individual slot is its uploader.
            $document = $this->documents->createFromStoredFile($folder, $slot->displayName, $slot->profile, $slot->listItem, $file, $slot->teacher ?? $by);
            $submission->setDocument($document);
            $revision = $document->getRevisions()->first();
            if ($revision === false) {
                throw new FormException('error.no_revision');
            }
        } else {
            $revision = $this->documents->addRevision($document, $file, $by);
        }

        $this->em->persist(new FormSubmissionRevision($submission, $revision, $modelRevision, $data));
        $submission->closeDraft();
        $this->em->flush();

        $this->logger->record('form_submission.deliver', ['activity' => $activity->getTitle(), 'document' => $document->getName(), 'version' => $revision->getVersion()], $folder->getEducationalCentre());
        if ($revision->isPendingReview()) {
            $this->notifier->notifyReviewers($revision);
        }

        return $revision;
    }

    public function modelRevisionOf(DocumentRevision $revision): ?FormModelRevision
    {
        return $this->modelRevisions->findByRevision($revision);
    }
}
