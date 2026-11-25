<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Teacher;

/**
 * The teachers a Séneca subjects import found for the names of its «Profesor/a» column (see SenecaTeacherMatcher): the ones in the
 * centre's active course, and the names left out — not found at all, or found but outside the course.
 */
final class SenecaTeacherMatch
{
    /**
     * @param array<string, Teacher> $found       by the name as the file writes it
     * @param list<string>           $notFound    no teacher with that name (or not written «Apellidos, Nombre»)
     * @param list<string>           $notInCourse a teacher with that name exists, but is not active in the active course
     */
    public function __construct(
        public readonly array $found,
        public readonly array $notFound,
        public readonly array $notInCourse,
    ) {}

    /**
     * The teachers of these names that were found, once each (two spellings of the same teacher count once).
     *
     * @param  list<string>  $names
     * @return list<Teacher>
     */
    public function teachersFor(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $teacher = $this->found[$name] ?? null;
            if ($teacher !== null) {
                $result[$teacher->getId()->toRfc4122()] = $teacher;
            }
        }

        return array_values($result);
    }

    public function unresolvedCount(): int
    {
        return count($this->notFound) + count($this->notInCourse);
    }
}
