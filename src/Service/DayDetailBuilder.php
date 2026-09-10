<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDeadlineOccurrence;
use App\Repository\ActivityRepository;
use App\Repository\SchoolEventRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the detail view of a single day, reached by clicking a day in the calendar: school
 * events (per visibility), the teacher's own activity deadlines landing on that day (by upload
 * profile — never "all activities", not even for an admin, see
 * ActivityCompletionChecker::getMyOwnedObligations()), and the non-working day label.
 */
class DayDetailBuilder
{
    public function __construct(
        private readonly SchoolEventRepository $events,
        private readonly NonWorkingDayChecker $nonWorkingDayChecker,
        private readonly TranslatorInterface $translator,
        private readonly ActivityRepository $activities,
        private readonly ActivityCompletionChecker $activityCompletion,
        private readonly ActivityDeadlineChecker $activityDeadline,
    ) {}

    public function build(AcademicYear $year, EducationalCentre $centre, ?Teacher $viewer, bool $isAdmin, \DateTimeImmutable $date): DayDetailReport
    {
        if ($isAdmin) {
            $events = $this->events->findAllForAcademicYearAndDate($year, $date);
        } elseif ($viewer !== null) {
            $events = $this->events->findVisibleForTeacherAndDate($viewer, $year, $date);
        } else {
            $events = [];
        }

        return new DayDetailReport(
            $date,
            $events,
            $viewer !== null ? $this->activityDeadlinesForDate($viewer, $centre, $date) : [],
            $this->nonWorkingDayChecker->isNonWorkingDay($year, $date) ? $this->nonWorkingDayLabel($year, $date) : null,
        );
    }

    /** @return list<ActivityDeadlineOccurrence> */
    private function activityDeadlinesForDate(Teacher $viewer, EducationalCentre $centre, \DateTimeImmutable $date): array
    {
        $day = $date->format('Y-m-d');

        $items = [];
        foreach ($this->activities->findAllByCentre($centre) as $activity) {
            $end = $this->activityDeadline->cycleEndDateNear($activity, $date);

            // Single-date activities (same start and end day/month) still only surface on their
            // deadline day; an activity with a real range surfaces on every day it covers.
            if ($this->isSingleDate($activity)) {
                if ($end->format('Y-m-d') !== $day) {
                    continue;
                }
                $start = $end;
            } else {
                $start = $this->activityDeadline->cycleStartDateNear($activity, $date);
                if ($day < $start->format('Y-m-d') || $day > $end->format('Y-m-d')) {
                    continue;
                }
            }

            foreach ($this->activityCompletion->getMyOwnedObligations($viewer, $activity) as $owner) {
                $completed = $this->activityCompletion->isCompletedFor($activity, $owner['profile'], $owner['listItem'], $owner['teacher']);
                $items[]   = new ActivityDeadlineOccurrence($activity, $start, $end, $owner['label'], $owner['key'], $completed);
            }
        }

        return $items;
    }

    private function isSingleDate(Activity $activity): bool
    {
        return $activity->getStartDay() === $activity->getEndDay()
            && $activity->getStartMonth() === $activity->getEndMonth();
    }

    private function nonWorkingDayLabel(AcademicYear $year, \DateTimeImmutable $date): string
    {
        $description = $this->nonWorkingDayChecker->descriptionFor($year, $date);
        if ($description !== null) {
            return $description;
        }

        return $this->nonWorkingDayChecker->isWeekend($date)
            ? $this->translator->trans('day.non_working_weekend', [], 'calendar')
            : $this->translator->trans('day.non_working', [], 'calendar');
    }
}
