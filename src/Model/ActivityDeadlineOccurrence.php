<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;

/**
 * One obligation a teacher personally owns for an activity, anchored to a real calendar span —
 * not persisted, built on the fly for the calendar (month grid and day detail) from
 * ActivityCompletionChecker::getMyOwnedObligations() + ActivityDeadlineChecker's cycle helpers.
 *
 * $startDate/$endDate are the real dates the activity's current cycle opens and closes on. When
 * the activity's start day/month and end day/month are the same pair, both hold that single date
 * and the calendar draws one marker; otherwise they bound the range the calendar fills day by
 * day. $ownerKey identifies which of the activity's upload rows this occurrence belongs to (used
 * to pick a stable colour and a unique id); empty when the owner is the teacher themself
 * (Individual scope, or no folder at all).
 */
final readonly class ActivityDeadlineOccurrence
{
    public function __construct(
        public Activity $activity,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?string $ownerLabel,
        public string $ownerKey,
        public bool $completed,
    ) {}

    /** True when the activity is a single-date deadline rather than a real start–end range. */
    public function isSingleDay(): bool
    {
        return $this->startDate->format('Y-m-d') === $this->endDate->format('Y-m-d');
    }
}
