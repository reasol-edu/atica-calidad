<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * Looking things up in a chain of scopes (the scope of a copy first, then the ones around it): the value of a field, the rows of a
 * table, the copies of a repeating section. The first scope that has the thing wins.
 */
final class FormScopes
{
    /**
     * @param list<array<string, mixed>> $chain
     */
    public static function field(array $chain, string $key): mixed
    {
        foreach ($chain as $scope) {
            if (is_array($scope['fields'] ?? null) && array_key_exists($key, $scope['fields'])) {
                return $scope['fields'][$key];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $chain
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $chain, string $table): array
    {
        foreach ($chain as $scope) {
            if (is_array($scope['tables'] ?? null) && array_key_exists($table, $scope['tables'])) {
                return FormDefinition::listOf($scope['tables'][$table]);
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $chain
     *
     * @return list<array<string, mixed>>
     */
    public static function copies(array $chain, string $section): array
    {
        foreach ($chain as $scope) {
            if (is_array($scope['copies'] ?? null) && array_key_exists($section, $scope['copies'])) {
                return FormDefinition::listOf($scope['copies'][$section]);
            }
        }

        return [];
    }

    /**
     * The data with the scope at an address ([sectionKey, copyKey] pairs from the root, as FormWalker gives them) replaced by what
     * $change makes of it.
     *
     * @param array<string, mixed>                                 $data
     * @param list<array{0: string, 1: string}>                    $address
     * @param callable(array<string, mixed>): array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    public static function update(array $data, array $address, callable $change): array
    {
        if ($address === []) {
            return $change($data);
        }
        [$section, $copy] = $address[0];

        $all    = is_array($data['copies'] ?? null) ? $data['copies'] : [];
        $copies = FormDefinition::listOf($all[$section] ?? []);
        foreach ($copies as $i => $candidate) {
            if (($candidate['_key'] ?? null) === $copy) {
                $copies[$i] = self::update($candidate, array_slice($address, 1), $change);
                break;
            }
        }
        $all[$section]  = $copies;
        $data['copies'] = $all;

        return $data;
    }
}
