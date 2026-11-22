<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Activity;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Service\DocumentTreeAccessChecker;

/**
 * Who may fill in (and deliver) the document of a slot: the same rule as uploading its file — whoever manages the folder, or
 * the one assigned to an individual slot, or anyone holding the profile (and element) the slot is owed by.
 */
final class FormSlotAccess
{
    public function __construct(
        private readonly DocumentTreeAccessChecker $access,
    ) {}

    public function canFill(Teacher $teacher, Activity $activity, ActivitySubmissionSlot $slot): bool
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return false;
        }
        if ($this->access->canManageFolder($teacher, $folder)) {
            return true;
        }

        return $slot->teacher !== null
            ? $slot->teacher->getId()->equals($teacher->getId())
            : $this->access->holdsProfile($teacher, $slot->profile, $slot->listItem);
    }
}
