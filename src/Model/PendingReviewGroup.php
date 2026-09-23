<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;
use App\Entity\DocumentRevision;

/**
 * Revisions pending review, as one line on screen: all of one activity's submissions together
 * ("Programación didáctica · 6 entregas por revisar", opened in the activity itself), or a single
 * document from a folder that backs no activity. Built by PendingReviewFinder::group().
 */
final readonly class PendingReviewGroup
{
    /** @param non-empty-list<DocumentRevision> $revisions oldest pending first */
    public function __construct(
        public ?Activity $activity,
        public array $revisions,
    ) {}

    /** The oldest pending one — the one a link to the group lands on. */
    public function first(): DocumentRevision
    {
        return $this->revisions[0];
    }

    public function count(): int
    {
        return \count($this->revisions);
    }

    public function oldestAt(): \DateTimeImmutable
    {
        return $this->first()->getRevisedAt();
    }
}
