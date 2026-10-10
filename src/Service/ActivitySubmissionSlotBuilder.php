<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\ListItem;
use App\Entity\Tag;
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
 * ListItem::getAssociatedProfile()/getAssociatedProfileListItem() for the list-item-to-subprofile
 * association that lets a row produce more than one named submission.
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
            $activity->getTags()->toArray(),
            $activity->getSubmissionScope(),
            $this->access->getFolderUploadRows($folder),
        );
    }

    /**
     * The slots for an activity described by plain values instead of a saved one — what the
     * activity form uses to say how many submissions the settings being edited would ask for.
     *
     * @param Tag[]                $tags       an element of the list is only asked for when it carries all of them
     * @param ProfileAssignmentRow[] $uploadRows see DocumentTreeAccessChecker::getFolderUploadRows()
     *
     * @return ActivitySubmissionSlot[]
     */
    public function buildSlotsFor(?ListItem $listItem, array $tags, ActivitySubmissionScope $scope, array $uploadRows): array
    {
        $rowSlots = $listItem === null
            ? $this->plainRowSlots($uploadRows)
            : $this->listItemRowSlots($tags, $listItem, $uploadRows);

        if ($scope !== ActivitySubmissionScope::Individual) {
            return $rowSlots;
        }

        $this->warmTeachersHoldingCache($rowSlots);

        $slots = [];
        foreach ($rowSlots as $slot) {
            $key = ProfileAssignmentRow::keyFor($slot->profile, $slot->listItem);
            foreach ($this->teachersHoldingCache[$key] ?? [] as $teacher) {
                $slots[] = new ActivitySubmissionSlot($slot->profile, $slot->listItem, $slot->nameListItem, $slot->displayName, $teacher);
            }
        }

        return $slots;
    }

    /**
     * The leaves of $listItem an activity with these tags actually asks for — all of them when it
     * has no tags, else only those carrying every tag (inherited ones included). What the activity
     * form lists under "Plazos por elemento", so it never offers a deadline for an element the
     * activity doesn't cover.
     *
     * @param  Tag[]      $tags
     * @return ListItem[]
     */
    public function leavesFor(ListItem $listItem, array $tags): array
    {
        return array_values(array_filter(
            $this->listItems->findLeafDescendants($listItem),
            fn (ListItem $leaf): bool => $this->hasAllTags($leaf, $tags),
        ));
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
     * pulls in every leaf under it), each named with its path below that element. A leaf is
     * attributed to the folder upload row its own profile/subprofile association points at; a leaf
     * with no association falls back to the folder's single upload row, and yields nothing when
     * the folder has several (no way to tell which one owns it) or when its association isn't
     * among the rows the folder accepts. The activity's tag filter still applies per leaf
     * (inherited ancestor tags included).
     *
     * @param  Tag[]                  $tags
     * @param  ProfileAssignmentRow[] $uploadRows
     * @return ActivitySubmissionSlot[]
     */
    private function listItemRowSlots(array $tags, ListItem $listItem, array $uploadRows): array
    {
        $slots = [];
        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            if (!$this->hasAllTags($leaf, $tags)) {
                continue;
            }

            $row = $this->uploadRowForLeaf($leaf, $uploadRows);
            if ($row === null) {
                continue;
            }

            $slots[] = new ActivitySubmissionSlot($row->profile, $row->listItem, $leaf, $this->submissionName($listItem, $leaf), null);
        }

        return $slots;
    }

    /**
     * The folder upload row a leaf's submission belongs to: the one matching the leaf's own
     * (associated profile, associated subprofile) when it has an association; otherwise the
     * folder's single upload row, but only when there is exactly one. Null when an associated
     * leaf's target isn't among the folder's rows, or an unassociated leaf can't be pinned to a
     * single row.
     *
     * @param ProfileAssignmentRow[] $uploadRows
     */
    private function uploadRowForLeaf(ListItem $leaf, array $uploadRows): ?ProfileAssignmentRow
    {
        $profile = $leaf->getAssociatedProfile();
        if ($profile === null) {
            return count($uploadRows) === 1 ? current($uploadRows) : null;
        }

        $subprofile = $leaf->getAssociatedProfileListItem();
        foreach ($uploadRows as $row) {
            if ($row->profile === $profile && $row->listItem === $subprofile) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A submission's name: the list path from just under the activity's selected element down to
     * the leaf, joined with " › " (e.g. selecting "Materias" names a leaf "Ciencias › Física").
     * A leaf directly under the selected element — or the selected element itself, when a leaf was
     * picked — is just its own name.
     */
    public function submissionName(ListItem $selected, ListItem $leaf): string
    {
        $trail = [];
        for ($node = $leaf; $node !== null && $node !== $selected; $node = $node->getParent()) {
            array_unshift($trail, $node->getName());
        }

        return $trail === [] ? $leaf->getName() : implode(' › ', $trail);
    }

    /** @param Tag[] $requiredTags */
    private function hasAllTags(ListItem $leaf, array $requiredTags): bool
    {
        if ($requiredTags === []) {
            return true;
        }

        $effective = $leaf->getEffectiveTags();
        foreach ($requiredTags as $tag) {
            if (!$effective->contains($tag)) {
                return false;
            }
        }

        return true;
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
            if ($this->submissionName($listItem, $leaf) === $document->getName()) {
                return $leaf;
            }
        }

        return null;
    }
}
