<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * Evaluates an expression (FormExpression) where it is used: it knows what each kind of reference means over the scopes of a
 * document — see FormExpression for the references. Formulas are parsed once per source text.
 */
final class FormEvaluator
{
    /** @var array<string, array<string, mixed>|null> */
    private array $trees = [];

    /**
     * @param list<array<string, mixed>> $chain  the scopes, the one the expression lives in first
     * @param array<string, mixed>|null  $row    the row being calculated, for `[r:COL]`
     * @param array<string, mixed>|null  $origin { table, row } the row a copy comes from, for `[o:COL]`
     */
    public function run(string $source, FormIndex $index, array $chain, ?array $row = null, ?array $origin = null): mixed
    {
        if (!array_key_exists($source, $this->trees)) {
            $this->trees[$source] = FormExpression::parse($source)['ast'];
        }
        $ast = $this->trees[$source];
        if ($ast === null) {
            return null;
        }

        return FormExpression::evaluate($ast, fn (string $ref): mixed => $this->reference($ref, $index, $chain, $row, $origin));
    }

    /**
     * @param list<array<string, mixed>> $chain
     * @param array<string, mixed>|null  $row
     * @param array<string, mixed>|null  $origin
     */
    private function reference(string $ref, FormIndex $index, array $chain, ?array $row, ?array $origin): mixed
    {
        [$kind, $rest] = array_pad(explode(':', $ref, 2), 2, '');

        switch ($kind) {
            case 'f':
                return FormScopes::field($chain, $rest);

            case 't':
                [$table, $column] = array_pad(explode('.', $rest, 2), 2, '');

                return $this->column(FormScopes::rows($chain, $table), $column);

            case 'r':
                return $row[$rest] ?? null;

            case 'o':
                return is_array($origin['row'] ?? null) ? ($origin['row'][$rest] ?? null) : null;

            case 'c':
                [$section, $tail] = array_pad(explode('.', $rest, 2), 2, '');
                $out              = [];
                foreach (FormScopes::copies($chain, $section) as $copy) {
                    if (str_starts_with($tail, 'f:')) {
                        $out[] = is_array($copy['fields'] ?? null) ? ($copy['fields'][substr($tail, 2)] ?? null) : null;
                    } elseif (str_starts_with($tail, 't:')) {
                        [$table, $column] = array_pad(explode('.', substr($tail, 2), 2), 2, '');
                        array_push($out, ...$this->column(FormDefinition::listOf(is_array($copy['tables'] ?? null) ? ($copy['tables'][$table] ?? []) : []), $column));
                    }
                }

                return $out;

            case 'm':
                return $this->matrix($rest, $index, $chain);
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<mixed>
     */
    private function column(array $rows, string $column): array
    {
        return array_map(static fn (array $row): mixed => $row[$column] ?? null, $rows);
    }

    /**
     * The cells of a double-entry table, or (`.rows`, `.cols`) the sum of each row or of each column.
     *
     * @param list<array<string, mixed>> $chain
     *
     * @return list<mixed>
     */
    private function matrix(string $path, FormIndex $index, array $chain): array
    {
        [$key, $what] = array_pad(explode('.', $path, 2), 2, '');
        $table        = $index->tables[$key] ?? null;
        if ($table === null) {
            return [];
        }
        $columns = array_map(static fn (array $r): string => FormDefinition::str($r['_key'] ?? ''), FormScopes::rows($chain, FormDefinition::str($table['colTable'] ?? '')));
        $rows    = FormScopes::rows($chain, $key);

        $cells = $rowSums = [];
        $colSums = array_fill_keys($columns, 0.0);
        foreach ($rows as $row) {
            $sum = 0.0;
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                $cells[] = $value;
                if (is_numeric($value)) {
                    $sum += (float) $value;
                    $colSums[$col] += (float) $value;
                }
            }
            $rowSums[] = $sum;
        }

        return match ($what) {
            'rows'  => $rowSums,
            'cols'  => array_values($colSums),
            default => $cells,
        };
    }
}
