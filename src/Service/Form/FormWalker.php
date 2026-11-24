<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * Walks a definition together with its data and yields what is really there: the sections once per copy, with the scope each one
 * reads its values from, which of them are visible, and which block each of them shows. Everybody that has to know "what does this
 * document contain" (the resolver for the web and the PDF, the validator) goes through it, so they agree.
 *
 * Data is scoped: a section that repeats gives each copy a scope of its own (`copies[<sectionKey>][]` inside the scope that holds
 * the section), and the blocks of that section and of its non-repeating descendants are stored in the copy; a nested repeating
 * section opens its own scopes inside it. A section that does not repeat reads and writes the scope of its parent.
 *
 * An instance is:
 *   { section, path, own, copy: ?{index,total,key,source}, origin: ?{table,row}, scope, chain, visible, blocks, children }
 * `own` says the instance is a copy of its own section; `copy` and `origin` are the nearest copy's (inherited by the sections
 * inside it, which is what `{{copy.number}}` and `{{row:…}}` read). `chain` is the scopes from the innermost out (what a reference
 * or a condition looks values up in) and each block is { block, visible }.
 */
final class FormWalker
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>> instances (see the class doc)
     */
    public function walk(array $definition, array $data): array
    {
        $tables = [];
        foreach (FormDefinition::blocks($definition) as $entry) {
            if (($entry['block']['type'] ?? '') === 'table') {
                $tables[FormDefinition::str($entry['block']['key'] ?? '')] = $entry['block'];
            }
        }

        return $this->sections(FormDefinition::listOf($definition['sections'] ?? []), $data, [$data], $tables, '', true, null, null, []);
    }

    /**
     * @param list<array<string, mixed>>          $sections
     * @param array<string, mixed>                $scope
     * @param list<array<string, mixed>>          $chain
     * @param array<string, array<string, mixed>> $tables
     * @param array<string, mixed>|null           $inheritedCopy
     * @param array<string, mixed>|null           $inheritedOrigin
     * @param list<array{0: string, 1: string}>   $address
     *
     * @return list<array<string, mixed>>
     */
    private function sections(array $sections, array $scope, array $chain, array $tables, string $path, bool $parentVisible, ?array $inheritedCopy, ?array $inheritedOrigin, array $address): array
    {
        $out = [];
        foreach ($sections as $section) {
            $key     = FormDefinition::str($section['key'] ?? '');
            $visible = $parentVisible && FormConditions::holds($section['visibleIf'] ?? null, $chain);
            $repeat  = is_array($section['repeat'] ?? null) ? $section['repeat'] : null;

            if ($repeat === null) {
                $out[] = $this->instance($section, $path . $key, false, $inheritedCopy, $inheritedOrigin, $scope, $chain, $visible, $tables, $address);
                continue;
            }

            $copies = FormDefinition::listOf(is_array($scope['copies'] ?? null) && is_array($scope['copies'][$key] ?? null) ? $scope['copies'][$key] : []);
            $table  = ($repeat['mode'] ?? '') === 'table' ? ($tables[FormDefinition::str($repeat['table'] ?? '')] ?? null) : null;
            $total  = count($copies);
            // A section repeated by the items of a list: each copy knows the name of its item (`{{…|copy.label}}`).
            $labels = [];
            foreach (FormDefinition::listOf(($repeat['mode'] ?? '') === 'list' ? ($repeat['items'] ?? []) : []) as $item) {
                $labels[FormDefinition::str($item['key'] ?? '')] = FormDefinition::str($item['label'] ?? '');
            }
            foreach ($copies as $index => $copy) {
                $copyKey = FormDefinition::str($copy['_key'] ?? '');
                $origin  = null;
                if ($table !== null) {
                    $row = $this->rowOf($chain, FormDefinition::str($table['key'] ?? ''), FormDefinition::str($copy['_source'] ?? ''));
                    if ($row !== null) {
                        $origin = ['table' => $table, 'row' => $row];
                    }
                }
                $info  = ['index' => $index + 1, 'total' => $total, 'key' => $copyKey, 'source' => isset($copy['_source']) ? FormDefinition::str($copy['_source']) : null, 'title' => FormDefinition::str($section['title'] ?? ''), 'label' => $labels[$copyKey] ?? ''];
                $out[] = $this->instance($section, $path . $key . ':' . $copyKey, true, $info, $origin, $copy, [$copy, ...$chain], $visible, $tables, [...$address, [$key, $copyKey]]);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>                $section
     * @param array<string, mixed>|null           $copy
     * @param array<string, mixed>|null           $origin
     * @param array<string, mixed>                $scope
     * @param list<array<string, mixed>>          $chain
     * @param array<string, array<string, mixed>> $tables
     * @param list<array{0: string, 1: string}>   $address
     *
     * @return array<string, mixed>
     */
    private function instance(array $section, string $path, bool $own, ?array $copy, ?array $origin, array $scope, array $chain, bool $visible, array $tables, array $address): array
    {
        $blocks = [];
        foreach (FormDefinition::listOf($section['blocks'] ?? []) as $block) {
            $blocks[] = ['block' => $block, 'visible' => $visible && ($block['foot'] ?? false) !== true && FormConditions::holds($block['visibleIf'] ?? null, $chain)];
        }

        return [
            'section'  => $section,
            'path'     => $path,
            'own'      => $own,
            'copy'     => $copy,
            'origin'   => $origin,
            'scope'    => $scope,
            'chain'    => $chain,
            'visible'  => $visible,
            'address'  => $address,
            'blocks'   => $blocks,
            'children' => $this->sections(FormDefinition::listOf($section['children'] ?? []), $scope, $chain, $tables, $path . '/', $visible, $copy, $origin, $address),
        ];
    }

    /**
     * The row of a table, looked up in the scopes from the innermost out.
     *
     * @param list<array<string, mixed>> $chain
     *
     * @return array<string, mixed>|null
     */
    private function rowOf(array $chain, string $tableKey, string $rowKey): ?array
    {
        foreach ($chain as $scope) {
            $tablesData = is_array($scope['tables'] ?? null) ? $scope['tables'] : [];
            foreach (FormDefinition::listOf($tablesData[$tableKey] ?? []) as $row) {
                if (($row['_key'] ?? null) === $rowKey) {
                    return $row;
                }
            }
        }

        return null;
    }
}
