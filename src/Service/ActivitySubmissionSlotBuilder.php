<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\ListItem;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Model\ProfileAssignmentRow;
use App\Repository\DocumentRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Builds the flat list of submissions an activity expects, and resolves each one to its Document
 * if anyone has already uploaded it. See DocumentTreeAccessChecker::getFolderUploadRows() for the
 * folder-side half of this (which profile/subprofile rows the folder accepts for upload) and
 * ListItemOwners for the associations of a list item (ListItem::getEffectiveAssociations()) that
 * let a row produce more than one named submission (and a leaf be owed by more than one row).
 *
 * Teachers-holding-a-profile/listItem lookups are memoized per (profile, listItem) for the life of
 * the request and, within one buildSlots() call, resolved in a single batched query rather than
 * one query per row: an Individual-scope activity needs it once per folder-upload row, and a
 * caller building slots for many activities (e.g. the "Mis actividades" tab) ends up asking many
 * different combinations — batching turns "N queries, one per row" into "one query per activity
 * with anything left uncached", and the cache then collapses repeats across activities that share
 * a category/folder. Caches Teacher entities, so — like ProfileAssignmentRowBuilder — it
 * implements ResetInterface to clear at the same point Doctrine resets its identity map, rather
 * than risk comparing a stale, detached entity against a freshly-hydrated one.
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class ActivitySubmissionSlotBuilder implements ResetInterface
{
    /** @var array<string, Teacher[]> */
    private array $teachersHoldingCache = [];

    /** @var array<string, list<Document>> the folder's documents of one cycle year, keyed "folderId|cycleYear" */
    private array $documentsByFolderCycle = [];

    public function __construct(
        private readonly DocumentTreeAccessChecker $access,
        private readonly ListItemRepository $listItems,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly DocumentRepository $documents,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly ActivityLeafResolver $leaves,
        private readonly ListItemOwners $owners,
    ) {}

    /** @var array<string, array<string, int>> by dimension: the names two of its values share (see combinationName()) */
    private array $repeatedNames = [];

    public function reset(): void
    {
        $this->teachersHoldingCache  = [];
        $this->documentsByFolderCycle = [];
        $this->repeatedNames          = [];
    }

    /** @return ActivitySubmissionSlot[] */
    public function buildSlots(Activity $activity): array
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return [];
        }

        return $this->buildSlotsFor(
            $activity->getListItem(),
            $activity->getSubmissionScope(),
            $this->access->getFolderUploadRows($folder),
            $activity->isSubmissionNameFullPath(),
            array_map(static fn (\App\Entity\ActivityDimension $d): ListItem => $d->getList(), $activity->getDimensions()),
            $activity->isSplitByElement(),
        );
    }

    /**
     * The slots for an activity described by plain values instead of a saved one — what the
     * activity form uses to say how many submissions the settings being edited would ask for.
     *
     * With dimensions, every slot is one combination of its element with one value of each dimension (ActivityDimension): of an
     * associated one (the activity's list declares its list associated), the values the element is linked to — none, no slot —; of a
     * free one, all of them. Without $splitByElement, the elements an owner owes are gathered: one slot per owner and combination.
     *
     * @param ProfileAssignmentRow[] $uploadRows see DocumentTreeAccessChecker::getFolderUploadRows()
     * @param bool                   $fullPath   how the leaves are named, see submissionName()
     * @param list<ListItem>         $dimensions the lists of the dimensions, in order
     *
     * @return ActivitySubmissionSlot[]
     */
    public function buildSlotsFor(?ListItem $listItem, ActivitySubmissionScope $scope, array $uploadRows, bool $fullPath = true, array $dimensions = [], bool $splitByElement = true): array
    {
        $rowSlots = $listItem === null
            ? $this->plainRowSlots($uploadRows, $dimensions)
            : $this->listItemRowSlots($listItem, $uploadRows, $fullPath, $dimensions, $splitByElement);

        if ($scope !== ActivitySubmissionScope::Individual) {
            return $rowSlots;
        }

        $this->warmTeachersHoldingCache($rowSlots);

        $slots = [];
        foreach ($rowSlots as $slot) {
            $key = ProfileAssignmentRow::keyFor($slot->profile, $slot->listItem);
            foreach ($this->teachersHoldingCache[$key] ?? [] as $teacher) {
                $slots[] = new ActivitySubmissionSlot($slot->profile, $slot->listItem, $slot->nameListItem, $slot->displayName, $teacher, $slot->ownerLabel, $slot->combination);
            }
        }

        return $slots;
    }

    /** @param ActivitySubmissionSlot[] $rowSlots */
    private function warmTeachersHoldingCache(array $rowSlots): void
    {
        $pending = [];
        foreach ($rowSlots as $slot) {
            $key = ProfileAssignmentRow::keyFor($slot->profile, $slot->listItem);
            if (!isset($this->teachersHoldingCache[$key])) {
                $pending[$key] = [$slot->profile, $slot->listItem];
            }
        }

        if ($pending === []) {
            return;
        }

        foreach ($this->assignments->findTeachersHoldingProfileAndListItemForPairs(array_values($pending)) as $key => $teachers) {
            $this->teachersHoldingCache[$key] = $teachers;
        }
    }

    /**
     * One shared slot per folder upload row, named after the row's profile/subprofile — the shape
     * an activity's submissions take when it isn't pinned to a list element.
     *
     * With dimensions (all free: there is no element to be linked), one per row and combination of all their values.
     *
     * @param  ProfileAssignmentRow[] $uploadRows
     * @param  list<ListItem>         $dimensions
     * @return ActivitySubmissionSlot[]
     */
    private function plainRowSlots(array $uploadRows, array $dimensions = []): array
    {
        $values = array_map(fn (ListItem $dimension): array => array_values($this->listItems->findLeafDescendants($dimension)), $dimensions);
        $slots  = [];
        foreach ($uploadRows as $row) {
            foreach (self::product($values) as $combination) {
                $slots[] = new ActivitySubmissionSlot($row->profile, $row->listItem, null, $this->combinationName($row->displayName, $combination, $dimensions), null, null, $combination);
            }
        }

        return $slots;
    }

    /**
     * One slot per leaf descendant of the activity's selected list element (so selecting a branch
     * pulls in every leaf under it) and per folder upload row that owns it, each named with its
     * path below that element — see uploadRowsForLeaf() for who owns a leaf. With dimensions, one
     * per combination of each, and without $splitByElement one per owner and combination.
     *
     * @param  ProfileAssignmentRow[] $uploadRows
     * @param  list<ListItem>         $dimensions
     * @return ActivitySubmissionSlot[]
     */
    private function listItemRowSlots(ListItem $listItem, array $uploadRows, bool $fullPath, array $dimensions = [], bool $splitByElement = true): array
    {
        $slots   = [];
        $perName = [];
        $grouped = [];
        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            $combinations = self::product($this->valuesOf($leaf, $listItem, $dimensions));
            $name         = $this->submissionName($listItem, $leaf, $fullPath);
            foreach ($this->uploadRowsForLeaf($leaf, $uploadRows) as $row) {
                foreach ($combinations as $combination) {
                    if (!$splitByElement) {
                        $key = $row->key() . '|' . implode('+', array_map(static fn (ListItem $v): string => $v->identityKey(), $combination));
                        if (!isset($grouped[$key])) {
                            $grouped[$key] = true;
                            $groupName     = $this->combinationName($dimensions === [] ? $row->displayName : '', $combination, $dimensions);
                            $slots[]       = new ActivitySubmissionSlot($row->profile, $row->listItem, null, $groupName, null, $row->displayName, $combination);
                            $perName[$groupName] = ($perName[$groupName] ?? 0) + 1;
                        }

                        continue;
                    }
                    $fullName           = $this->combinationName($name, $combination, $dimensions);
                    $slots[]            = new ActivitySubmissionSlot($row->profile, $row->listItem, $leaf, $fullName, null, $row->displayName, $combination);
                    $perName[$fullName] = ($perName[$fullName] ?? 0) + 1;
                }
            }
        }

        // Only worth saying who owes each row when some element is owed by more than one.
        if ($perName === [] || max($perName) < 2) {
            return array_map(
                static fn (ActivitySubmissionSlot $s): ActivitySubmissionSlot => new ActivitySubmissionSlot($s->profile, $s->listItem, $s->nameListItem, $s->displayName, null, null, $s->combination),
                $slots,
            );
        }

        return $slots;
    }

    /**
     * The values of each dimension for $leaf, in order: of an associated one — the activity's list ($listItem's) declares its list
     * associated — those its associations cover (own and inherited); of a free one, all of its leaves.
     *
     * @param  list<ListItem>       $dimensions
     * @return list<list<ListItem>>
     */
    public function valuesOf(ListItem $leaf, ListItem $listItem, array $dimensions): array
    {
        $values = [];
        foreach ($dimensions as $dimension) {
            $all = array_values($this->listItems->findLeafDescendants($dimension));
            if (!$listItem->hasLinkedList($dimension)) {
                $values[] = $all;

                continue;
            }
            $linked   = $leaf->linkedLeafIdsIn($dimension, fn (ListItem $node): array => $this->listItems->findLeafIdsUnder($node));
            $values[] = array_values(array_filter($all, static fn (ListItem $value): bool => isset($linked[$value->getId()->toRfc4122()])));
        }

        return $values;
    }

    /**
     * Every combination of one value of each list, in order (the first list varies slowest); one empty combination for no lists, none
     * if any list is empty.
     *
     * @param  list<list<ListItem>> $lists
     * @return list<list<ListItem>>
     */
    private static function product(array $lists): array
    {
        $combinations = [[]];
        foreach ($lists as $values) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = [...$combination, $value];
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * «1º DAM › Programación · Final 1»: $name followed by the name of each value — with its path below the dimension when two values
     * of it are called the same («ESO/Bach. › 1ª»). Just the values when $name is ''.
     *
     * @param list<ListItem> $combination
     * @param list<ListItem> $dimensions
     */
    private function combinationName(string $name, array $combination, array $dimensions): string
    {
        $parts = $name === '' ? [] : [$name];
        foreach ($combination as $index => $value) {
            $dimension = $dimensions[$index] ?? null;
            $repeated  = $dimension === null ? [] : ($this->repeatedNames[$dimension->identityKey()] ??= array_flip($this->duplicateLeafNames($dimension)));
            $parts[]   = $dimension !== null && isset($repeated[$value->getName()])
                ? $this->leaves->submissionName($dimension, $value)
                : $value->getName();
        }

        return implode(' · ', $parts);
    }

    /**
     * The folder upload rows a leaf's submission is owed by — one submission per row (ListItemOwners holds the rule). Only rows the
     * folder accepts count (they come from $uploadRows).
     *
     * @param  ProfileAssignmentRow[] $uploadRows
     * @return list<ProfileAssignmentRow>
     */
    private function uploadRowsForLeaf(ListItem $leaf, array $uploadRows): array
    {
        return $this->owners->ownersOf($leaf, $uploadRows);
    }

    /** A submission's name after its leaf — see ActivityLeafResolver::submissionName(), which holds the rule. */
    public function submissionName(ListItem $selected, ListItem $leaf, bool $fullPath = true): string
    {
        return $this->leaves->submissionName($selected, $leaf, $fullPath);
    }

    /**
     * Whether anyone has already uploaded $slot's submission — matched by folder + profile/
     * subprofile + name(+ first uploader, in Individual scope), for the occurrence of the activity
     * $reference belongs to ("now" if null). Null if the slot is still empty.
     */
    public function resolveSlot(Activity $activity, ActivitySubmissionSlot $slot, ?\DateTimeImmutable $reference = null): ?Document
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return null;
        }

        $cycleKey = $this->cycleKeyFor($activity, $slot, $reference);
        $key      = $folder->getId()->toRfc4122() . '|' . $cycleKey;
        // One query per (folder, cycle) instead of one per slot; the match below is the same
        // identity rule as DocumentRepository::findOneByFolderProfileListItemNameAndFirstUploader().
        $documents = $this->documentsByFolderCycle[$key] ??= $this->documents->findByFolderAndCycleYearWithRevisions($folder, $cycleKey);

        $combination = $slot->combinationKey();
        foreach ($documents as $document) {
            // With dimensions the combination identifies it (not the values' names, which may be renamed): the name only has to match
            // in the element's part, before the values. Without, the name — and a document with a combination never matches.
            $sameName = $combination === ''
                ? $document->getName() === $slot->displayName && $document->getSlotCombination() === null
                : $document->getSlotCombination() === $combination
                    && self::withoutValues($document->getName(), count($slot->combination)) === self::withoutValues($slot->displayName, count($slot->combination));
            if (!$sameName
                || $document->getUploadProfile()?->getId()->toRfc4122() !== $slot->profile->getId()->toRfc4122()
                || $document->getUploadListItem()?->getId()->toRfc4122() !== $slot->listItem?->getId()->toRfc4122()
            ) {
                continue;
            }
            if ($slot->teacher !== null && $document->getFirstRevision()?->getUploadedBy()->getId()->toRfc4122() !== $slot->teacher->getId()->toRfc4122()) {
                continue;
            }

            return $document;
        }

        return null;
    }

    /** Uploads and deletions change what a slot resolves to: forget the indexed documents after any flush. */
    public function postFlush(): void
    {
        $this->documentsByFolderCycle = [];
        $this->repeatedNames          = [];
    }

    /** «1º DAM › Programación» of «1º DAM › Programación · Final 1»: a submission's name without its last $values parts. */
    public static function withoutValues(string $name, int $values): string
    {
        if ($values === 0) {
            return $name;
        }
        $parts = explode(' · ', $name);

        return implode(' · ', array_slice($parts, 0, max(0, count($parts) - $values)));
    }

    /** The cycle key a document uploaded for $slot right now (or at $reference) would be stamped with — see resolveSlot(). */
    public function cycleKeyFor(Activity $activity, ActivitySubmissionSlot $slot, ?\DateTimeImmutable $reference = null): int
    {
        return $reference === null
            ? $this->deadline->currentCycleKey($activity, $slot->deadlineItem($activity))
            : $this->deadline->cycleKeyNear($activity, $reference, $slot->deadlineItem($activity));
    }

    /** The leaf a persisted $document was named after — see ActivityLeafResolver::findLeafForDocument(), which holds the rule. */
    public function findLeafForDocument(Activity $activity, Document $document): ?ListItem
    {
        return $this->leaves->findLeafForDocument($activity, $document);
    }

    /** The element whose dates $document's submission follows — see ActivityLeafResolver::findDeadlineItemForDocument(). */
    public function findDeadlineItemForDocument(Activity $activity, Document $document): ?ListItem
    {
        return $this->leaves->findDeadlineItemForDocument($activity, $document);
    }

    /**
     * The names that more than one leaf of $listItem would have in the leaf-only naming («Física» under «Ciencias» and under
     * «Letras»): they could not be told apart, so the activity form does not allow that setting then.
     *
     * @return list<string>
     */
    public function duplicateLeafNames(ListItem $listItem): array
    {
        $seen = [];
        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            $seen[$leaf->getName()] = ($seen[$leaf->getName()] ?? 0) + 1;
        }

        return array_keys(array_filter($seen, static fn (int $n): bool => $n > 1));
    }

    /**
     * After the naming setting of $activity changed (the caller has already set the new one), the documents already delivered take the
     * name the new setting gives their leaf, so their slots keep finding them. Only those that carried exactly the old name of a leaf;
     * the caller flushes.
     *
     * @return int how many documents were renamed
     */
    public function renameDocumentsForNameMode(Activity $activity, bool $wasFullPath): int
    {
        $folder   = $activity->getFolder();
        $listItem = $activity->getListItem();
        if ($folder === null || $listItem === null || $wasFullPath === $activity->isSubmissionNameFullPath()) {
            return 0;
        }

        $newNames = [];
        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            $newNames[$this->submissionName($listItem, $leaf, $wasFullPath)] = $this->submissionName($listItem, $leaf, $activity->isSubmissionNameFullPath());
        }

        $renamed = 0;
        foreach ($this->documents->findByFolder($folder) as $document) {
            // With dimensions only the element's part of the name changes; the values after it stay.
            $combination = $document->getSlotCombination();
            $values      = $combination === null ? 0 : count(explode('+', $combination));
            $element     = self::withoutValues($document->getName(), $values);
            $to          = $newNames[$element] ?? null;
            if ($to !== null && $to !== $element) {
                $document->setName($to . substr($document->getName(), strlen($element)));
                ++$renamed;
            }
        }
        $this->reset();

        return $renamed;
    }
}
