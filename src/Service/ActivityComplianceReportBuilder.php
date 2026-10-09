<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Model\ActivityComplianceRow;
use App\Model\ActivityCycleFigures;
use App\Repository\AcademicYearRepository;
use App\Repository\ActivityCompletionRepository;
use App\Repository\ActivityRepository;
use App\Repository\DocumentRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * How each activity went in a given academic year and, optionally, against the one before: what was
 * expected, what got done (submissions accepted, or teachers who ticked a manual one) and how much
 * of it in time — for the audit's "¿se cumple lo que se dice?". Years are the activities' own
 * cycles (see ActivityDeadlineChecker): a cycle key is the first calendar year of an academic year,
 * 2025 for 2025-2026.
 *
 * Two caveats, both from what is stored: documents and completions keep their year, but who was
 * expected back then doesn't — so "expected" is today's slots for an activity with a folder, and the
 * teachers of that academic year (the one named after the cycle, else the active one) for a manual
 * one. And "on time" is measured against the activity's general deadline for that year.
 */
final class ActivityComplianceReportBuilder
{
    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ActivityCompletionRepository $completions,
        private readonly DocumentRepository $documents,
        private readonly AcademicYearRepository $academicYears,
        private readonly ActivitySubmissionProgressCalculator $progress,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Cycle years there is something to report on, newest first — plus the current one, which may
     * not have a single submission yet.
     *
     * @return list<int>
     */
    public function availableCycles(EducationalCentre $centre): array
    {
        $years = array_unique([
            ...$this->documents->findActivityCycleYearsByCentre($centre),
            ...$this->completions->findCycleYearsByCentre($centre),
            $this->currentCycle($centre),
        ]);
        rsort($years);

        return $years;
    }

    /** The cycle "now" belongs to, from the active academic year's name ("2026-2027" → 2026), else the calendar. */
    public function currentCycle(EducationalCentre $centre): int
    {
        $name = $centre->getActiveAcademicYear()?->getName();
        if ($name !== null && preg_match('/^(\d{4})/', $name, $m) === 1) {
            return (int) $m[1];
        }

        $now = $this->clock->now();

        return (int) $now->format('Y') - ((int) $now->format('n') < 9 ? 1 : 0);
    }

    /** @return list<ActivityComplianceRow> every visible activity, in category order */
    public function build(EducationalCentre $centre, int $cycle, ?int $compareWith = null): array
    {
        $cycles = $compareWith === null ? [$cycle] : [$cycle, $compareWith];

        // Everything for the centre in two queries, grouped here by activity and cycle.
        $docsBy = [];
        foreach ($this->documents->findSubmissionFiguresByCentreAndCycles($centre, $cycles) as $d) {
            $docsBy[$d['activityId']][$d['cycleYear']][] = $d;
        }
        $doneBy = [];
        foreach ($this->completions->findDatesByCentreAndCycles($centre, $cycles) as $c) {
            $doneBy[$c['activityId']][$c['cycleYear']][] = $c['completedAt'];
        }

        $teachersByCycle = [];
        foreach ($cycles as $key) {
            $teachersByCycle[$key] = $this->teachersOf($centre, $key);
        }

        $rows = [];
        foreach ($this->activities->findAllByCentre($centre) as $activity) {
            $id       = $activity->getId()->toRfc4122();
            $expected = $this->progress->forActivity($activity)?->total;
            $figures  = fn (int $key): ActivityCycleFigures => $this->figures(
                $activity,
                $key,
                $expected ?? $teachersByCycle[$key],
                $docsBy[$id][$key] ?? [],
                $doneBy[$id][$key] ?? [],
            );

            $rows[] = new ActivityComplianceRow(
                $this->categoryPath($activity->getCategory()),
                $activity->getTitle(),
                $expected !== null,
                $figures($cycle),
                $compareWith === null ? null : $figures($compareWith),
            );
        }

        return $rows;
    }

    /**
     * @param list<array{activityId: string, cycleYear: int, uploadedAt: \DateTimeImmutable, accepted: bool}> $documents
     * @param list<\DateTimeImmutable>                                                                         $completions
     */
    private function figures(Activity $activity, int $cycle, int $expected, array $documents, array $completions): ActivityCycleFigures
    {
        // Any date inside the academic year does to find that year's occurrence of the activity.
        $deadline = $this->deadline->cycleEndDateNear($activity, new \DateTimeImmutable($cycle . '-12-01'))->setTime(23, 59, 59);

        if ($activity->requiresSubmissions()) {
            $accepted = array_filter($documents, static fn (array $d): bool => $d['accepted']);

            return new ActivityCycleFigures(
                $expected,
                \count($documents),
                \count($accepted),
                \count(array_filter($documents, static fn (array $d): bool => $d['uploadedAt'] <= $deadline)),
            );
        }

        return new ActivityCycleFigures(
            $expected,
            \count($completions),
            \count($completions),
            \count(array_filter($completions, static fn (\DateTimeImmutable $at): bool => $at <= $deadline)),
        );
    }

    /** Teachers of the academic year named after the cycle ("2025-2026" for 2025), else of the active one. */
    private function teachersOf(EducationalCentre $centre, int $cycle): int
    {
        foreach ($this->academicYears->findByCentreOrderedByName($centre) as $year) {
            if (str_starts_with($year->getName(), (string) $cycle)) {
                return $year->getTeachers()->count();
            }
        }

        return $centre->getActiveAcademicYear()?->getTeachers()->count() ?? 0;
    }

    private function categoryPath(ActivityCategory $category): string
    {
        $trail = [];
        for ($c = $category; $c !== null; $c = $c->getParent()) {
            array_unshift($trail, $c->getName());
        }

        return implode(' › ', $trail);
    }
}
