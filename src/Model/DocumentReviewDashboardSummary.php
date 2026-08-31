<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\DocumentRevision;

/**
 * The dashboard's "pending review" widget data — not persisted, built on the fly by
 * DashboardPendingReviewComponent. $hasAccess is false for a teacher with no review access to any
 * folder in the centre, in which case the widget renders nothing at all; $total counts every
 * revision awaiting this teacher's review, $items only the ones actually shown (capped).
 */
final readonly class DocumentReviewDashboardSummary
{
    /** @param list<DocumentRevision> $items */
    public function __construct(
        public bool $hasAccess,
        public int $total,
        public array $items,
    ) {}
}
