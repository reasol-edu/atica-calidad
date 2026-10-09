<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;

/**
 * One obligation a teacher personally owns for an activity, with where it stands — not persisted,
 * built on the fly by ActivityObligationFinder. A single Activity can produce more than one item
 * for the same teacher when its submission scope is ByProfile and the teacher holds more than one
 * owner row (e.g. head of two different departments) — each is tracked and shown independently.
 * $ownerLabel disambiguates those cases in the UI (e.g. the department name); null when there's
 * only ever one obligation per teacher for the activity (Individual scope, or no folder at all).
 */
final readonly class ActivityDashboardItem
{
    public function __construct(
        public Activity $activity,
        public ActivityObligationStatus $status,
        public string $categoryPath,
        public ?string $ownerLabel,
        /** The current occurrence's deadline. */
        public \DateTimeImmutable $deadline,
        /** The real date the current occurrence opens on. */
        public \DateTimeImmutable $startsAt,
        /** Last day a late submission is still accepted ( == $deadline without a grace period). */
        public \DateTimeImmutable $graceUntil,
        /** Whole days from today to $deadline: 0 today, 1 tomorrow, negative once past. */
        public int $daysLeft,
        /** The owner row this obligation belongs to, as ids ('' when not applicable) — what marking it done needs. */
        public string $profileId = '',
        public string $listItemId = '',
        public string $leafId = '',
        /** A manual activity the teacher can tick off right now (no folder, not auto-complete, window open). */
        public bool $canMarkDone = false,
        /** The submission row still to do ("" when there is none to point at, "next" for the first of several): lets a link land right on it. */
        public string $slotKey = '',
        /** What a file can be sent for right from a list: '' (nothing, or not unambiguous), 'new' (one empty row) or 'revision' (a rejected submission). */
        public string $uploadMode = '',
        /** The one empty row's key when $uploadMode is 'new'. */
        public string $uploadSlotKey = '',
        /** The rejected document when $uploadMode is 'revision'. */
        public string $uploadDocumentId = '',
    ) {}

    /**
     * Route parameters of "app_activities" opening this obligation's activity — and, for one still
     * waiting for a file, landing on its row.
     *
     * @return array<string, string>
     */
    public function linkParams(): array
    {
        $params = ['category' => $this->activity->getCategory()->getId()->toRfc4122(), 'activity' => $this->activity->getId()->toRfc4122()];
        if ($this->slotKey !== '') {
            $params['slot'] = $this->slotKey;
        }

        return $params;
    }

    /** Most urgent first (see ActivityObligationStatus::urgency()), then soonest deadline. */
    public static function compareByUrgency(self $a, self $b): int
    {
        return [$a->status->urgency(), $a->deadline] <=> [$b->status->urgency(), $b->deadline];
    }
}
