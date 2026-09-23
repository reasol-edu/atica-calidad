<?php

declare(strict_types=1);

namespace App\Model;

/**
 * How an activity's expected submissions stand overall, for this academic year: every slot
 * counted once, however many teachers or profiles it belongs to. Built by
 * ActivitySubmissionProgressCalculator for the "Ver" cards of whoever manages or reviews the
 * activity's folder.
 */
final readonly class ActivitySubmissionProgress
{
    public function __construct(
        /** Expected submissions (slots). */
        public int $total,
        /** Slots with a document, whatever its state. */
        public int $delivered,
        /** Of those, the ones with an accepted (active) revision. */
        public int $accepted,
        /** Of those, the ones waiting for someone's approval. */
        public int $inReview,
        /** Of those, the ones rejected and not submitted again yet. */
        public int $rejected,
    ) {}

    public function deliveredPercentage(): int
    {
        return $this->total === 0 ? 0 : (int) round($this->delivered / $this->total * 100);
    }

    public function acceptedPercentage(): int
    {
        return $this->total === 0 ? 0 : (int) round($this->accepted / $this->total * 100);
    }
}
