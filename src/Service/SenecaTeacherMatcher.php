<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\SenecaImportNode;
use App\Model\SenecaTeacherMatch;
use App\Repository\TeacherRepository;

/**
 * Finds the teachers named in the «Profesor/a» column of a Séneca «Materias y grupos» export («Apellidos, Nombre», split at the first
 * «, », as GestConv+ imports the same file). Matched against the centre's active course in memory — one query, and teachers are
 * shared by every centre of the platform, so a namesake elsewhere must not win —, without minding case or repeated spaces. Only
 * the names not found there are looked for on the whole platform, to tell «not found» from «not in the course».
 */
final class SenecaTeacherMatcher
{
    public function __construct(
        private readonly TeacherRepository $teachers,
    ) {}

    /** @param SenecaImportNode[] $desired the tree the parser built */
    public function match(EducationalCentre $centre, array $desired): SenecaTeacherMatch
    {
        $names = [];
        $this->collectNames($desired, $names);

        $year = $centre->getActiveAcademicYear();
        /** @var array<string, Teacher> $course */
        $course = [];
        foreach ($year !== null ? $this->teachers->findByAcademicYearOrderedByName($year) : [] as $teacher) {
            if ($teacher->isActive()) {
                $course[self::key($teacher->getName()->getLastName(), $teacher->getName()->getFirstName())] ??= $teacher;
            }
        }

        $found = $notFound = $notInCourse = [];
        foreach (array_keys($names) as $name) {
            $parts = explode(', ', $name, 2);
            $last  = trim($parts[0]);
            $first = trim($parts[1] ?? '');
            if ($last === '' || $first === '') {
                $notFound[] = $name;

                continue;
            }

            $teacher = $course[self::key($last, $first)] ?? null;
            if ($teacher !== null) {
                $found[$name] = $teacher;
            } elseif ($this->teachers->findByFullName($first, $last) !== null) {
                $notInCourse[] = $name;
            } else {
                $notFound[] = $name;
            }
        }

        return new SenecaTeacherMatch($found, $notFound, $notInCourse);
    }

    /**
     * @param SenecaImportNode[]  $nodes
     * @param array<string, true> $names
     */
    private function collectNames(array $nodes, array &$names): void
    {
        foreach ($nodes as $node) {
            foreach ($node->getTeacherNames() as $name) {
                $names[$name] = true;
            }
            $this->collectNames($node->getChildren(), $names);
        }
    }

    private static function key(string $lastName, string $firstName): string
    {
        $normal = static fn (string $s): string => mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($s)));

        return $normal($lastName) . '|' . $normal($firstName);
    }
}
