<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Activity;
use App\Entity\DocumentRevision;
use App\Entity\FormFieldChange;
use App\Entity\FormModelRevision;
use App\Repository\FormFieldChangeRepository;
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
        private readonly FormApprovalChain $chain,
        private readonly FormFlatData $flat,
        private readonly FormFieldChangeRepository $changes,
        private readonly FormPrefiller $prefiller,
        private readonly FormUpdateService $updates,
        private readonly FormValueIndexer $values,
        private readonly FormIndicatorCalculator $indicators,
        private readonly FormListOptions $lists,
    ) {}

    /**
     * The definition a draft is filled with: the model revision's, with the options that come from the centre's lists as they are
     * now (see FormListOptions).
     *
     * @param array<string, mixed>|null $data what is typed (the draft's own when omitted)
     *
     * @return array<string, mixed>
     */
    public function definitionOf(FormSubmission $submission, ?array $data = null): array
    {
        return $this->lists->live($submission->getModelRevision(), $data ?? $submission->getDraftData() ?? []);
    }

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

        $prefill    = $this->prefiller->build($activity, $slot->key(), $cycle, $force);
        $submission = new FormSubmission($activity, $slot->key(), $cycle, $force, $prefill['data'] ?? $this->normalizer->initial($force->getDefinition()));
        $submission->setPrefilledFrom($prefill['from'] ?? null);
        $this->em->persist($submission);
        $this->em->flush();

        return $submission;
    }

    /**
     * Creates the draft of every slot of an activity that has none yet (pre-filled as any new draft is), so everybody finds theirs
     * waiting when the period opens.
     *
     * @return int how many drafts were created
     */
    public function createMissingDrafts(Activity $activity): int
    {
        $created = 0;
        foreach ($this->slots->buildSlots($activity) as $slot) {
            if ($this->submissions->findBySlot($activity, $slot->key(), $this->slots->cycleKeyFor($activity, $slot)) !== null) {
                continue;
            }
            try {
                $this->open($activity, $slot);
                ++$created;
            } catch (FormException) {
                return $created; // no revision in force yet: nothing to create for any slot
            }
        }

        return $created;
    }

    /** Throws the typed data of a never-delivered draft away and starts blank, even if it was pre-filled. */
    public function startFromScratch(FormSubmission $submission): void
    {
        if (!$submission->hasDraft()) {
            return;
        }
        $revision = $submission->getModelRevision();
        $submission->startDraft($revision, $this->normalizer->initial($revision->getDefinition()));
        $submission->setPrefilledFrom(null);
        $this->em->flush();
    }

    /** Copies again what the draft was pre-filled from (or what it would be pre-filled from today), replacing what is typed. */
    public function prefillAgain(FormSubmission $submission): bool
    {
        if (!$submission->hasDraft()) {
            return false;
        }
        $revision = $submission->getModelRevision();
        $prefill  = $this->prefiller->build($submission->getActivity(), $submission->getSlotKey(), $submission->getCycleYear(), $revision);
        if ($prefill === null) {
            return false;
        }
        $submission->startDraft($revision, $prefill['data']);
        $submission->setPrefilledFrom($prefill['from']);
        $this->em->flush();

        return true;
    }

    public function updates(): FormUpdateService
    {
        return $this->updates;
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
        $submission->startDraft($revision, $this->normalizer->normalize($this->lists->live($revision, $last->getData()), $last->getData()));
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
     * @throws FormException `error.stale` | `error.no_draft` | `error.locked`
     */
    public function save(FormSubmission $submission, array $raw, int $baseVersion, Teacher $by): array
    {
        if (!$submission->hasDraft()) {
            throw new FormException('error.no_draft');
        }
        $this->acquireLock($submission, $by);
        if ($baseVersion !== $submission->getDraftVersion()) {
            throw new FormException('error.stale');
        }

        $definition = $this->definitionOf($submission, $raw);
        $data       = $this->normalizer->normalize($definition, $raw);
        $this->recordChanges($submission, $definition, $submission->getDraftData() ?? [], $data, $by);
        $submission->saveDraft($data, $by);
        $this->em->flush();

        return $data;
    }

    // ── Edit lock: one person at a time ──────────────────────────────────────

    /** Seconds the lock lasts without activity: saving and the page's heartbeat renew it. */
    public const int LOCK_SECONDS = 300;

    public function lockHolder(FormSubmission $submission): ?Teacher
    {
        return $submission->getLockHolder($this->clock->now());
    }

    /**
     * Takes the edit lock of a draft, or renews it when it is already the teacher's. Nothing is flushed here when only renewing is
     * not needed: callers flush.
     *
     * @throws FormException `error.locked` when somebody else holds it
     */
    public function acquireLock(FormSubmission $submission, Teacher $by): void
    {
        $holder = $this->lockHolder($submission);
        if ($holder !== null && !$holder->getId()->equals($by->getId())) {
            throw new FormException('error.locked');
        }
        $submission->lock($by, $this->clock->now()->modify('+' . self::LOCK_SECONDS . ' seconds'));
    }

    /** Takes the lock whoever holds it (the previous holder finds out the next time they save). */
    public function takeOver(FormSubmission $submission, Teacher $by): void
    {
        $submission->lock($by, $this->clock->now()->modify('+' . self::LOCK_SECONDS . ' seconds'));
        $this->em->flush();
    }

    // ── History of a value ───────────────────────────────────────────────────

    /**
     * Writes one change for every value that differs between what was saved and what is being saved. The same person changing the
     * same value again within two minutes amends the last change, so typing a sentence is one entry, not one per keystroke.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function recordChanges(FormSubmission $submission, array $definition, array $before, array $after, Teacher $by): void
    {
        $old = $this->flat->flatten($definition, $before);
        $new = $this->flat->flatten($definition, $after);

        foreach ($new + $old as $id => $entry) {
            $from = $old[$id]['value'] ?? null;
            $to   = $new[$id]['value'] ?? null;
            if ($from === $to) {
                continue;
            }
            $last = $this->changes->findLastForValue($submission, $entry['block'], $entry['path'], $entry['row'], $entry['column']);
            $json = $to === null ? null : json_encode($to, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if ($last !== null && $last->getChangedBy()?->getId()->equals($by->getId()) === true && $last->getChangedAt() > $this->clock->now()->modify('-120 seconds')) {
                $last->amend($json);
                continue;
            }
            $this->em->persist(new FormFieldChange(
                $submission,
                $entry['block'],
                $entry['path'],
                $entry['row'],
                $entry['column'],
                $from === null ? null : json_encode($from, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $json,
                $by,
            ));
        }
    }

    /**
     * The changes of one value, newest first.
     *
     * @return list<FormFieldChange>
     */
    public function historyOf(FormSubmission $submission, string $block, string $path, ?string $row, ?string $column): array
    {
        return $this->changes->findForValue($submission, $block, $path, $row, $column);
    }

    /**
     * What is wrong with the draft: the problems that stop it from being delivered (`severity` block) and the warnings of the
     * model's rules that only advise (`severity` warning).
     *
     * @return list<array{block: string, path: string, row: ?string, column: ?string, code: string, params: array<string, string>, severity: string}>
     */
    public function problems(FormSubmission $submission): array
    {
        $definition = $this->definitionOf($submission);
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
        $holder = $this->lockHolder($submission);
        if ($holder !== null && !$holder->getId()->equals($by->getId())) {
            throw new FormException('error.locked');
        }
        if ($this->updates->isUpdateRequired($submission)) {
            throw new FormException('error.update_required');
        }
        $problems = $this->validator->blocking($this->problems($submission));
        if ($problems !== []) {
            throw new FormException('error.not_ready', $problems);
        }

        $activity     = $submission->getActivity();
        $folder        = $activity->getFolder();
        $modelRevision = $submission->getModelRevision();
        if ($folder === null) {
            throw new FormException('error.no_folder');
        }

        $definition = $this->definitionOf($submission);
        $data       = $this->normalizer->withoutEmptyRows($definition, $this->normalizer->normalize($definition, $submission->getDraftData() ?? []));

        $content = $this->pdf->buildForRevision($modelRevision, $definition, $data,$folder->getEducationalCentre(), $slot->displayName, false, $this->contextFor($activity, $slot, $submission->getCycleYear()));
        $file    = $this->documents->storeFile($content, 'application/pdf', $slot->displayName . '.pdf');

        // A model with an approval chain: the revision waits for its steps whatever the folder asks.
        $hasChain = FormApprovalChain::defines($definition);

        $document = $submission->getDocument();
        if ($document === null) {
            // Same identity rule as an uploaded file: whoever is assigned to an individual slot is its uploader.
            $document = $this->documents->createFromStoredFile($folder, $slot->displayName, $slot->profile, $slot->listItem, $file, $slot->teacher ?? $by, null, $hasChain);
            $submission->setDocument($document);
            $revision = $document->getRevisions()->first();
            if ($revision === false) {
                throw new FormException('error.no_revision');
            }
        } else {
            $revision = $this->documents->addRevision($document, $file, $by, null, $hasChain);
        }

        // The lists may change later: what was selected is frozen with the name it has now.
        $filled = new FormSubmissionRevision($submission, $revision, $modelRevision, $data, $this->lists->snapshot($definition, $data));
        $this->em->persist($filled);
        $this->values->index($filled);
        if ($hasChain) {
            $this->chain->start($filled, $folder->getEducationalCentre());
        }
        $submission->closeDraft()->releaseLock();
        $this->em->flush();

        $this->logger->record('form_submission.deliver', ['activity' => $activity->getTitle(), 'document' => $document->getName(), 'version' => $revision->getVersion()], $folder->getEducationalCentre());
        // With a chain the folder's reviewers are not the ones who decide: the people of the first step find it waiting for them.
        if ($revision->isPendingReview() && !$hasChain) {
            $this->notifier->notifyReviewers($revision);
        }
        // Approved at once (nobody reviews this folder): the indicators fed from it have a new value now.
        if ($revision->isApproved()) {
            $this->indicators->recalculateForActivity($activity);
        }

        return $revision;
    }

    public function modelRevisionOf(DocumentRevision $revision): ?FormModelRevision
    {
        return $this->modelRevisions->findByRevision($revision);
    }
}
