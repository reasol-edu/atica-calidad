<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\EducationalCentre;
use App\Model\ActivityStatusReportRow;
use App\Repository\ActivityCompletionRepository;
use App\Repository\ActivityRepository;
use App\Repository\TeacherRepository;

/**
 * How every activity of the centre stands in its current occurrence, for the Informes section:
 * one row per activity, in category order. An activity with a folder is measured by its expected
 * submissions (ActivitySubmissionProgressCalculator — sent, accepted, waiting for approval,
 * rejected); one without, by how many of the active academic year's teachers marked it completed
 * (such an activity applies to every teacher individually).
 */
final class ActivityStatusReportBuilder
{
    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ActivitySubmissionProgressCalculator $progress,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly ActivityCompletionRepository $completions,
        private readonly TeacherRepository $teachers,
    ) {}

    /** @return list<ActivityStatusReportRow> */
    public function build(EducationalCentre $centre): array
    {
        $year     = $centre->getActiveAcademicYear();
        $teachers = $year === null ? 0 : $this->teachers->countByAcademicYear($year);

        $rows = [];
        foreach ($this->activities->findAllByCentre($centre) as $activity) {
            $rows[] = $this->row($activity, $teachers);
        }

        usort($rows, static fn (ActivityStatusReportRow $a, ActivityStatusReportRow $b): int => strcmp($a->categoryPath, $b->categoryPath));

        return $rows;
    }

    private function row(Activity $activity, int $teachers): ActivityStatusReportRow
    {
        $path     = $this->categoryPath($activity->getCategory());
        $startsAt = $this->deadline->currentCycleStartDate($activity);
        $deadline = $this->deadline->currentCycleEndDate($activity);

        $progress = $this->progress->forActivity($activity);
        if ($progress !== null) {
            return new ActivityStatusReportRow($path, $activity->getTitle(), $startsAt, $deadline, true, $progress->total, $progress->delivered, $progress->accepted, $progress->inReview, $progress->rejected);
        }

        $done = $this->completions->countByActivityAndCycle($activity, $this->deadline->currentCycleKey($activity));

        return new ActivityStatusReportRow($path, $activity->getTitle(), $startsAt, $deadline, false, $teachers, $done, $done, 0, 0);
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
