<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use Symfony\Component\Clock\ClockInterface;

/**
 * Computes whether an Activity's day/month deadline (no year — it repeats every academic year)
 * has already passed for the current cycle. Self-contained: anchors the deadline to a real
 * calendar date using only "now", without needing AcademicYear's actual dates (it only stores a
 * name like "2025-2026", not real start/end dates).
 */
final class ActivityDeadlineChecker
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    /** The real calendar date the activity's deadline falls on for the cycle "now" belongs to. */
    public function currentCycleEndDate(Activity $activity): \DateTimeImmutable
    {
        return $this->cycleEndDateNear($activity, $this->clock->now());
    }

    /**
     * The real calendar date the activity's deadline falls on for the cycle $reference belongs
     * to — same anchoring/year-crossing logic as currentCycleEndDate(), just anchored to an
     * arbitrary date instead of "now". Used by the calendar, which can be browsed to any month.
     */
    public function cycleEndDateNear(Activity $activity, \DateTimeImmutable $reference): \DateTimeImmutable
    {
        $end = new \DateTimeImmutable(\sprintf(
            '%04d-%02d-%02d 23:59:59',
            (int) $reference->format('Y'),
            $activity->getEndMonth(),
            $activity->getEndDay(),
        ));

        // A range that crosses the calendar year boundary (e.g. Sep–Jun): while the reference is
        // still in the "start" stretch (Sep–Dec), the relevant end date is next calendar year's.
        if ($activity->getStartMonth() > $activity->getEndMonth() && (int) $reference->format('n') >= $activity->getStartMonth()) {
            $end = $end->modify('+1 year');
        }

        return $end;
    }

    public function isOverdue(Activity $activity): bool
    {
        return $this->clock->now() > $this->currentCycleEndDate($activity);
    }

    /** The real calendar date the activity's period opens on for the cycle "now" belongs to. */
    public function currentCycleStartDate(Activity $activity): \DateTimeImmutable
    {
        return $this->cycleStartDateNear($activity, $this->clock->now());
    }

    /**
     * The real calendar date the activity's period opens on for the cycle $reference belongs to —
     * same anchoring/year-crossing logic as cycleEndDateNear(), mirrored for the start side: a
     * wrapping range (e.g. Sep–Jun) whose reference sits in the "end" stretch (Jan–Jun) started
     * back in the previous calendar year.
     */
    public function cycleStartDateNear(Activity $activity, \DateTimeImmutable $reference): \DateTimeImmutable
    {
        $start = new \DateTimeImmutable(\sprintf(
            '%04d-%02d-%02d 00:00:00',
            (int) $reference->format('Y'),
            $activity->getStartMonth(),
            $activity->getStartDay(),
        ));

        if ($activity->getStartMonth() > $activity->getEndMonth() && (int) $reference->format('n') < $activity->getStartMonth()) {
            $start = $start->modify('-1 year');
        }

        return $start;
    }

    /** Whether the activity's current cycle has already opened — false while still waiting for its yearly start date. */
    public function hasStarted(Activity $activity): bool
    {
        return $this->clock->now() >= $this->currentCycleStartDate($activity);
    }

    /** Whole days remaining until the current cycle's deadline. Meaningful only when not overdue — see isOverdue(). */
    public function daysUntilDeadline(Activity $activity): int
    {
        return (int) $this->clock->now()->diff($this->currentCycleEndDate($activity))->days;
    }
}
