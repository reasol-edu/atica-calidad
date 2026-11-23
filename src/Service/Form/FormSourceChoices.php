<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\EducationalCentre;
use App\Entity\FormModel;
use App\Repository\ActivityRepository;
use App\Repository\FormModelRevisionRepository;

/**
 * The fields an indicator can be fed from: those of the models of the centre's activities filled in online (the revision in force),
 * by activity (only those of one model, if given) — numbers, yes/no, choices and calculated values, in fields and in table columns.
 */
final class FormSourceChoices
{
    private const array TYPES = ['number', 'bool', 'choice', 'calculated', 'scale'];

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly FormModelRevisionRepository $modelRevisions,
        private readonly FormListOptions $lists,
    ) {}

    /** @return list<array{id: string, name: string, fields: list<array{key: string, label: string, type: string, options: list<array{key: string, label: string}>}>}> */
    public function forCentre(EducationalCentre $centre, ?FormModel $only = null): array
    {
        $out    = [];
        $forces = $this->modelRevisions->findAllInForceByCentre($centre);
        foreach ($this->activities->findAllByCentre($centre, true) as $activity) {
            $model = $activity->getFormModel();
            if (!$activity->isForm() || $model === null || ($only !== null && !$model->getId()->equals($only->getId()))) {
                continue;
            }
            $force = $forces[$model->getId()->toRfc4122()] ?? null;
            if ($force === null) {
                continue;
            }
            $fields = [];
            // With the options of the lists as they are now: a condition on a choice is picked among them.
            $index = FormIndex::of($this->lists->expand($force->getDefinition(), $centre));
            foreach ($index->fields as $key => $block) {
                $type = FormDefinition::str($block['fieldType']);
                if (in_array($type, self::TYPES, true)) {
                    $fields[] = ['key' => $key, 'label' => FormDefinition::str($block['label'] ?? '') ?: $key, 'type' => $type, 'options' => self::optionsOf($block)];
                }
            }
            foreach ($index->tables as $table) {
                foreach (FormDefinition::listOf($table['columns'] ?? []) as $column) {
                    $type = FormDefinition::str($column['fieldType']);
                    if (in_array($type, self::TYPES, true)) {
                        $fields[] = ['key' => FormDefinition::str($column['key'] ?? ''), 'label' => FormDefinition::str($table['label'] ?? '') . ' › ' . FormDefinition::str($column['label'] ?? ''), 'type' => $type, 'options' => self::optionsOf($column)];
                    }
                }
            }
            $out[] = ['id' => $activity->getId()->toRfc4122(), 'name' => $activity->getTitle(), 'fields' => $fields];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return list<array{key: string, label: string}> what a choice offers (nothing for any other type)
     */
    private static function optionsOf(array $spec): array
    {
        if (FormDefinition::str($spec['fieldType'] ?? '') !== 'choice') {
            return [];
        }
        $options = [];
        foreach (FormDefinition::listOf($spec['options'] ?? []) as $option) {
            $options[] = ['key' => FormDefinition::str($option['key'] ?? ''), 'label' => FormDefinition::str($option['label'] ?? '')];
        }

        return $options;
    }
}
