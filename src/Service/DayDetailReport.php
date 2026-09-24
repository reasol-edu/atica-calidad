<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SchoolEvent;
use App\Model\ActivityDeadlineOccurrence;
use App\Model\QualityTask;

final readonly class DayDetailReport
{
    /**
     * @param list<SchoolEvent>                $events
     * @param list<ActivityDeadlineOccurrence> $activityDeadlines
     * @param list<QualityTask>                $qualityTasks      "Mejora continua" deadlines of the day
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $events,
        public array $activityDeadlines,
        public ?string $nonWorkingDayLabel = null,
        public array $qualityTasks = [],
    ) {}
}
