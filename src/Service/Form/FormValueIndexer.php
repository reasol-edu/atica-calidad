<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\FormSubmissionRevision;
use App\Entity\FormValue;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Derives the FormValue rows of a delivered revision from its frozen data and definition: one per scalar value (calculated ones
 * included, they are what indicators total). Never a source of truth — rebuilt whenever needed with `app:forms:reindex`.
 */
final class FormValueIndexer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FormWalker $walker,
    ) {}

    /** Replaces the values of a revision with the ones its data holds; persists, the caller flushes. */
    public function index(FormSubmissionRevision $revision): int
    {
        $this->em->createQuery('DELETE FROM ' . FormValue::class . ' v WHERE v.submissionRevision = :r')->setParameter('r', $revision->getId(), 'uuid')->execute();

        $count = 0;
        foreach ($this->extract($revision->getModelRevision()->getDefinition(), $revision->getData()) as [$key, $path, $row, $type, $value]) {
            // A multiple choice is one row per option chosen (the key of the option in `text`), however many: no value is cut, and
            // "who chose this item" is an exact match.
            foreach (is_array($value) && $value !== [] ? array_values($value) : [$value] as $one) {
                [$num, $text, $flag] = self::typed($type, $one);
                if ($num === null && $text === null && $flag === null) {
                    continue;
                }
                $this->em->persist(new FormValue($revision, $key, $path, $row, $num, $text, $flag));
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: string, 4: mixed}> key, path, row key, field type, value
     */
    public function extract(array $definition, array $data): array
    {
        $out = [];
        $this->visit($this->walker->walk($definition, $data), $out);

        return $out;
    }

    /**
     * @param list<array<string, mixed>>                                                    $instances
     * @param list<array{0: string, 1: string, 2: ?string, 3: string, 4: mixed}> $out
     */
    private function visit(array $instances, array &$out): void
    {
        foreach ($instances as $instance) {
            $path   = FormDefinition::str($instance['path'] ?? '');
            $scope  = FormDefinition::map($instance['scope'] ?? null) ?? [];
            $fields = FormDefinition::map($scope['fields'] ?? null) ?? [];
            $tables = FormDefinition::map($scope['tables'] ?? null) ?? [];

            foreach (FormDefinition::listOf($instance['blocks'] ?? []) as $entry) {
                $block = FormDefinition::map($entry['block'] ?? null) ?? [];
                $key   = FormDefinition::str($block['key'] ?? '');
                if (($block['type'] ?? '') === 'field' && array_key_exists($key, $fields)) {
                    $out[] = [$key, $path, null, FormDefinition::str($block['fieldType'] ?? ''), $fields[$key]];
                } elseif (($block['type'] ?? '') === 'table' && ($block['mode'] ?? '') !== 'matrix') {
                    foreach (FormDefinition::listOf($tables[$key] ?? []) as $row) {
                        $rowKey = FormDefinition::str($row['_key'] ?? '');
                        foreach (FormDefinition::listOf($block['columns'] ?? []) as $column) {
                            $columnKey = FormDefinition::str($column['key'] ?? '');
                            if (array_key_exists($columnKey, $row)) {
                                $out[] = [$columnKey, $path, $rowKey, FormDefinition::str($column['fieldType'] ?? ''), $row[$columnKey]];
                            }
                        }
                    }
                }
            }
            $this->visit(FormDefinition::listOf($instance['children'] ?? []), $out);
        }
    }

    /** @return array{0: ?float, 1: ?string, 2: ?bool} number, text and yes/no of a value as the index keeps them */
    public static function typed(string $type, mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [null, null, null];
        }
        if ($type === 'bool') {
            return [$value ? 1.0 : 0.0, null, (bool) $value];
        }
        if ($type === 'attachment') {
            // One file of a field «Adjuntos» (the indexer passes them one by one): its name.
            $name = is_array($value) ? FormDefinition::str($value['name'] ?? '') : '';

            return $name === '' ? [null, null, null] : [null, $name, null];
        }
        if (is_int($value) || is_float($value) || ($type === 'number' && is_numeric($value))) {
            return [(float) $value, null, null];
        }

        if ($type === 'long' && is_string($value)) {
            // Rich text is indexed (and explored) as the text it says.
            return [null, FormRichText::plain($value), null];
        }

        return [null, is_scalar($value) ? (string) $value : null, null];
    }
}
