<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\IndicatorStatus;
use App\Entity\Measurement;
use App\Entity\MeasurementPeriod;

/**
 * One period of an indicator's year (IndicatorRow): its value and how it stands, or why there's
 * none yet — "pending" (over, still within the days to record it), "late" (past that) or "future".
 */
final readonly class IndicatorCell
{
    public const string VALUE   = 'value';
    public const string PENDING = 'pending';
    public const string LATE    = 'late';
    public const string FUTURE  = 'future';

    public function __construct(
        public MeasurementPeriod $period,
        public ?Measurement $measurement,
        public string $state,
        /** Last day to record it: the period's end plus quality.measurement_days. */
        public \DateTimeImmutable $dueDate,
    ) {}

    public function status(): ?IndicatorStatus
    {
        return match ($this->state) {
            self::VALUE => $this->measurement?->status(),
            self::LATE  => IndicatorStatus::NoData,
            default     => null,
        };
    }
}
