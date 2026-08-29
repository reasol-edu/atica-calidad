<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;

/**
 * One obligation a teacher personally owns for an activity — not persisted, built on the fly by
 * ActivityDashboardSummaryBuilder. A single Activity can produce more than one item for the same
 * teacher when its submission scope is ByProfile and the teacher holds more than one owner row
 * (e.g. head of two different departments) — each is tracked and shown independently.
 * $ownerLabel disambiguates those cases in the UI (e.g. the department name); null when there's
 * only ever one obligation per teacher for the activity (Individual scope, or no folder at all).
 */
final readonly class ActivityDashboardItem
{
    public function __construct(
        public Activity $activity,
        public ActivityDashboardStatus $status,
        public string $categoryPath,
        public ?string $ownerLabel,
        public \DateTimeImmutable $deadline,
    ) {}
}
