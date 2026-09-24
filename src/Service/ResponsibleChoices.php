<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EducationalCentre;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Repository\SpecificProfileRepository;
use App\Repository\TeacherRepository;

/**
 * The "Responsable" picker of the plan action and indicator forms: "Yo", a teacher of the active
 * year ("t:<id>") or a profile of the centre ("p:<id>"), anyone holding it.
 */
final class ResponsibleChoices
{
    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly SpecificProfileRepository $profiles,
    ) {}

    /**
     * The active year's active teachers, by name — plus $current first when it's no longer among
     * them, so editing doesn't lose it.
     *
     * @return list<Teacher>
     */
    public function teachers(EducationalCentre $centre, ?Teacher $current = null): array
    {
        $year     = $centre->getActiveAcademicYear();
        $teachers = $year === null ? [] : array_values(array_filter($this->teachers->findByAcademicYearOrderedByName($year), static fn (Teacher $t): bool => $t->isActive()));
        if ($current !== null && array_filter($teachers, static fn (Teacher $t): bool => $t->getId()->equals($current->getId())) === []) {
            array_unshift($teachers, $current);
        }

        return $teachers;
    }

    /** @return list<SpecificProfile> the centre's active profiles */
    public function profiles(EducationalCentre $centre): array
    {
        return array_values(array_filter($this->profiles->findByCentre($centre), static fn (SpecificProfile $p): bool => $p->isActive()));
    }

    /** The picker's value for a teacher or a profile ('' for neither). */
    public static function value(?Teacher $teacher, ?SpecificProfile $profile): string
    {
        return match (true) {
            $teacher !== null => 't:' . $teacher->getId()->toRfc4122(),
            $profile !== null => 'p:' . $profile->getId()->toRfc4122(),
            default           => '',
        };
    }

    /**
     * What $value picks: [teacher, profile], or null when it's not a valid choice. '' is "Yo": $me.
     * $current (the one it had, when editing) is always valid.
     *
     * @return array{0: ?Teacher, 1: ?SpecificProfile}|null
     */
    public function resolve(string $value, EducationalCentre $centre, Teacher $me, ?Teacher $current = null): ?array
    {
        if ($value === '') {
            return [$me, null];
        }
        if (str_starts_with($value, 't:')) {
            $id = substr($value, 2);
            foreach ($this->teachers($centre, $current) as $teacher) {
                if ($teacher->getId()->toRfc4122() === $id) {
                    return [$teacher, null];
                }
            }

            return null;
        }
        if (str_starts_with($value, 'p:')) {
            $profile = $this->profiles->findByIdAndCentre(substr($value, 2), $centre);

            return $profile === null ? null : [null, $profile];
        }

        return null;
    }
}
