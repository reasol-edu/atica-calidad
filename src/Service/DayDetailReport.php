<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SchoolEvent;

final readonly class DayDetailReport
{
    /**
     * @param list<SchoolEvent> $events
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $events,
        public ?string $nonWorkingDayLabel = null,
    ) {}
}
