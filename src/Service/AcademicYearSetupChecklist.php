<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\EducationalCentre;
use App\Entity\SpecificProfileAssignment;
use App\Model\AcademicYearSetupStep;
use App\Entity\ListItem;
use App\Entity\Teacher;
use App\Repository\AcademicYearRepository;
use App\Repository\ListItemRepository;
use App\Repository\NonWorkingDayRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * "Preparar el nuevo curso": where a centre stands in getting a new academic year ready — the
 * year exists, it's the active one, it has its teachers and its non-working days, and no profile
 * is still assigned to someone who isn't in it any more, and no activity asks a profile nobody in
 * the year holds. Activities and document reviews follow the calendar on their own (their
 * deadlines and folders carry over); the activities step only checks who they ask.
 *
 * Profiles and their assignments belong to the centre, not to a year, so they carry over; the
 * only thing to check is who left.
 */
final class AcademicYearSetupChecklist
{
    public const string STEP_CREATE      = 'create';
    public const string STEP_ACTIVATE    = 'activate';
    public const string STEP_TEACHERS    = 'teachers';
    public const string STEP_SUBJECTS    = 'subjects';
    public const string STEP_DAYS        = 'non_working_days';
    public const string STEP_ASSIGNMENTS = 'assignments';
    public const string STEP_ACTIVITIES  = 'activities';

    public function __construct(
        private readonly AcademicYearRepository $years,
        private readonly NonWorkingDayRepository $nonWorkingDays,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly ActivityYearReviewBuilder $activityReview,
        private readonly ClockInterface $clock,
        private readonly ListItemRepository $items,
    ) {}

    /** The year being prepared: the latest one by name ("2026-2027" sorts after "2025-2026"), if any. */
    public function targetYear(EducationalCentre $centre): ?AcademicYear
    {
        $years = $this->years->findByCentreOrderedByName($centre);
        usort($years, static fn (AcademicYear $a, AcademicYear $b): int => strnatcmp($a->getName(), $b->getName()));

        return $years === [] ? null : $years[\count($years) - 1];
    }

    /**
     * The name the next year would take: the latest one's, one year on ("2025-2026" → "2026-2027",
     * "2025/26" → "2026/27"), or "<this year>-<next year>" when there's nothing to go on.
     */
    public function suggestedName(EducationalCentre $centre): string
    {
        $latest = $this->targetYear($centre)?->getName();
        if ($latest !== null && preg_match('/^(\d{4})(\s*[-\/]\s*)(\d{2}|\d{4})$/', $latest, $m) === 1) {
            $second = (string) ((int) $m[3] + 1);

            return ((int) $m[1] + 1) . $m[2] . str_pad(substr($second, -\strlen($m[3])), \strlen($m[3]), '0', \STR_PAD_LEFT);
        }

        $year = (int) $this->clock->now()->format('Y');

        return $year . '-' . ($year + 1);
    }

    /**
     * Other years with teachers to copy from, latest first.
     *
     * @return list<AcademicYear>
     */
    public function copySources(EducationalCentre $centre, ?AcademicYear $target): array
    {
        $years = array_values(array_filter(
            $this->years->findByCentreOrderedByName($centre),
            static fn (AcademicYear $y): bool => $y !== $target && !$y->getTeachers()->isEmpty(),
        ));
        usort($years, static fn (AcademicYear $a, AcademicYear $b): int => strnatcmp($b->getName(), $a->getName()));

        return $years;
    }

    /**
     * @param list<array{item: ListItem, path: string, teacher: Teacher}>|null $staleSubjects staleSubjectTeachers(), when the caller
     *                                                                                    already has it (it walks the whole catalogue)
     *
     * @return list<AcademicYearSetupStep> in the order they're best done
     */
    public function steps(EducationalCentre $centre, ?AcademicYear $target, ?array $staleSubjects = null): array
    {
        $active   = $target !== null && $centre->getActiveAcademicYear() === $target;
        $teachers = $target?->getTeachers()->count() ?? 0;
        $days     = $target === null ? 0 : \count($this->nonWorkingDays->findByAcademicYearOrdered($target));
        $stale    = $target === null ? 0 : \count($this->staleAssignments($centre, $target));
        $subjects = $target === null || $teachers === 0 ? 0 : \count($staleSubjects ?? $this->staleSubjectTeachers($centre, $target));
        $flagged  = $target === null || $teachers === 0 ? 0 : $this->activityReview->countWithIssues($centre, $target);

        return [
            new AcademicYearSetupStep(self::STEP_CREATE, $target !== null),
            new AcademicYearSetupStep(self::STEP_ACTIVATE, $active, blocked: $target === null),
            new AcademicYearSetupStep(self::STEP_TEACHERS, $teachers > 0, $teachers, blocked: !$active),
            new AcademicYearSetupStep(self::STEP_SUBJECTS, $teachers > 0 && $subjects === 0, $subjects, blocked: !$active || $teachers === 0),
            new AcademicYearSetupStep(self::STEP_DAYS, $days > 0, $days, blocked: !$active),
            new AcademicYearSetupStep(self::STEP_ASSIGNMENTS, $teachers > 0 && $stale === 0, $stale, blocked: !$active || $teachers === 0),
            new AcademicYearSetupStep(self::STEP_ACTIVITIES, $teachers > 0 && $flagged === 0, $flagged, blocked: !$active || $teachers === 0),
        ];
    }

    /**
     * The teachers associated with an element of the catalogue (who teaches each subject, group…) who aren't in $year: someone who left
     * still down as teaching a subject — until the Séneca «Materias y grupos» import, or «Quitar docentes fuera de curso», updates it.
     * Only the element's own associations (an inherited one is the ancestor's). By element path, for the list on the step.
     *
     * @return list<array{item: ListItem, path: string, teacher: Teacher}>
     */
    public function staleSubjectTeachers(EducationalCentre $centre, AcademicYear $year): array
    {
        $members = [];
        foreach ($year->getTeachers() as $teacher) {
            $members[$teacher->getId()->toRfc4122()] = true;
        }

        $found = [];
        foreach ($this->items->findAllInTree($centre) as $item) {
            foreach ($item->getTeachers() as $teacher) {
                if (!isset($members[$teacher->getId()->toRfc4122()])) {
                    $names = [];
                    for ($node = $item; $node !== null; $node = $node->getParent()) {
                        array_unshift($names, $node->getName());
                    }
                    $found[] = ['item' => $item, 'path' => implode(' › ', $names), 'teacher' => $teacher];
                }
            }
        }
        usort($found, static fn (array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));

        return $found;
    }

    /**
     * Profile assignments whose teacher isn't in $year: someone who left still acting as head of
     * department, tutor… — until the assignment is removed or handed over.
     *
     * @return list<SpecificProfileAssignment>
     */
    public function staleAssignments(EducationalCentre $centre, AcademicYear $year): array
    {
        $members = [];
        foreach ($year->getTeachers() as $teacher) {
            $members[$teacher->getId()->toRfc4122()] = true;
        }

        return array_values(array_filter(
            $this->assignments->findAllForCentre($centre),
            static fn (SpecificProfileAssignment $a): bool => !isset($members[$a->getTeacher()->getId()->toRfc4122()]),
        ));
    }
}
