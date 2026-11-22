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
}
