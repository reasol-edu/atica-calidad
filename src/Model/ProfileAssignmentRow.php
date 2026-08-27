<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;

/**
 * One row over a centre's specific profiles: either a profile with no list
 * association ($listItem === null), or one leaf ("subperfil") of a
 * list-associated profile. Not persisted — built on the fly from
 * SpecificProfile/ListItem/SpecificProfileAssignment by ProfileAssignmentRowBuilder to give a
 * uniform, flat unit to filter/paginate over (the "Asignar perfiles" screen) or to pick from
 * (restricting a calendar event to specific profiles/subperfiles).
 */
final readonly class ProfileAssignmentRow
{
    /** @param Teacher[] $teachers */
    public function __construct(
        public SpecificProfile $profile,
        public ?ListItem $listItem,
        public string $displayName,
        public bool $active,
        public array $teachers,
    ) {}

    public function key(): string
    {
        return self::keyFor($this->profile, $this->listItem);
    }

    public static function keyFor(SpecificProfile $profile, ?ListItem $listItem): string
    {
        return $listItem === null
            ? $profile->getId()->toRfc4122()
            : $profile->getId()->toRfc4122() . ':' . $listItem->getId()->toRfc4122();
    }
}
