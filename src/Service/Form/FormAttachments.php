<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * The values of the fields «Adjuntos» wherever they are in the data (the document's own fields and those of every copy of a repeating
 * section, nested), and the attachments of a resolved document in the order it shows them (the order of their «Anexo N»).
 */
final class FormAttachments
{
    /**
     * The data with the value of every attachment field passed through `$fn(list $files, array $block): ?list` (null removes it).
     *
     * @param array<string, mixed>                                                         $definition
     * @param array<string, mixed>                                                         $data
     * @param callable(list<array<string, mixed>>, array<string, mixed>): (list<array<string, mixed>>|null) $fn
     *
     * @return array<string, mixed>
     */
    public static function map(array $definition, array $data, callable $fn): array
    {
        return self::walk(FormDefinition::listOf($definition['sections'] ?? []), $data, $fn);
    }

    /**
     * The ids of every file the data points at.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function ids(array $definition, array $data): array
    {
        $ids = [];
        self::map($definition, $data, static function (array $files) use (&$ids): array {
            foreach ($files as $file) {
                $ids[] = FormDefinition::str($file['id'] ?? '');
            }

            return $files;
        });

        return array_values(array_unique(array_filter($ids, static fn (string $id): bool => $id !== '')));
    }

    /**
     * The attachments of a resolved document (FormResolver::resolve()), numbered as it shows them.
     *
     * @param list<array<string, mixed>> $sections
     *
     * @return list<array{n: int, id: string, name: string, size: int, mime: string, image: bool, printPages: bool}>
     */
    public static function fromResolved(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            foreach (FormDefinition::listOf($section['blocks'] ?? []) as $block) {
                foreach (FormDefinition::listOf($block['items'] ?? []) as $item) {
                    foreach (FormDefinition::listOf($item['attachments'] ?? []) as $file) {
                        /** @var array{n: int, id: string, name: string, size: int, mime: string, image: bool, printPages: bool} $file */
                        $out[] = $file;
                    }
                }
            }
            array_push($out, ...self::fromResolved(FormDefinition::listOf($section['children'] ?? [])));
        }

        return $out;
    }

    /** Whether a file of this type is shown as a picture in the document (the rest are listed). */
    public static function isImage(string $mime): bool
    {
        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
    }

    /** «230 KB», «1,4 MB». */
    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes < 1024        => $bytes . ' B',
            $bytes < 1024 * 1024 => max(1, (int) round($bytes / 1024)) . ' KB',
            default              => str_replace('.', ',', (string) round($bytes / 1024 / 1024, 1)) . ' MB',
        };
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @param array<string, mixed>       $scope
     * @param callable                   $fn
     *
     * @return array<string, mixed>
     */
    private static function walk(array $sections, array $scope, callable $fn): array
    {
        [$blocks, $repeating] = self::collect($sections);
        $fields               = is_array($scope['fields'] ?? null) ? $scope['fields'] : [];
        foreach ($blocks as $block) {
            $key = FormDefinition::str($block['key'] ?? '');
            if (($block['type'] ?? '') !== 'field' || ($block['fieldType'] ?? '') !== 'attachment' || !is_array($fields[$key] ?? null)) {
                continue;
            }
            $files = $fn(FormDefinition::listOf($fields[$key]), $block);
            if ($files === null || $files === []) {
                unset($fields[$key]);
            } else {
                $fields[$key] = $files;
            }
        }
        if (array_key_exists('fields', $scope)) {
            $scope['fields'] = $fields;
        }

        $copies = is_array($scope['copies'] ?? null) ? $scope['copies'] : [];
        foreach ($repeating as $section) {
            $key = FormDefinition::str($section['key'] ?? '');
            if (!is_array($copies[$key] ?? null)) {
                continue;
            }
            $inner = ['repeat' => null] + $section;
            foreach ($copies[$key] as $i => $copy) {
                $copyScope = FormDefinition::map($copy);
                if ($copyScope !== null) {
                    $copies[$key][$i] = self::walk([$inner], $copyScope, $fn);
                }
            }
        }
        if (array_key_exists('copies', $scope)) {
            $scope['copies'] = $copies;
        }

        return $scope;
    }

    /**
     * The blocks of a scope (its sections and their children that do not repeat) and the sections that repeat in it (each a scope of
     * its own) — the same split as FormDataNormalizer::collect().
     *
     * @param list<array<string, mixed>> $sections
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function collect(array $sections): array
    {
        $blocks = $repeating = [];
        foreach ($sections as $section) {
            if (is_array($section['repeat'] ?? null)) {
                $repeating[] = $section;
                continue;
            }
            array_push($blocks, ...FormDefinition::listOf($section['blocks'] ?? []));
            [$childBlocks, $childRepeating] = self::collect(FormDefinition::listOf($section['children'] ?? []));
            array_push($blocks, ...$childBlocks);
            array_push($repeating, ...$childRepeating);
        }

        return [$blocks, $repeating];
    }
}
