<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\FormSubmissionRevision;
use App\Repository\ActivityRepository;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Repository\FormValueRepository;
use App\Service\ActivitySubmissionSlotBuilder;

/**
 * The data explorer: the values of the documents filled in online as flat rows (one per value), for a cycle, to filter and
 * export. Only the latest APPROVED revision of each slot counts, as in the indicators, and only the activities the caller can
 * see (the centre's, which is what the Informes permission already grants). Reads FormValue rows, never whole documents.
 */
final class FormDataExplorer
{
    public const array COLUMNS = ['activity', 'cycle', 'slot', 'version', 'copy', 'row', 'field', 'value'];

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly FormSubmissionRepository $submissions,
        private readonly FormSubmissionRevisionRepository $revisions,
        private readonly FormValueRepository $values,
        private readonly ActivitySubmissionSlotBuilder $slots,
        private readonly FormListOptions $lists,
    ) {}

    /** @return list<Activity> the activities of the centre that are filled in online */
    public function activities(EducationalCentre $centre): array
    {
        return array_values(array_filter(
            $this->activities->findAllByCentre($centre, true),
            static fn (Activity $a): bool => $a->isForm() && $a->getFormModel() !== null,
        ));
    }

    /**
     * @return list<array{activity: string, cycle: int, slot: string, version: int, copy: string, row: string, field: string, value: string}>
     */
    public function rows(Activity $activity, int $cycle): array
    {
        $labels = [];
        foreach ($this->slots->buildSlots($activity) as $slot) {
            $labels[$slot->key()] = $slot->displayName;
        }

        /** @var array<string, FormSubmissionRevision> $latest */
        $latest = [];
        /** @var array<string, string> $slotOf */
        $slotOf = [];
        foreach ($this->submissions->findByActivityCycle($activity, $cycle) as $submission) {
            $best = null;
            foreach ($this->revisions->findBySubmission($submission) as $candidate) {
                if ($candidate->getRevision()->isApproved() && ($best === null || $candidate->getRevision()->getVersion() > $best->getRevision()->getVersion())) {
                    $best = $candidate;
                }
            }
            if ($best !== null) {
                $latest[$best->getId()->toRfc4122()] = $best;
                $slotOf[$best->getId()->toRfc4122()] = $submission->getSlotKey();
            }
        }

        /** @var array<string, FormIndex> $indexes by model revision id */
        $indexes = [];
        $out     = [];
        foreach ($this->values->findByActivityCycle($activity, $cycle) as $value) {
            $revId = $value->getSubmissionRevision()->getId()->toRfc4122();
            if (!isset($latest[$revId])) {
                continue;
            }
            $revision = $latest[$revId];
            // Per delivered revision, not per model revision: what a list-fed field offered was frozen with each delivery.
            $index    = $indexes[$revId] ??= FormIndex::of($this->lists->delivered($revision));

            [$label, $options] = $this->describe($index, $value->getFieldKey());
            $out[] = [
                'activity' => $activity->getTitle(),
                'cycle'    => $cycle,
                'slot'     => $labels[$slotOf[$revId]] ?? $slotOf[$revId],
                'version'  => $revision->getRevision()->getVersion(),
                'copy'     => $value->getPath(),
                'row'      => $value->getRowKey() ?? '',
                'field'    => $label,
                'value'    => $this->display($value->getNum(), $value->getText(), $value->getFlag(), $options),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['slot'], $a['copy'], $a['row'], $a['field']] <=> [$b['slot'], $b['copy'], $b['row'], $b['field']]);

        return $out;
    }

    /** @return array{0: string, 1: array<string, string>} the label of a field or column and its options' labels by key */
    private function describe(FormIndex $index, string $key): array
    {
        $spec = $index->fields[$key] ?? null;
        $name = $spec === null ? null : FormDefinition::str($spec['label'] ?? '');
        if ($spec === null) {
            foreach ($index->tables as $table) {
                $column = $index->column(FormDefinition::str($table['key'] ?? ''), $key);
                if ($column !== null) {
                    $spec = $column;
                    $name = FormDefinition::str($table['title'] ?? '') . ' › ' . FormDefinition::str($column['label'] ?? '');
                    break;
                }
            }
        }

        $options = [];
        foreach (FormDefinition::listOf($spec['options'] ?? []) as $option) {
            $options[FormDefinition::str($option['key'] ?? '')] = FormDefinition::str($option['label'] ?? '');
        }

        return [($name ?? '') !== '' ? (string) $name : $key, $options];
    }

    /** @param array<string, string> $options */
    private function display(?float $num, ?string $text, ?bool $flag, array $options): string
    {
        if ($flag !== null) {
            return $flag ? '1' : '0';
        }
        if ($num !== null) {
            return rtrim(rtrim(number_format($num, 6, '.', ''), '0'), '.');
        }
        if ($text === null) {
            return '';
        }

        return implode(', ', array_map(static fn (string $k): string => $options[$k] ?? $k, explode(',', $text)));
    }
}
