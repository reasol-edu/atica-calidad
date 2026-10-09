<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;
use App\Entity\DocumentRevision;

/** One revision in the review queue (ReviewQueueBuilder), with what decides how soon it needs looking at. */
final readonly class ReviewQueueItem
{
    /** Its activity's deadline is past or close: the submission may be late for what it is for. */
    public const string CRITICAL = 'critical';
    /** Has been waiting for a while. */
    public const string WAITING = 'waiting';
    public const string NORMAL = 'normal';

    public function __construct(
        public DocumentRevision $revision,
        public string $urgency,
        /** Whole days since it was sent for review. */
        public int $waitingDays,
        public ?Activity $activity,
        /** The activity's deadline for the current occurrence; null for a document of any other folder. */
        public ?\DateTimeImmutable $deadline,
        /** Whole days to $deadline (negative once past); null without one. */
        public ?int $daysToDeadline,
    ) {}
}
