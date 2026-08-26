<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Teacher;
use App\Repository\SchoolEventRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the detail view of a single day, reached by clicking a day in the
 * calendar: school events (per visibility) and the non-working day label.
 */
class DayDetailBuilder
{
    public function __construct(
        private readonly SchoolEventRepository $events,
        private readonly NonWorkingDayChecker $nonWorkingDayChecker,
        private readonly TranslatorInterface $translator,
    ) {}

    public function build(AcademicYear $year, ?Teacher $viewer, bool $isAdmin, \DateTimeImmutable $date): DayDetailReport
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
            $this->nonWorkingDayChecker->isNonWorkingDay($year, $date) ? $this->nonWorkingDayLabel($year, $date) : null,
        );
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
