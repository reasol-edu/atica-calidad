<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * Fills in the calculated fields and the calculated columns of a document's data from their formulas. The result is stored with the
 * data (frozen with each delivered revision, and available to indicators), and is never typed: the normalizer drops whatever the
 * browser sends for them.
 *
 * A formula can read another calculated value, so the document is calculated in a few passes (each reads the values the pass before
 * left), enough for the chains a form really has; a cycle is rejected when the model is saved (FormDefinitionNormalizer::problems()).
 */
final class FormCalculator
{
    private const int PASSES = 4;

    public function __construct(
        private readonly FormWalker $walker,
        private readonly FormEvaluator $evaluator,
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function apply(array $definition, array $data): array
    {
        $index = FormIndex::of($definition);
        if (!$this->hasFormulas($index)) {
            return $data;
        }

        for ($pass = 0; $pass < self::PASSES; ++$pass) {
            $before = $data;
            $data   = $this->calculate($this->walker->walk($definition, $data), $index, $data);
            if ($data === $before) {
                break;
            }
        }

        return $data;
    }

    private function hasFormulas(FormIndex $index): bool
    {
        foreach ($index->fields as $field) {
            if (($field['fieldType'] ?? '') === 'calculated') {
                return true;
            }
        }
        foreach ($index->tables as $table) {
            foreach (FormDefinition::listOf($table['columns'] ?? []) as $column) {
                if (($column['fieldType'] ?? '') === 'calculated') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $instances
     * @param array<string, mixed>       $data
     *
     * @return array<string, mixed>
     */
    private function calculate(array $instances, FormIndex $index, array $data): array
    {
        foreach ($instances as $instance) {
            $chain  = FormDefinition::listOf($instance['chain'] ?? []);
            $origin = FormDefinition::map($instance['origin'] ?? null);
            $blocks = FormDefinition::listOf(array_map(static fn (array $e): mixed => $e['block'] ?? null, FormDefinition::listOf($instance['blocks'] ?? [])));
            $address = [];
            foreach (is_array($instance['address'] ?? null) ? $instance['address'] : [] as $pair) {
                $pair      = is_array($pair) ? array_values($pair) : [];
                $address[] = [FormDefinition::str($pair[0] ?? ''), FormDefinition::str($pair[1] ?? '')];
            }

            $data = FormScopes::update($data, $address, fn (array $scope): array => $this->calculateScope($scope, $blocks, $index, $chain, $origin));
        }

        foreach (FormDefinition::listOf($instances) as $instance) {
            $data = $this->calculate(FormDefinition::listOf($instance['children'] ?? []), $index, $data);
        }

        return $data;
    }

    /**
     * @param array<string, mixed>       $scope
     * @param list<array<string, mixed>> $blocks
     * @param list<array<string, mixed>> $chain
     * @param array<string, mixed>|null  $origin
     *
     * @return array<string, mixed>
     */
    private function calculateScope(array $scope, array $blocks, FormIndex $index, array $chain, ?array $origin): array
    {
        $fields = is_array($scope['fields'] ?? null) ? $scope['fields'] : [];
        $tables = is_array($scope['tables'] ?? null) ? $scope['tables'] : [];

        foreach ($blocks as $block) {
            $key = FormDefinition::str($block['key'] ?? '');

            if (($block['type'] ?? '') === 'field' && ($block['fieldType'] ?? '') === 'calculated') {
                $value = $this->value(FormDefinition::str($block['formula'] ?? ''), $block, $index, $chain, null, $origin);
                if ($value === null) {
                    unset($fields[$key]);
                } else {
                    $fields[$key] = $value;
                }
            }

            if (($block['type'] ?? '') === 'table') {
                $rows = FormDefinition::listOf($tables[$key] ?? []);
                foreach (FormDefinition::listOf($block['columns'] ?? []) as $column) {
                    if (($column['fieldType'] ?? '') !== 'calculated') {
                        continue;
                    }
                    $columnKey = FormDefinition::str($column['key'] ?? '');
                    foreach ($rows as $i => $row) {
                        $value = $this->value(FormDefinition::str($column['formula'] ?? ''), $column, $index, $chain, $row, $origin);
                        if ($value === null) {
                            unset($rows[$i][$columnKey]);
                        } else {
                            $rows[$i][$columnKey] = $value;
                        }
                    }
                }
                if (isset($tables[$key])) {
                    $tables[$key] = $rows;
                }
            }
        }
        $scope['fields'] = $fields;
        $scope['tables'] = $tables;

        return $scope;
    }

    /**
     * @param array<string, mixed>       $spec
     * @param list<array<string, mixed>> $chain
     * @param array<string, mixed>|null  $row
     * @param array<string, mixed>|null  $origin
     */
    private function value(string $formula, array $spec, FormIndex $index, array $chain, ?array $row, ?array $origin): string|int|float|bool|null
    {
        $result = $this->evaluator->run($formula, $index, $chain, $row, $origin);
        if (is_float($result) || is_int($result)) {
            $decimals = min(6, max(0, FormDefinition::int($spec['decimals'] ?? 2)));
            $result   = round((float) $result, $decimals);

            return $decimals === 0 ? (int) $result : $result;
        }

        return is_string($result) || is_bool($result) ? $result : null;
    }
}
