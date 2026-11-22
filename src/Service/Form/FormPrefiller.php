<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Activity;
use App\Entity\FormModelRevision;
use App\Entity\FormSubmission;
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
    ) {}

    /**
     * @return array{data: array<string, mixed>, from: array<string, mixed>}|null null when there is nothing to copy from
     */
    public function build(Activity $activity, string $slotKey, int $cycleYear, FormModelRevision $target): ?array
    {
        $source = $activity->getFormPrefillSource();
        if ($source !== null) {
            $found = $this->latestDelivered($source, $slotKey, null);
            if ($found !== null) {
                return $this->transfer($found, $target, 'document', $source->getTitle());
            }
        }

        $found = $this->latestDelivered($activity, $slotKey, $cycleYear);
        if ($found === null) {
            return null;
        }
        $year = $found->getSubmission()->getCycleYear();

        return $this->transfer($found, $target, 'previous', $year . '-' . ($year + 1));
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
    private function transfer(\App\Entity\FormSubmissionRevision $from, FormModelRevision $target, string $kind, string $label): array
    {
        $sourceRevision = $from->getModelRevision();
        $targetDef      = $target->getDefinition();

        if ($sourceRevision->getModel()->getId()->equals($target->getModel()->getId())) {
            $data = $this->normalizer->normalize($targetDef, $from->getData());
        } else {
            $data = $this->normalizer->normalize($targetDef, ['fields' => $this->byName($sourceRevision->getDefinition(), $from->getData(), $targetDef)]);
        }

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
