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
 * ListItem::getEffectiveProfileAssociations() for the list-item-to-subprofile associations that
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
    ) {}

    public function reset(): void
    {
        $this->teachersHoldingCache  = [];
        $this->documentsByFolderCycle = [];
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
        );
    }

    /**
     * The slots for an activity described by plain values instead of a saved one — what the
     * activity form uses to say how many submissions the settings being edited would ask for.
     *
     * @param ProfileAssignmentRow[] $uploadRows see DocumentTreeAccessChecker::getFolderUploadRows()
     * @param bool                   $fullPath   how the leaves are named, see submissionName()
     *
     * @return ActivitySubmissionSlot[]
     */
    public function buildSlotsFor(?ListItem $listItem, ActivitySubmissionScope $scope, array $uploadRows, bool $fullPath = true): array
    {
        $rowSlots = $listItem === null
            ? $this->plainRowSlots($uploadRows)
            : $this->listItemRowSlots($listItem, $uploadRows, $fullPath);

        if ($scope !== ActivitySubmissionScope::Individual) {
            return $rowSlots;
        }

        $this->warmTeachersHoldingCache($rowSlots);

        $slots = [];
        foreach ($rowSlots as $slot) {
            $key = ProfileAssignmentRow::keyFor($slot->profile, $slot->listItem);
            foreach ($this->teachersHoldingCache[$key] ?? [] as $teacher) {
                $slots[] = new ActivitySubmissionSlot($slot->profile, $slot->listItem, $slot->nameListItem, $slot->displayName, $teacher, $slot->ownerLabel);
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
     * @param  ProfileAssignmentRow[] $uploadRows
     * @return ActivitySubmissionSlot[]
     */
    private function plainRowSlots(array $uploadRows): array
    {
        $slots = [];
        foreach ($uploadRows as $row) {
            $slots[] = new ActivitySubmissionSlot($row->profile, $row->listItem, null, $row->displayName, null);
        }

        return $slots;
    }

    /**
     * One slot per leaf descendant of the activity's selected list element (so selecting a branch
     * pulls in every leaf under it) and per folder upload row that owns it, each named with its
     * path below that element — see uploadRowsForLeaf() for who owns a leaf.
     *
     * @param  ProfileAssignmentRow[] $uploadRows
     * @return ActivitySubmissionSlot[]
     */
    private function listItemRowSlots(ListItem $listItem, array $uploadRows, bool $fullPath): array
    {
        $slots   = [];
        $perName = [];
        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            $name = $this->submissionName($listItem, $leaf, $fullPath);
            foreach ($this->uploadRowsForLeaf($leaf, $uploadRows) as $row) {
                $slots[]         = new ActivitySubmissionSlot($row->profile, $row->listItem, $leaf, $name, null, $row->displayName);
                $perName[$name]  = ($perName[$name] ?? 0) + 1;
            }
        }

        // Only worth saying who owes each row when some element is owed by more than one.
        if ($perName === [] || max($perName) < 2) {
            return array_map(
                static fn (ActivitySubmissionSlot $s): ActivitySubmissionSlot => new ActivitySubmissionSlot($s->profile, $s->listItem, $s->nameListItem, $s->displayName, null),
                $slots,
            );
        }

        return $slots;
    }

    /**
     * The folder upload rows a leaf's submission is owed by — one submission per row. The leaf's
     * associations are the ones it has itself plus those of all its ancestors (see
     * ListItem::getEffectiveProfileAssociations()); each stands for the folder rows it
     * covers: the row of a plain profile, the very row of a leaf subprofile, or — for an
     * intermediate node of the profile's list — every row of a leaf under it, so each of those
     * owes its own submission. Only rows the folder accepts count (they come from $uploadRows).
     * Without any association, the folder's single upload row, but only when there is exactly one;
     * none when there are several (no way to tell which one owns the leaf).
     *
     * @param  ProfileAssignmentRow[] $uploadRows
     * @return list<ProfileAssignmentRow>
     */
    private function uploadRowsForLeaf(ListItem $leaf, array $uploadRows): array
    {
        return $this->uploadRowsForAssociations($leaf->getEffectiveProfileAssociations()->getValues(), $uploadRows);
    }

    /**
     * The same rule over any set of associations — what app:list-items:inheritance-report uses to
     * count submissions under the former rule too.
     *
     * @param  list<\App\Entity\ListItemProfile> $associations
     * @param  ProfileAssignmentRow[]            $uploadRows
     * @return list<ProfileAssignmentRow>
     */
    public function uploadRowsForAssociations(array $associations, array $uploadRows): array
    {
        if ($associations === []) {
            return count($uploadRows) === 1 ? [current($uploadRows)] : [];
        }

        $owners = [];
        foreach ($associations as $association) {
            $profile    = $association->getSpecificProfile();
            $subprofile = $association->getSubprofile();
            $covered    = $subprofile === null ? [] : array_flip($this->listItems->findLeafIdsUnder($subprofile));
            foreach ($uploadRows as $row) {
                if ($row->profile !== $profile) {
                    continue;
                }
                $covers = $subprofile === null
                    ? $row->listItem === null
                    : $row->listItem !== null && isset($covered[$row->listItem->getId()->toRfc4122()]);
                if ($covers) {
                    $owners[$row->key()] = $row;
                }
            }
        }

        // Alphabetical, so the owners of one element always list in the same order.
        $owners = array_values($owners);
        usort($owners, static fn (ProfileAssignmentRow $a, ProfileAssignmentRow $b): int => strnatcasecmp($a->displayName, $b->displayName));

        return $owners;
    }

    /**
     * A submission's name: the list path from just under the activity's selected element down to
     * the leaf, joined with " › " (e.g. selecting "Materias" names a leaf "Ciencias › Física").
     * A leaf directly under the selected element — or the selected element itself, when a leaf was
     * picked — is just its own name. With $fullPath false (the activity's «solo el nombre del elemento»
     * setting) it is the leaf's own name whatever its depth («Física»): then two leaves with the same
     * name would be the same submission, which the form refuses (see duplicateLeafNames()).
     */
    public function submissionName(ListItem $selected, ListItem $leaf, bool $fullPath = true): string
    {
        if (!$fullPath) {
            return $leaf->getName();
        }

        $trail = [];
        for ($node = $leaf; $node !== null && $node !== $selected; $node = $node->getParent()) {
            array_unshift($trail, $node->getName());
        }

        return $trail === [] ? $leaf->getName() : implode(' › ', $trail);
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

        foreach ($documents as $document) {
            if ($document->getName() !== $slot->displayName
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
    }

    /** The cycle key a document uploaded for $slot right now (or at $reference) would be stamped with — see resolveSlot(). */
    public function cycleKeyFor(Activity $activity, ActivitySubmissionSlot $slot, ?\DateTimeImmutable $reference = null): int
    {
        return $reference === null
            ? $this->deadline->currentCycleKey($activity, $slot->nameListItem)
            : $this->deadline->cycleKeyNear($activity, $reference, $slot->nameListItem);
    }

    /**
     * The leaf of $activity's own list item that a persisted $document was named after — matched
     * by recomputing submissionName() for every leaf and comparing against $document's own name,
     * the same naming rule listItemRowSlots() uses, just backwards. Used wherever something about
     * $document needs to know which leaf (if any) it belongs to — e.g. stamping its cycle year at
     * creation time with that leaf's own deadline override, or deciding whether it's a past
     * occurrence's submission. Deliberately lighter than matching a full ActivitySubmissionSlot
     * (buildSlots()): it never touches the folder's upload-row/profile resolution, which runs from
     * a Doctrine entity listener (see DocumentActivityCycleListener) where other entities in the
     * same flush may not have their id assigned yet. Null when the activity has no list item, or
     * $document's name matches no current leaf (the list item changed after it was uploaded).
     */
    public function findLeafForDocument(Activity $activity, Document $document): ?ListItem
    {
        $listItem = $activity->getListItem();
        if ($listItem === null) {
            return null;
        }

        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            if ($this->submissionName($listItem, $leaf, $activity->isSubmissionNameFullPath()) === $document->getName()) {
                return $leaf;
            }
        }

        return null;
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
            $to = $newNames[$document->getName()] ?? null;
            if ($to !== null && $to !== $document->getName()) {
                $document->setName($to);
                ++$renamed;
            }
        }
        $this->reset();

        return $renamed;
    }
}
