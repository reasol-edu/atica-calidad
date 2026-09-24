<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Indicator;
use App\Entity\IndicatorStatus;
use App\Entity\IndicatorTarget;
use App\Entity\Measurement;

/**
 * An indicator in one academic year, ready to show (IndicatorBoardBuilder): its target, a cell
 * per period, its latest value and how it stands, the comparable value of the year before, and
 * the points of its chart.
 */
final readonly class IndicatorRow
{
    /**
     * @param list<IndicatorCell>                                                                                                   $cells
     * @param array{value: float, label: string}|null                                                                               $previous the year before's value for the same period (or its last one)
     * @param array{points: list<array{x: float, y: float}>, previous: list<array{x: float, y: float}>, targetY: ?float, thresholdY: ?float} $chart    in a 100 × 40 box
     */
    public function __construct(
        public Indicator $indicator,
        public ?IndicatorTarget $target,
        public array $cells,
        public ?Measurement $latest,
        /** Of its last period that has a value or should have had it (NoData); null while none is due yet. */
        public ?IndicatorStatus $status,
        public ?array $previous,
        public array $chart,
    ) {}

    /** How many of its periods have a value. */
    public function measuredCount(): int
    {
        return \count(array_filter($this->cells, static fn (IndicatorCell $c): bool => $c->state === IndicatorCell::VALUE));
    }

    /** Off-target values nobody has dealt with yet. */
    public function pendingReviewCount(): int
    {
        return \count(array_filter(
            $this->cells,
            static fn (IndicatorCell $c): bool => $c->measurement !== null && !$c->measurement->isReviewed() && $c->status() === IndicatorStatus::OffTarget,
        ));
    }
}
