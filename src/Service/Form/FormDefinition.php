<?php

declare(strict_types=1);

namespace App\Service\Form;

use Symfony\Component\Uid\Uuid;

/**
 * The shape of a document model's definition (a JSON-able array) and the few things everybody needs to know about it.
 *
 *   { "version": 1, "sections": [ Section ] }
 *   Section = { key, title, numbered, help, blocks: [ Block ], children: [ Section ] }
 *   Block   = text  { key, type: "text", mode: fixed|starter|fill, label, html, required, help }
 *           | field { key, type: "field", fieldType, name, label, required, help, options?, min?, max?, unit? }
 *           | table { key, type: "table", name, label, mode: free|fixed|matrix|counted, columns: [ Column ], rows: [ { key, label } ],
 *                     rowHeader, minRows, maxRows, rowsField (counted: the key of a number field), help }
 *   Column  = { key, name, label, fieldType, required, options?, min?, max?, unit? }
 *
 * Every section, block, column, fixed row and option has a `key` (a UUID) that is stable for ever across the revisions of a
 * model: filled data, links, indicators and the update of a document to a newer revision all hang on keys, never on position or
 * title. Nothing in this class reads the database.
 *
 * @phpstan-type Definition array<string, mixed>
 */
final class FormDefinition
{
    public const int VERSION = 1;

    /**
     * `calculated` (from a formula) and `relation` (picks rows of another table; table columns only) are not typed in as such. `scale` is
     * a rating from 1 to `points` (a whole number), with optional words for both ends (`lowLabel`, `highLabel`): a survey answer.
     */
    public const array FIELD_TYPES = ['short', 'long', 'number', 'date', 'time', 'bool', 'choice', 'multichoice', 'calculated', 'relation', 'scale'];

    /** The points a rating scale may have (and the ones it gets when the model says none). */
    public const int SCALE_MIN_POINTS     = 3;
    public const int SCALE_MAX_POINTS     = 10;
    public const int SCALE_DEFAULT_POINTS = 5;

    /** The types whose value is a number (formulas, totals at the foot, the summary of a row, indicators that average). */
    public const array NUMERIC_TYPES = ['number', 'calculated', 'scale'];

    public const array TEXT_MODES = ['fixed', 'starter', 'fill'];

    /**
     * `matrix` is a double-entry table: its rows are those of one table and its columns those of another. `counted` has as many
     * rows as a number field says (`rowsField`, capped by `maxRows`): nobody adds or removes them, they follow the number.
     */
    public const array TABLE_MODES = ['free', 'fixed', 'matrix', 'counted'];

    /**
     * The largest autosave the server reads (bytes of JSON), for a model's draft and for a document's. Far above any real document (1000
     * rows of a table are some hundreds of KB), it stops a runaway or hostile request before it is decoded, normalized and diffed.
     */
    public const int MAX_REQUEST_BYTES = 4 * 1024 * 1024;

    /** The most rows a counted table gets, whatever the number says (and the cap used when the model sets none). */
    public const int MAX_COUNTED_ROWS = 100;

    public const array TOTALS = ['', 'sum', 'avg', 'min', 'max', 'count', 'custom'];

    /** @return array<string, mixed> */
    public static function blank(): array
    {
        return ['version' => self::VERSION, 'sections' => []];
    }

    public static function newKey(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    /**
     * Every block of the definition in reading order, with the section it is in.
     *
     * @param array<string, mixed> $definition
     *
     * @return \Generator<int, array{block: array<string, mixed>, section: array<string, mixed>}>
     */
    public static function blocks(array $definition): \Generator
    {
        foreach (self::sectionsOf($definition['sections'] ?? []) as $section) {
            foreach (self::listOf($section['blocks'] ?? []) as $block) {
                yield ['block' => $block, 'section' => $section];
            }
        }
    }

    /**
     * Every section, depth first, in reading order.
     *
     * @param mixed $sections
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function sectionsOf(mixed $sections): \Generator
    {
        foreach (self::listOf($sections) as $section) {
            yield $section;
            yield from self::sectionsOf($section['children'] ?? []);
        }
    }

    /**
     * Block by key, or null.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>|null
     */
    public static function findBlock(array $definition, string $key): ?array
    {
        foreach (self::blocks($definition) as $entry) {
            if (($entry['block']['key'] ?? null) === $key) {
                return $entry['block'];
            }
        }

        return null;
    }

    /**
     * The keys of everything that has a key (sections, blocks, columns, fixed rows, options): to check they are unique and to
     * compare two revisions.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    public static function allKeys(array $definition): array
    {
        $keys = [];
        foreach (self::sectionsOf($definition['sections'] ?? []) as $section) {
            $keys[] = self::str($section['key'] ?? '');
            foreach (self::listOf($section['blocks'] ?? []) as $block) {
                $keys[] = self::str($block['key'] ?? '');
                foreach (self::listOf($block['columns'] ?? []) as $column) {
                    $keys[] = self::str($column['key'] ?? '');
                    foreach (self::listOf($column['options'] ?? []) as $option) {
                        $keys[] = self::str($option['key'] ?? '');
                    }
                }
                foreach (self::listOf($block['rows'] ?? []) as $row) {
                    $keys[] = self::str($row['key'] ?? '');
                }
                foreach (self::listOf($block['options'] ?? []) as $option) {
                    $keys[] = self::str($option['key'] ?? '');
                }
            }
        }

        return $keys;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * A map (string keys) out of whatever came in, or null when it is not an array.
     *
     * @return array<string, mixed>|null
     */
    public static function map(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /** An integer out of whatever came in (JSON numbers, numeric strings); 0 for anything else. */
    public static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    public static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
