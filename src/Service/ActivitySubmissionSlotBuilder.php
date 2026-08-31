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
use Symfony\Contracts\Service\ResetInterface;

/**
 * Builds the flat list of submissions an activity expects, and resolves each one to its Document
 * if anyone has already uploaded it. See DocumentTreeAccessChecker::getFolderUploadRows() for the
 * folder-side half of this (which profile/subperfil rows the folder accepts for upload) and
 * ListItem::getAssociatedProfile()/getAssociatedProfileListItem() for the list-item-to-subperfil
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
final class ActivitySubmissionSlotBuilder implements ResetInterface
{
    /** @var array<string, Teacher[]> */
    private array $teachersHoldingCache = [];

    public function __construct(
        private readonly DocumentTreeAccessChecker $access,
        private readonly ListItemRepository $listItems,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly DocumentRepository $documents,
    ) {}

    public function reset(): void
    {
        $this->teachersHoldingCache = [];
    }

    /** @return ActivitySubmissionSlot[] */
    public function buildSlots(Activity $activity): array
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return [];
        }

        $rowSlots = [];
        foreach ($this->access->getFolderUploadRows($folder) as $row) {
            foreach ($this->slotsForRow($activity, $row) as $slot) {
                $rowSlots[] = $slot;
            }
        }

        if ($activity->getSubmissionScope() !== ActivitySubmissionScope::Individual) {
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

    /** @return ActivitySubmissionSlot[] the slot(s) a single folder upload row contributes, before any per-teacher (Individual) expansion. */
    private function slotsForRow(Activity $activity, ProfileAssignmentRow $row): array
    {
        $listItem = $activity->getListItem();
        if ($listItem === null) {
            return [new ActivitySubmissionSlot($row->profile, $row->listItem, null, $row->displayName, null)];
        }

        $tags  = $activity->getTags();
        $slots = [];
        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            if ($leaf->getAssociatedProfile() !== $row->profile || $leaf->getAssociatedProfileListItem() !== $row->listItem) {
                continue;
            }
            if (!$this->hasAllTags($leaf, $tags->toArray())) {
                continue;
            }
            $slots[] = new ActivitySubmissionSlot($row->profile, $row->listItem, $leaf, $leaf->getName(), null);
        }

        return $slots;
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
     * subperfil + name(+ first uploader, in Individual scope). Null if the slot is still empty.
     */
    public function resolveSlot(Activity $activity, ActivitySubmissionSlot $slot): ?Document
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return null;
        }

        return $this->documents->findOneByFolderProfileListItemNameAndFirstUploader(
            $folder,
            $slot->profile,
            $slot->listItem,
            $slot->displayName,
            $slot->teacher,
        );
    }
}
