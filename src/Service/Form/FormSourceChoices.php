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
    private const array TYPES = ['number', 'bool', 'choice', 'calculated'];

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly FormModelRevisionRepository $modelRevisions,
    ) {}

    /** @return list<array{id: string, name: string, fields: list<array{key: string, label: string, type: string}>}> */
    public function forCentre(EducationalCentre $centre, ?FormModel $only = null): array
    {
        $out = [];
        foreach ($this->activities->findAllByCentre($centre, true) as $activity) {
            $model = $activity->getFormModel();
            if (!$activity->isForm() || $model === null || ($only !== null && !$model->getId()->equals($only->getId()))) {
                continue;
            }
            $force = $this->modelRevisions->findInForce($model);
            if ($force === null) {
                continue;
            }
            $fields = [];
            $index  = FormIndex::of($force->getDefinition());
            foreach ($index->fields as $key => $block) {
                $type = FormDefinition::str($block['fieldType']);
                if (in_array($type, self::TYPES, true)) {
                    $fields[] = ['key' => $key, 'label' => FormDefinition::str($block['label'] ?? '') ?: $key, 'type' => $type];
                }
            }
            foreach ($index->tables as $table) {
                foreach (FormDefinition::listOf($table['columns'] ?? []) as $column) {
                    $type = FormDefinition::str($column['fieldType']);
                    if (in_array($type, self::TYPES, true)) {
                        $fields[] = ['key' => FormDefinition::str($column['key'] ?? ''), 'label' => FormDefinition::str($table['title'] ?? '') . ' › ' . FormDefinition::str($column['label'] ?? ''), 'type' => $type];
                    }
                }
            }
            $out[] = ['id' => $activity->getId()->toRfc4122(), 'name' => $activity->getTitle(), 'fields' => $fields];
        }

        return $out;
    }
}
