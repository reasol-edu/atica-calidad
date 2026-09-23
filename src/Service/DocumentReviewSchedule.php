<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Clock\ClockInterface;

/**
 * How close a document's next review date is — one rule for the badge in the document tree and
 * for the Informes section, so both flag the same documents.
 */
final class DocumentReviewSchedule
{
    public const string OVERDUE = 'overdue';
    public const string SOON    = 'soon';
    public const string OK      = 'ok';

    /** Within this many days the review is flagged as coming up. */
    public const int SOON_DAYS = 30;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    /** self::OVERDUE, self::SOON (within SOON_DAYS), self::OK — or null without a date. */
    public function stateOf(?\DateTimeImmutable $nextReviewAt): ?string
    {
        if ($nextReviewAt === null) {
            return null;
        }

        $today = $this->today();

        return match (true) {
            $nextReviewAt < $today                                        => self::OVERDUE,
            $nextReviewAt <= $today->modify('+' . self::SOON_DAYS . ' days') => self::SOON,
            default                                                       => self::OK,
        };
    }

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
