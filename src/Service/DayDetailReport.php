<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SchoolEvent;
use App\Model\ActivityDeadlineOccurrence;

final readonly class DayDetailReport
{
    /**
     * @param list<SchoolEvent>              $events
     * @param list<ActivityDeadlineOccurrence> $activityDeadlines
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $events,
        public array $activityDeadlines,
        public ?string $nonWorkingDayLabel = null,
    ) {}
}
