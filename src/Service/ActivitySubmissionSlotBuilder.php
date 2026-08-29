<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\ListItem;
use App\Entity\Tag;
use App\Model\ActivitySubmissionSlot;
use App\Model\ProfileAssignmentRow;
use App\Repository\DocumentRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileAssignmentRepository;

/**
 * Builds the flat list of submissions an activity expects, and resolves each one to its Document
 * if anyone has already uploaded it. See DocumentTreeAccessChecker::getFolderUploadRows() for the
 * folder-side half of this (which profile/subperfil rows the folder accepts for upload) and
 * ListItem::getAssociatedProfile()/getAssociatedProfileListItem() for the list-item-to-subperfil
 * association that lets a row produce more than one named submission.
 */
final class ActivitySubmissionSlotBuilder
{
    public function __construct(
        private readonly DocumentTreeAccessChecker $access,
        private readonly ListItemRepository $listItems,
        private readonly SpecificProfileAssignmentRepository $assignments,
        private readonly DocumentRepository $documents,
    ) {}

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

        $slots = [];
        foreach ($rowSlots as $slot) {
            foreach ($this->assignments->findTeachersHoldingProfileAndListItem($slot->profile, $slot->listItem) as $teacher) {
                $slots[] = new ActivitySubmissionSlot($slot->profile, $slot->listItem, $slot->nameListItem, $slot->displayName, $teacher);
            }
        }

        return $slots;
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
