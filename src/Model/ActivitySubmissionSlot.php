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
 * ListItem::getEffectiveProfileAssociations()); $teacher is only set when the
 * activity's submission scope is Individual; $ownerLabel names the profile/subprofile that owes
 * the slot, set only when the same element is owed by several of them (so the rows can be told
 * apart) and null otherwise.
 */
final readonly class ActivitySubmissionSlot
{
    public function __construct(
        public SpecificProfile $profile,
        public ?ListItem $listItem,
        public ?ListItem $nameListItem,
        public string $displayName,
        public ?Teacher $teacher,
        public ?string $ownerLabel = null,
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

    /**
     * The id of the element (leaf) a slot was named after, read back from its key (see key()): its third part. Null for a key that is
     * not one or a slot with no element — what a step of an approval chain limited to the element looks at (FormApprovalChain).
     */
    public static function leafOf(string $key): ?string
    {
        $parts = explode(':', $key);
        if (count($parts) !== 4 || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $parts[2]) !== 1) {
            return null;
        }

        return $parts[2];
    }

    /**
     * Who delivers a slot, read back from its key (see key()) without building the activity's slots: the profile and, for a profile of
     * a list, its subprofile. What the rows and copies taken from a list filter by (FormListOptions). Null for a key that is not one.
     *
     * @return array{profile: string, subprofile: ?string}|null
     */
    public static function deliveredBy(string $key): ?array
    {
        $parts = explode(':', $key);
        $uuid  = static fn (string $v): bool => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) === 1;
        if (count($parts) !== 4 || !$uuid($parts[0]) || ($parts[1] !== '' && !$uuid($parts[1]))) {
            return null;
        }

        return ['profile' => $parts[0], 'subprofile' => $parts[1] === '' ? null : $parts[1]];
    }
}
