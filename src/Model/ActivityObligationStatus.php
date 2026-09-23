<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Where one of a teacher's activity obligations stands — the single status model every screen
 * (dashboard, "Mis actividades", "Ver" cards, the bell) and the reminder emails share, computed by
 * ActivityObligationFinder. Each status belongs to one group() that answers "whose move is it?".
 */
enum ActivityObligationStatus: string
{
    /** The occurrence hasn't opened yet. */
    case Upcoming = 'upcoming';
    /** Open and still within its deadline. */
    case Open = 'open';
    /** A submission was rejected: it has to be submitted again. */
    case Rejected = 'rejected';
    /** Past the deadline, but still accepted: an enforced end date's grace period (or the teacher may bypass it). */
    case Late = 'late';
    /** Past the deadline (not enforced, so still doable). */
    case Overdue = 'overdue';
    /** Everything submitted; at least one submission waits for someone's approval. */
    case InReview = 'in_review';
    case Completed = 'completed';
    /** The enforced deadline (and any grace period) has passed without completing it: nothing left to do. */
    case Closed = 'closed';

    public const string GROUP_TODO     = 'todo';
    public const string GROUP_WAITING  = 'waiting';
    public const string GROUP_DONE     = 'done';
    public const string GROUP_UPCOMING = 'upcoming';
    public const string GROUP_CLOSED   = 'closed';

    /** Whose move it is: the teacher's own (todo), someone else's (waiting), or nobody's right now. */
    public function group(): string
    {
        return match ($this) {
            self::Open, self::Rejected, self::Late, self::Overdue => self::GROUP_TODO,
            self::InReview  => self::GROUP_WAITING,
            self::Completed => self::GROUP_DONE,
            self::Upcoming  => self::GROUP_UPCOMING,
            self::Closed    => self::GROUP_CLOSED,
        };
    }

    public function isActionable(): bool
    {
        return $this->group() === self::GROUP_TODO;
    }

    /** Past its deadline and still to do — shown in red, counted apart. */
    public function isOverdue(): bool
    {
        return $this === self::Overdue || $this === self::Late;
    }

    /** Lower sorts first: what most urgently needs the teacher, down to what needs nothing. */
    public function urgency(): int
    {
        return match ($this) {
            self::Overdue   => 0,
            self::Late      => 1,
            self::Rejected  => 2,
            self::Open      => 3,
            self::InReview  => 4,
            self::Upcoming  => 5,
            self::Closed    => 6,
            self::Completed => 7,
        };
    }
}
