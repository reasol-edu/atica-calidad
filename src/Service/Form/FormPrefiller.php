<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Activity;
use App\Entity\FormModelRevision;
use App\Entity\FormSubmission;
use App\Entity\ListItem;
use App\Model\ActivitySubmissionSlot;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;

/**
 * What a new draft starts from instead of blank: what the same slot delivered in an earlier academic year, or what the slot delivered
 * in the activity the form says it pre-fills from. Values travel by identity key when both documents come from the same model, and by
 * the internal name of the fields when they do not (a field named "grupo" in one model fills the one named "grupo" in the other).
 */
final class FormPrefiller
{
    public function __construct(
        private readonly FormSubmissionRepository $submissions,
        private readonly FormSubmissionRevisionRepository $revisions,
        private readonly FormDataNormalizer $normalizer,
        private readonly FormListOptions $lists,
        private readonly \App\Repository\ListItemRepository $items,
    ) {}

    /**
     * @return array{data: array<string, mixed>, from: array<string, mixed>}|null null when there is nothing to copy from
     */
    public function build(Activity $activity, string $slotKey, int $cycleYear, FormModelRevision $target): ?array
    {
        // With dimensions: the previous value of the same submission, this year (the 2ª starts from the 1ª).
        $previous = $activity->isFormPrefillPrevious() ? $this->previousValue($activity, $slotKey, $cycleYear) : null;
        if ($previous !== null) {
            return $this->transfer($previous['revision'], $target, 'previous_value', $previous['label'], $slotKey);
        }

        $source = $activity->getFormPrefillSource();
        if ($source !== null) {
            // The same submission there; one without dimensions is the same element without the combination (the 1ª of a subject's
            // review starts from the subject's programme).
            $found = $this->latestDelivered($source, $slotKey, null)
                ?? (ActivitySubmissionSlot::combinationOf($slotKey) !== [] && !$source->hasDimensions() ? $this->latestDelivered($source, self::withoutCombination($slotKey), null) : null);
            if ($found !== null) {
                return $this->transfer($found, $target, 'document', $source->getTitle(), $slotKey);
            }
        }

        $found = $this->latestDelivered($activity, $slotKey, $cycleYear);
        if ($found === null) {
            return null;
        }
        $year = $found->getSubmission()->getCycleYear();

        return $this->transfer($found, $target, 'previous', $year . '-' . ($year + 1), $slotKey);
    }

    /**
     * The last delivered revision of the same submission in an earlier value of the dimension that marks the dates (or, without one,
     * the first), this cycle: the value right before in the list's order, or the one before that if it was not delivered, and so on.
     * Values the element does not have have no submission, so they are skipped by themselves (Final 1 of an FP module comes from the
     * 2ª, never from the Extr.).
     *
     * @return array{revision: \App\Entity\FormSubmissionRevision, label: string}|null
     */
    private function previousValue(Activity $activity, string $slotKey, int $cycleYear): ?array
    {
        $ids        = ActivitySubmissionSlot::combinationOf($slotKey);
        $dimensions = $activity->getDimensions();
        if ($ids === [] || $dimensions === []) {
            return null;
        }
        $index = 0;
        foreach ($dimensions as $i => $dimension) {
            $index = $dimension->isDated() ? $i : $index;
        }
        $values = array_values($this->items->findLeafDescendants($dimensions[$index]->getList()));
        $order  = array_map(static fn (ListItem $v): string => $v->getId()->toRfc4122(), $values);
        $at     = array_search($ids[$index] ?? '', $order, true);
        if ($at === false) {
            return null;
        }

        for ($p = $at - 1; $p >= 0; --$p) {
            $candidate         = $ids;
            $candidate[$index] = $order[$p];
            $submission        = $this->submissions->findBySlot($activity, self::withoutCombination($slotKey) . ':' . implode('+', $candidate), $cycleYear);
            $revision          = $submission === null ? null : $this->revisions->findLatest($submission);
            if ($revision !== null) {
                return ['revision' => $revision, 'label' => $values[$p]->getName()];
            }
        }

        return null;
    }

    /** The first four parts of a slot key: the same submission without the combination. */
    private static function withoutCombination(string $slotKey): string
    {
        return implode(':', array_slice(explode(':', $slotKey), 0, 4));
    }

    /** The slot's most recent delivered revision, among the cycles before $before when given. */
    private function latestDelivered(Activity $activity, string $slotKey, ?int $before): ?\App\Entity\FormSubmissionRevision
    {
        foreach ($this->submissions->findByActivitySlot($activity, $slotKey) as $submission) {
            if ($before !== null && $submission->getCycleYear() >= $before) {
                continue;
            }
            $last = $this->revisions->findLatest($submission);
            if ($last !== null) {
                return $last;
            }
        }

        return null;
    }

    /**
     * @return array{data: array<string, mixed>, from: array<string, mixed>}
     */
    private function transfer(\App\Entity\FormSubmissionRevision $from, FormModelRevision $target, string $kind, string $label, string $slotKey): array
    {
        $sourceRevision = $from->getModelRevision();
        $targetDef      = $target->getDefinition();

        // What is copied is normalized against the target with its lists read as they are now: an item that was deactivated since
        // stays in sight (and blocks the delivery) instead of vanishing from the copy.
        $raw  = $sourceRevision->getModel()->getId()->equals($target->getModel()->getId())
            ? $from->getData()
            : ['fields' => $this->byName($sourceRevision->getDefinition(), $from->getData(), $targetDef)];
        // (With the rows of who delivers it: a pre-fill read against every item would be filtered on the next save.)
        $data = $this->normalizer->normalize($this->lists->live($target, $raw, \App\Model\ActivitySubmissionSlot::deliveredBy($slotKey)), $raw);

        return ['data' => $data, 'from' => ['kind' => $kind, 'label' => $label, 'revisionId' => $from->getRevision()->getId()->toRfc4122()]];
    }

    /**
     * The source's field values that have a field of the same internal name and type in the target, keyed by the target's key.
     *
     * @param array<string, mixed> $sourceDef
     * @param array<string, mixed> $sourceData
     * @param array<string, mixed> $targetDef
     *
     * @return array<string, mixed>
     */
    private function byName(array $sourceDef, array $sourceData, array $targetDef): array
    {
        $values = FormDefinition::map($sourceData['fields'] ?? null) ?? [];
        $byName = [];
        foreach (FormIndex::of($sourceDef)->fields as $key => $block) {
            $name = FormDefinition::str($block['name'] ?? '');
            if ($name !== '' && ($block['fieldType'] ?? '') !== 'calculated' && array_key_exists($key, $values)) {
                $byName[$name] = ['type' => $block['fieldType'] ?? '', 'value' => $values[$key]];
            }
        }

        $out = [];
        foreach (FormIndex::of($targetDef)->fields as $key => $block) {
            $name = FormDefinition::str($block['name'] ?? '');
            if ($name !== '' && isset($byName[$name]) && $byName[$name]['type'] === ($block['fieldType'] ?? null)) {
                $out[$key] = $byName[$name]['value'];
            }
        }

        return $out;
    }
}
