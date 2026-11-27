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
 * ListItemOwners); $teacher is only set when the
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
        /** @var list<ListItem> one value of each of the activity's dimensions, in their order (empty without dimensions) */
        public array $combination = [],
    ) {}

    /**
     * profile : subprofile : element : teacher, each a UUID or empty — and, for an activity with dimensions, a fifth part with the
     * combination (combinationKey()). Without dimensions it is exactly the four parts there always were, so what was delivered keeps
     * matching.
     */
    public function key(): string
    {
        $parts = [
            $this->profile->getId()->toRfc4122(),
            $this->listItem?->getId()->toRfc4122() ?? '',
            $this->nameListItem?->getId()->toRfc4122() ?? '',
            $this->teacher?->getId()->toRfc4122() ?? '',
        ];
        if ($this->combination !== []) {
            $parts[] = $this->combinationKey();
        }

        return implode(':', $parts);
    }

    /** The ids of the combination's values joined by «+», in the dimensions' order; '' without dimensions. */
    public function combinationKey(): string
    {
        return implode('+', array_map(static fn (ListItem $value): string => $value->getId()->toRfc4122(), $this->combination));
    }

    /**
     * The value that gives this submission its dates: the one of the activity's dimension with dates, or else its element (whose own
     * date range, if it has one, is an override of the activity's — ActivityDeadlineChecker). What every date is worked out with.
     */
    public function deadlineItem(\App\Entity\Activity $activity): ?ListItem
    {
        // The same rule as ActivityLeafResolver::datedValueOf(), on the values this slot already holds.
        foreach ($activity->getDimensions() as $index => $dimension) {
            if ($dimension->isDated()) {
                return $this->combination[$index] ?? $this->nameListItem;
            }
        }

        return $this->nameListItem;
    }

    /**
     * The ids of the combination read back from a key (its fifth part); empty for a key without one or that is not a key.
     *
     * @return list<string>
     */
    public static function combinationOf(string $key): array
    {
        $parts = self::partsOf($key);

        return $parts === null || $parts[4] === '' ? [] : explode('+', $parts[4]);
    }

    /**
     * The id of the element (leaf) a slot was named after, read back from its key (see key()): its third part. Null for a key that is
     * not one or a slot with no element — what a step of an approval chain limited to the element looks at (FormApprovalChain).
     */
    public static function leafOf(string $key): ?string
    {
        $parts = self::partsOf($key);

        return $parts === null || $parts[2] === '' ? null : $parts[2];
    }

    /**
     * Who delivers a slot, read back from its key (see key()) without building the activity's slots: the profile and, for a profile of
     * a list, its subprofile. What the rows and copies taken from a list filter by (FormListOptions). Null for a key that is not one.
     *
     * @return array{profile: string, subprofile: ?string}|null
     */
    public static function deliveredBy(string $key): ?array
    {
        $parts = self::partsOf($key);
        if ($parts === null || $parts[0] === '') {
            return null;
        }

        return ['profile' => $parts[0], 'subprofile' => $parts[1] === '' ? null : $parts[1]];
    }

    /**
     * The parts of a key (see key()) — profile, subprofile, element, teacher, each a UUID or empty, and the combination ('' when the
     * key has only four parts: UUIDs joined by «+») — null for anything else. The one place that reads the format back, for
     * deliveredBy(), leafOf() and combinationOf().
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}|null
     */
    private static function partsOf(string $key): ?array
    {
        $uuid  = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        $parts = explode(':', $key);
        if (count($parts) !== 4 && count($parts) !== 5) {
            return null;
        }
        foreach (array_slice($parts, 0, 4) as $part) {
            if ($part !== '' && preg_match('/^' . $uuid . '$/', $part) !== 1) {
                return null;
            }
        }
        $combination = $parts[4] ?? '';
        if ($combination !== '' && preg_match('/^' . $uuid . '(\+' . $uuid . ')*$/', $combination) !== 1) {
            return null;
        }

        return [$parts[0], $parts[1], $parts[2], $parts[3], $combination];
    }
}
