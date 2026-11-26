<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\ListItem;
use App\Entity\SpecificProfile;

/**
 * One association of a list item, as every consumer reads them: an element of an associated list (`$linked`: «Departamento ›
 * Informática», «Evaluación › 1ª»; an intermediate node stands for every leaf under it) or a plain profile with no list of its own
 * (`$profile`). Exactly one of the two is set. Built by ListItem (getAssociations(), getEffectiveAssociations()), never persisted.
 */
final readonly class ListItemAssociation
{
    private function __construct(
        public ?ListItem $linked,
        public ?SpecificProfile $profile,
    ) {}

    public static function link(ListItem $linked): self
    {
        return new self($linked, null);
    }

    public static function profile(SpecificProfile $profile): self
    {
        return new self(null, $profile);
    }

    /** `l:<item id>` or `p:<profile id>`: what the pickers send and what tells two associations apart. */
    public function key(): string
    {
        return $this->linked !== null
            ? 'l:' . $this->linked->identityKey()
            : 'p:' . ($this->profile === null ? '' : self::profileKey($this->profile));
    }

    private static function profileKey(SpecificProfile $profile): string
    {
        try {
            return $profile->getId()->toRfc4122();
        } catch (\Error) {
            return 'new-' . spl_object_id($profile); // not saved yet
        }
    }

    /** The element's path inside its list, root first («Departamento › FP › Informática»), or the profile's name. */
    public function label(): string
    {
        if ($this->linked === null) {
            return $this->profile?->getName() ?? '';
        }
        $names = [];
        $seen  = [];
        for ($node = $this->linked; $node !== null && !isset($seen[spl_object_id($node)]); $node = $node->getParent()) {
            $seen[spl_object_id($node)] = true;
            array_unshift($names, $node->getName());
        }

        return implode(' › ', $names);
    }

    /** The list (root item) the linked element belongs to; null for a profile. */
    public function list(): ?ListItem
    {
        return $this->linked?->getRoot();
    }
}
