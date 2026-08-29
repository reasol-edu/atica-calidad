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
        $now = $this->clock->now();
        $end = new \DateTimeImmutable(\sprintf(
            '%04d-%02d-%02d 23:59:59',
            (int) $now->format('Y'),
            $activity->getEndMonth(),
            $activity->getEndDay(),
        ));

        // A range that crosses the calendar year boundary (e.g. Sep–Jun): while we're still in
        // the "start" stretch (Sep–Dec), the relevant end date is next calendar year's.
        if ($activity->getStartMonth() > $activity->getEndMonth() && (int) $now->format('n') >= $activity->getStartMonth()) {
            $end = $end->modify('+1 year');
        }

        return $end;
    }

    public function isOverdue(Activity $activity): bool
    {
        return $this->clock->now() > $this->currentCycleEndDate($activity);
    }
}
