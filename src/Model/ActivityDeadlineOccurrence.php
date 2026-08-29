<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;

/**
 * One obligation a teacher personally owns for an activity, anchored to a real calendar date —
 * not persisted, built on the fly for the calendar (month grid and day detail) from
 * ActivityCompletionChecker::getMyOwnedObligations() + ActivityDeadlineChecker::cycleEndDateNear().
 * $ownerKey identifies which of the activity's upload rows this occurrence belongs to (used to
 * pick a stable colour and a unique id); empty when the owner is the teacher themself (Individual
 * scope, or no folder at all).
 */
final readonly class ActivityDeadlineOccurrence
{
    public function __construct(
        public Activity $activity,
        public \DateTimeImmutable $date,
        public ?string $ownerLabel,
        public string $ownerKey,
        public bool $completed,
    ) {}
}
