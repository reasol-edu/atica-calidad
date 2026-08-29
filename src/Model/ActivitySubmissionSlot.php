<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;

/**
 * One expected submission of an activity — not persisted, built on the fly by
 * ActivitySubmissionSlotBuilder to give a uniform unit to render (empty dropzone or existing
 * Document) and to compute statistics/completion over. $profile/$listItem identify which of the
 * activity's folder's upload rows this slot belongs to (see
 * DocumentTreeAccessChecker::getFolderUploadRows()); $nameListItem, when the activity has a list
 * item configured, is the specific leaf that gave this slot its name (see
 * ListItem::getAssociatedProfile()/getAssociatedProfileListItem()); $teacher is only set when the
 * activity's submission scope is Individual.
 */
final readonly class ActivitySubmissionSlot
{
    public function __construct(
        public SpecificProfile $profile,
        public ?ListItem $listItem,
        public ?ListItem $nameListItem,
        public string $displayName,
        public ?Teacher $teacher,
    ) {}

    public function key(): string
    {
        $parts = [
            $this->profile->getId()->toRfc4122(),
            $this->listItem?->getId()->toRfc4122() ?? '',
            $this->nameListItem?->getId()->toRfc4122() ?? '',
            $this->teacher?->getId()->toRfc4122() ?? '',
        ];

        return implode(':', $parts);
    }
}
