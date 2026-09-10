<?php

declare(strict_types=1);

namespace App\Model;

/**
 * The state of an activity's submission/completion window for one (activity, teacher, "now"):
 * whether the enforced start/end dates currently block the teacher, whether they'd be acting
 * late, and the dates the UI needs to explain it. Built by ActivityWindowChecker; shared by the
 * controller, the Live Component and the templates so all three agree.
 */
final readonly class ActivityWindow
{
    public function __construct(
        /** Hide the submit/mark actions and show a blocking notice. */
        public bool $blocked,
        /** Why it is blocked; null unless $blocked. */
        public ?ActivityWindowBlock $reason,
        /** The action is allowed but past the end date — record and show it as "late". */
        public bool $late,
        /** The teacher may only act because of their role (folder manager / quality manager / admin). */
        public bool $bypassing,
        /** "Now" is before the activity's cycle start date — regardless of whether the start is enforced. */
        public bool $notStarted,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        /** endDate + grace days ( == endDate when there is no grace period). */
        public \DateTimeImmutable $graceUntil,
    ) {}
}
