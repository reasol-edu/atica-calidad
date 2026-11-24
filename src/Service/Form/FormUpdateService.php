<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\FormModel;
use App\Entity\FormModelRevision;
use App\Entity\FormSubmission;
use App\Entity\FormUpdatePolicy;
use App\Repository\FormModelRevisionRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Service\ActivityDeadlineChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Moves what was filled with an earlier revision of a model to the one in force. Values travel by the identity key of each field, so
 * whatever the new revision still has is kept; what it no longer has is listed before the change so nobody loses it by surprise.
 * Whoever publishes the revision chooses whether updating is optional or mandatory (from a date): a mandatory update blocks delivering
 * the old revision once it is due.
 */
final class FormUpdateService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormModelRevisionRepository $modelRevisions,
        private readonly FormSubmissionRepository $submissions,
        private readonly FormSubmissionRevisionRepository $submissionRevisions,
        private readonly FormDataNormalizer $normalizer,
        private readonly FormListOptions $lists,
        private readonly FormFlatData $flat,
        private readonly ActivityDeadlineChecker $deadlines,
        private readonly ClockInterface $clock,
    ) {}

    /** The revision a submission is on: the draft's when there is one, otherwise the last one delivered. */
    public function effectiveRevision(FormSubmission $submission): FormModelRevision
    {
        if ($submission->hasDraft()) {
            return $submission->getModelRevision();
        }

        return $this->submissionRevisions->findLatest($submission)?->getModelRevision() ?? $submission->getModelRevision();
    }

    /** The revision in force when it is newer than the one the submission is on, otherwise null. */
    public function pendingUpdate(FormSubmission $submission): ?FormModelRevision
    {
        $model = $submission->getActivity()->getFormModel();
        if ($model === null) {
            return null;
        }
        $force = $this->modelRevisions->findInForce($model);
        if ($force === null) {
            return null;
        }
        $current = $this->effectiveRevision($submission);

        return $force->getModel()->getId()->equals($current->getModel()->getId()) && $force->getRevision()->getVersion() > $current->getRevision()->getVersion() ? $force : null;
    }

    /** Whether the submission must be updated before it is delivered: the revision in force asks for it and its date has come. */
    public function isUpdateRequired(FormSubmission $submission): bool
    {
        $force = $this->pendingUpdate($submission);
        if ($force === null || $force->getUpdatePolicy() !== FormUpdatePolicy::Mandatory) {
            return false;
        }
        $deadline = $force->getUpdateDeadline();

        return $deadline === null || $deadline <= $this->clock->now()->setTime(0, 0);
    }

    /**
     * The labels of the filled values the update would drop, because the new revision has nothing to put them in.
     *
     * @return list<string>
     */
    public function lostOnUpdate(FormSubmission $submission): array
    {
        $target = $this->pendingUpdate($submission);
        if ($target === null) {
            return [];
        }
        [$oldDefinition, $oldData] = $this->sourceOf($submission);
        // Both with the rows of who delivers it (a table whose rows come from a list has none in the stored definition).
        $slot                      = \App\Model\ActivitySubmissionSlot::deliveredBy($submission->getSlotKey());
        $oldDefinition             = $this->lists->expand($oldDefinition, $submission->getActivity()->getCategory()->getEducationalCentre(), $oldData, $slot);
        $newDefinition             = $this->lists->live($target, $oldData, $slot);
        $newFlat                   = $this->flat->flatten($newDefinition, $this->normalizer->normalize($newDefinition, $oldData));
        $index                     = FormIndex::of($oldDefinition);

        $lost = [];
        foreach ($this->flat->flatten($oldDefinition, $oldData) as $identity => $entry) {
            if (isset($newFlat[$identity]) || $this->isEmpty($entry['value'])) {
                continue;
            }
            $block  = $index->fields[$entry['block']] ?? $index->tables[$entry['block']] ?? [];
            $lost[] = FormDefinition::str($block['label'] ?? '') ?: FormDefinition::str($block['title'] ?? '') ?: $entry['block'];
        }

        return array_values(array_unique($lost));
    }

    /**
     * Puts the submission on the revision in force, keeping what still fits: a draft changes revision in place; a delivered document
     * (with no draft) gets a draft with its data, to be delivered again as the next revision.
     */
    public function update(FormSubmission $submission): bool
    {
        $target = $this->pendingUpdate($submission);
        if ($target === null) {
            return false;
        }
        [, $oldData] = $this->sourceOf($submission);
        $submission->startDraft($target, $this->normalizer->normalize($this->lists->live($target, $oldData, \App\Model\ActivitySubmissionSlot::deliveredBy($submission->getSlotKey())), $oldData));
        $this->em->flush();

        return true;
    }

    /**
     * What publishing a revision touches: the submissions of the current academic year filled with the model, still open as drafts or
     * already delivered.
     *
     * @return array{drafts: int, delivered: int}
     */
    public function impact(FormModel $model): array
    {
        $drafts = $delivered = 0;
        foreach ($this->submissions->findForModel($model) as $submission) {
            if ($submission->getCycleYear() !== $this->deadlines->currentCycleKey($submission->getActivity())) {
                continue;
            }
            if ($submission->hasDraft()) {
                ++$drafts;
            } elseif ($submission->getDocument() !== null) {
                ++$delivered;
            }
        }

        return ['drafts' => $drafts, 'delivered' => $delivered];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the definition and the data the submission is on
     */
    private function sourceOf(FormSubmission $submission): array
    {
        if ($submission->hasDraft()) {
            return [$submission->getModelRevision()->getDefinition(), $submission->getDraftData() ?? []];
        }
        $last = $this->submissionRevisions->findLatest($submission);
        if ($last === null) {
            return [$submission->getModelRevision()->getDefinition(), []];
        }

        return [$last->getModelRevision()->getDefinition(), $last->getData()];
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false;
    }
}
