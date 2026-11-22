<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * The visibility condition of a section or a block: { field: <field key>, op, value? } over the value of a field, looked up in the
 * scopes from the innermost out (a copy sees its own fields and those of the scopes around it).
 *
 *   filled | empty        the field has an answer / has none
 *   true | false          a yes/no field is yes / no (not answered is neither)
 *   eq | neq              the field is / is not `value` (an option key, or a text; for several options: contains / does not)
 *
 * A condition that cannot be understood is a condition that holds: a broken model must not hide parts of a document. A hidden
 * part is not filled in, not validated and not printed, and what was typed in it is kept in case it is shown again.
 */
final class FormConditions
{
    public const array OPERATORS = ['filled', 'empty', 'true', 'false', 'eq', 'neq'];

    /**
     * @param list<array<string, mixed>> $chain scopes, innermost first
     */
    public static function holds(mixed $condition, array $chain): bool
    {
        if (!is_array($condition)) {
            return true;
        }
        $field = FormDefinition::str($condition['field'] ?? '');
        $op    = FormDefinition::str($condition['op'] ?? '');
        if ($field === '' || !in_array($op, self::OPERATORS, true)) {
            return true;
        }

        $value = null;
        foreach ($chain as $scope) {
            $fields = is_array($scope['fields'] ?? null) ? $scope['fields'] : [];
            if (array_key_exists($field, $fields)) {
                $value = $fields[$field];
                break;
            }
        }
        $filled = $value !== null && $value !== '' && $value !== [];
        $wanted = $condition['value'] ?? null;

        return match ($op) {
            'filled' => $filled,
            'empty'  => !$filled,
            'true'   => $value === true,
            'false'  => $value === false,
            'eq'     => self::same($value, $wanted),
            'neq'    => !self::same($value, $wanted),
        };
    }

    private static function same(mixed $value, mixed $wanted): bool
    {
        if (is_array($value)) {
            return in_array($wanted, $value, true);
        }

        return is_scalar($value) && is_scalar($wanted) && (string) $value === (string) $wanted;
    }
}
