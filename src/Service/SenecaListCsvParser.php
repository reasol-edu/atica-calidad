<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\SenecaImportNode;
use App\Model\SenecaListImportKind;

/**
 * Turns a Séneca "Relación de unidades" (groups) or "Materias y grupos" (subjects) CSV export
 * into the list-item tree a Responsabilidades › Listas import wants to end up with, ready to
 * diff against what already exists (see SenecaListImporter). Only the column(s) that name the
 * tree are read — group size, tutor, teacher, course label, etc. are exported by Séneca alongside
 * but play no part in the list itself.
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

    /**
     * The desired top-level nodes under the import's root — one per group. A "subjects" import
     * nests each row's "Materia" under its "Unidad" group node; a "groups" import returns
     * childless group nodes. Rows missing a required value are skipped, same as any other CSV
     * import in this app.
     *
     * @return SenecaImportNode[]
     */
    public function buildTree(string $csvContent, SenecaListImportKind $kind): array
    {
        $rows = $this->csvReader->parse($csvContent)['rows'];

        /** @var array<string, SenecaImportNode> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $groupName = trim($row['Unidad'] ?? '');
            if ($groupName === '') {
                continue;
            }

            $group = $groups[$groupName] ??= new SenecaImportNode($groupName);

            if ($kind === SenecaListImportKind::Subjects) {
                $subjectName = trim($row['Materia'] ?? '');
                if ($subjectName === '') {
                    continue;
                }
                $group->childNamed($subjectName);
            }
        }

        return array_values($groups);
    }
}
