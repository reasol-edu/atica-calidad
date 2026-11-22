<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * The fields and the tables of a definition by key: what a reference, a formula, a relation or a double-entry table needs to find
 * the thing it points at without walking the definition again.
 */
final class FormIndex
{
    /**
     * @param array<string, array<string, mixed>> $fields field blocks by key
     * @param array<string, array<string, mixed>> $tables table blocks by key
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $tables,
    ) {}

    /** @param array<string, mixed> $definition */
    public static function of(array $definition): self
    {
        $fields = $tables = [];
        foreach (FormDefinition::blocks($definition) as $entry) {
            $key = FormDefinition::str($entry['block']['key'] ?? '');
            if (($entry['block']['type'] ?? '') === 'field') {
                $fields[$key] = $entry['block'];
            } elseif (($entry['block']['type'] ?? '') === 'table') {
                $tables[$key] = $entry['block'];
            }
        }

        return new self($fields, $tables);
    }

    /** @return array<string, mixed>|null the column of a table (its spec), or null */
    public function column(string $table, string $column): ?array
    {
        foreach (FormDefinition::listOf($this->tables[$table]['columns'] ?? []) as $spec) {
            if (($spec['key'] ?? null) === $column) {
                return $spec;
            }
        }

        return null;
    }
}
