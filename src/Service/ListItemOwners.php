<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ListItem;
use App\Model\ListItemAssociation;
use App\Model\ProfileAssignmentRow;
use App\Repository\ListItemRepository;

/**
 * Who owes an element of a list: the one rule, for the submissions of an activity (ActivitySubmissionSlotBuilder), the rows and copies
 * a model takes from a list (FormListOptions), the tables of deliveries (FormDeliveredRows) and the steps limited to the element
 * (FormApprovalChain). An element is associated with elements of other lists (and, rarely, with plain profiles); a profile that has a
 * list owes it through the element of its list the element is associated with — whatever that profile is: two profiles on the same
 * list both owe it. Associations accumulate down the tree (ListItem::getEffectiveAssociations()).
 *
 * Holds nothing of its own between calls (the leaves under a node are ListItemRepository's, reset with it).
 */
final class ListItemOwners
{
    public function __construct(
        private readonly ListItemRepository $listItems,
    ) {}

    /**
     * The folder upload rows that owe $leaf's submission — one submission per row: the row of a subprofile its associations cover (an
     * intermediate node covers every leaf under it), the row of a plain profile it is associated with. With no association at all,
     * the folder's single upload row, but only when there is exactly one; none when there are several (no way to tell which one owns
     * the leaf). Alphabetical, so the owners of one element always list in the same order.
     *
     * @param  ProfileAssignmentRow[] $uploadRows
     * @return list<ProfileAssignmentRow>
     */
    public function ownersOf(ListItem $leaf, array $uploadRows): array
    {
        return $this->forAssociations($leaf->getEffectiveAssociations(), $uploadRows);
    }

    /**
     * The same rule over any set of associations.
     *
     * @param  list<ListItemAssociation> $associations
     * @param  ProfileAssignmentRow[]    $uploadRows
     * @return list<ProfileAssignmentRow>
     */
    public function forAssociations(array $associations, array $uploadRows): array
    {
        if ($associations === []) {
            return count($uploadRows) === 1 ? [current($uploadRows)] : [];
        }

        $covered = [];
        $plain   = [];
        foreach ($associations as $association) {
            if ($association->linked !== null) {
                foreach ($this->listItems->findLeafIdsUnder($association->linked) as $id) {
                    $covered[$id] = true;
                }
            } elseif ($association->profile !== null) {
                $plain[$association->profile->getId()->toRfc4122()] = true;
            }
        }

        $owners = [];
        foreach ($uploadRows as $row) {
            $covers = $row->listItem === null
                ? isset($plain[$row->profile->getId()->toRfc4122()])
                : isset($covered[$row->listItem->getId()->toRfc4122()]);
            if ($covers) {
                $owners[$row->key()] = $row;
            }
        }

        $owners = array_values($owners);
        usort($owners, static fn (ProfileAssignmentRow $a, ProfileAssignmentRow $b): int => strnatcasecmp($a->displayName, $b->displayName));

        return $owners;
    }

    /**
     * Whether $item belongs to who delivers a document: one of its associations (own or inherited) is the subprofile that delivers it
     * or a node above it, for a profile of a list; with no subprofile, the plain profile itself. A document of one teacher (an
     * individual delivery) also has the elements that teacher is associated with, own or inherited: the subjects they teach.
     *
     * @param array{profile: string, subprofile: ?string, teacher?: ?string} $who
     * @param array<string, ListItem>                                        $index the centre's items by id
     */
    public static function belongsTo(ListItem $item, array $who, array $index): bool
    {
        $teacher = $who['teacher'] ?? null;
        if ($teacher !== null) {
            for ($node = $item; $node !== null; $node = $node->getParent()) {
                foreach ($node->getTeachers() as $associated) {
                    if ($associated->getId()->toRfc4122() === $teacher) {
                        return true;
                    }
                }
            }
        }

        // The subprofile and the items above it: an association to any of them covers it.
        $covering = [];
        for ($node = $who['subprofile'] === null ? null : ($index[$who['subprofile']] ?? null); $node !== null && !isset($covering[$node->getId()->toRfc4122()]); $node = $node->getParent()) {
            $covering[$node->getId()->toRfc4122()] = true;
        }
        foreach ($item->getEffectiveAssociations() as $association) {
            if ($who['subprofile'] === null
                ? $association->profile?->getId()->toRfc4122() === $who['profile']
                : ($association->linked !== null && isset($covering[$association->linked->getId()->toRfc4122()]))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The elements of $list (a profile's list, or a node of it) that $item is associated with, own and inherited: the subprofiles a
     * step limited to the element is decided by (FormApprovalChain).
     *
     * @return list<ListItem>
     */
    public static function linkedUnder(ListItem $item, ListItem $list): array
    {
        $found = [];
        foreach ($item->getEffectiveAssociations() as $association) {
            if ($association->linked !== null && $association->linked->isUnder($list)) {
                $found[] = $association->linked;
            }
        }

        return $found;
    }
}
