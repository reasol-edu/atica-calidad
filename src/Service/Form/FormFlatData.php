<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * The typed values of a document's data one by one, each with the identity that keeps it apart: the copy it is in (`path`), its
 * block and, in a table, its row and column. What the history of a field is made of. Calculated values are left out: they are not
 * anybody's change.
 */
final class FormFlatData
{
    public function __construct(
        private readonly FormWalker $walker,
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return array<string, array{path: string, block: string, row: ?string, column: ?string, value: mixed}> by identity
     */
    public function flatten(array $definition, array $data): array
    {
        $out = [];
        $this->visit($this->walker->walk($definition, $data), $out);

        return $out;
    }

    /**
     * @param list<array<string, mixed>>                                                                              $instances
     * @param array<string, array{path: string, block: string, row: ?string, column: ?string, value: mixed}> $out
     */
    private function visit(array $instances, array &$out): void
    {
        foreach ($instances as $instance) {
            $path   = FormDefinition::str($instance['path'] ?? '');
            $scope  = FormDefinition::map($instance['scope'] ?? null) ?? [];
            $fields = FormDefinition::map($scope['fields'] ?? null) ?? [];
            $texts  = FormDefinition::map($scope['texts'] ?? null) ?? [];
            $tables = FormDefinition::map($scope['tables'] ?? null) ?? [];

            foreach (FormDefinition::listOf($instance['blocks'] ?? []) as $entry) {
                $block = FormDefinition::map($entry['block'] ?? null) ?? [];
                $key   = FormDefinition::str($block['key'] ?? '');

                switch ($block['type'] ?? '') {
                    case 'field':
                        if (($block['fieldType'] ?? '') !== 'calculated') {
                            $out[$path . '|' . $key] = ['path' => $path, 'block' => $key, 'row' => null, 'column' => null, 'value' => $fields[$key] ?? null];
                        }
                        break;

                    case 'text':
                        if (($block['mode'] ?? 'fixed') !== 'fixed') {
                            $out[$path . '|' . $key] = ['path' => $path, 'block' => $key, 'row' => null, 'column' => null, 'value' => FormDefinition::map($texts[$key] ?? null)['html'] ?? null];
                        }
                        break;

                    case 'table':
                        $columns = ($block['mode'] ?? '') === 'matrix'
                            ? array_map(static fn (array $r): string => FormDefinition::str($r['_key'] ?? ''), FormScopes::rows(FormDefinition::listOf($instance['chain'] ?? []), FormDefinition::str($block['colTable'] ?? '')))
                            : array_map(static fn (array $c): string => FormDefinition::str($c['key'] ?? ''), array_filter(FormDefinition::listOf($block['columns'] ?? []), static fn (array $c): bool => ($c['fieldType'] ?? '') !== 'calculated'));
                        foreach (FormDefinition::listOf($tables[$key] ?? []) as $row) {
                            $rowKey = FormDefinition::str($row['_key'] ?? '');
                            foreach ($columns as $column) {
                                $out[$path . '|' . $key . '|' . $rowKey . '|' . $column] = ['path' => $path, 'block' => $key, 'row' => $rowKey, 'column' => $column, 'value' => $row[$column] ?? null];
                            }
                        }
                        break;
                }
            }
            $this->visit(FormDefinition::listOf($instance['children'] ?? []), $out);
        }
    }
}
