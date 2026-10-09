<?php

declare(strict_types=1);

namespace App\Model;

/** How one activity went in one academic year (cycle) — a column of the compliance report. */
final readonly class ActivityCycleFigures
{
    public function __construct(
        /** Submissions expected, or teachers it applied to (manual). */
        public int $expected,
        /** Submissions sent whatever their state, or completions (manual). */
        public int $delivered,
        /** Submissions accepted, or completions (manual). */
        public int $done,
        /** Of $done/$delivered, the ones in before the cycle's deadline. */
        public int $onTime,
    ) {}

    /** Share of what was expected that got done, 0–100. */
    public function percentage(): int
    {
        return $this->expected === 0 ? 0 : min(100, (int) round($this->done / $this->expected * 100));
    }

    /** Share of what was handed in that was handed in on time, 0–100 (0 when nothing was). */
    public function onTimePercentage(): int
    {
        return $this->delivered === 0 ? 0 : min(100, (int) round($this->onTime / $this->delivered * 100));
    }
}
