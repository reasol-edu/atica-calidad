<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\SenecaImportNode;
use App\Model\SenecaListImportKind;
use App\Model\SenecaSubjectsLayout;

/**
 * Turns a Séneca "Relación de unidades" (groups) or "Materias y grupos" (subjects) CSV export
 * into the list-item tree a Responsabilidades › Catálogo del centro import wants to end up with, ready to
 * diff against what already exists (see SenecaListImporter). Only the column(s) that name the
 * tree are read, plus «Profesor/a» for the teachers of a subjects import — group size, tutor,
 * course label, etc. are exported by Séneca alongside but play no part in the list itself.
 */
final class SenecaListCsvParser
{
    public function __construct(
        private readonly CsvReader $csvReader,
    ) {}

    /** @return list<string> the CSV columns this kind of import requires, in the order to report a missing one */
    public function requiredColumns(SenecaListImportKind $kind): array
    {
        return match ($kind) {
            SenecaListImportKind::Groups   => ['Unidad'],
            SenecaListImportKind::Subjects => ['Unidad', 'Materia'],
        };
    }

    public function missingColumn(string $csvContent, SenecaListImportKind $kind): ?string
    {
        return $this->csvReader->findMissingColumn(
            $this->csvReader->parse($csvContent)['headers'],
            $this->requiredColumns($kind),
        );
    }

    /** Whether $csvContent has no usable header row at all — an empty or non-CSV upload. */
    public function isEmpty(string $csvContent): bool
    {
        return $this->csvReader->parse($csvContent)['headers'] === [];
    }

    /** The column of a «Materias y grupos» export with the teacher of each row («Apellidos, Nombre»). */
    public const string TEACHER_COLUMN = 'Profesor/a';

    public function hasTeacherColumn(string $csvContent): bool
    {
        return in_array(self::TEACHER_COLUMN, $this->csvReader->parse($csvContent)['headers'], true);
    }

    /**
     * The distinct values of a column, lower-cased (as the import compares names), for SenecaListImporter::detectLayout().
     *
     * @return array<string, true>
     */
    public function distinctValues(string $csvContent, string $column): array
    {
        $values = [];
        foreach ($this->csvReader->parse($csvContent)['rows'] as $row) {
            $value = trim($row[$column] ?? '');
            if ($value !== '') {
                $values[mb_strtolower($value)] = true;
            }
        }

        return $values;
    }

    /**
     * The desired top-level nodes under the import's root — one per group. A "subjects" import
     * nests each row's "Materia" under its "Unidad" group node (or, by subject, each "Unidad"
     * under its "Materia"); a "groups" import returns childless group nodes. Rows missing a
     * required value are skipped, same as any other CSV import in this app.
     *
     * With a «Profesor/a» column, the node of each «Unidad»–«Materia» pair of a subjects import
     * gathers the teachers of its rows (several in a desdoble).
     *
     * @return SenecaImportNode[]
     */
    public function buildTree(string $csvContent, SenecaListImportKind $kind, SenecaSubjectsLayout $layout = SenecaSubjectsLayout::ByGroup): array
    {
        $parsed      = $this->csvReader->parse($csvContent);
        $withTeacher = in_array(self::TEACHER_COLUMN, $parsed['headers'], true);

        /** @var array<string, SenecaImportNode> $top */
        $top = [];

        foreach ($parsed['rows'] as $row) {
            $groupName = trim($row['Unidad'] ?? '');
            if ($groupName === '') {
                continue;
            }

            if ($kind === SenecaListImportKind::Groups) {
                $top[$groupName] ??= new SenecaImportNode($groupName);

                continue;
            }

            $subjectName = trim($row['Materia'] ?? '');
            if ($subjectName === '') {
                // By group, the group is still in the list; by subject, there is nothing to hang it from.
                if ($layout === SenecaSubjectsLayout::ByGroup) {
                    $top[$groupName] ??= new SenecaImportNode($groupName);
                }

                continue;
            }

            [$upper, $lower] = $layout === SenecaSubjectsLayout::BySubject ? [$subjectName, $groupName] : [$groupName, $subjectName];
            $leaf = ($top[$upper] ??= new SenecaImportNode($upper))->childNamed($lower);
            if ($withTeacher) {
                $leaf->addTeacherName((string) preg_replace('/\s+/u', ' ', trim($row[self::TEACHER_COLUMN] ?? '')));
            }
        }

        return array_values($top);
    }
}
