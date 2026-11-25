<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Activity;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Service\SlotResponsibility;

/**
 * Who may fill in (and deliver) the document of a slot: the same rule as uploading its file — whoever manages the folder, or
 * the one assigned to an individual slot, or anyone holding the profile (and element) the slot is owed by, or the teachers of the
 * slot's element in an activity that asks for them. SlotResponsibility decides; this stays as the form side's name for it.
 */
final class FormSlotAccess
{
    public function __construct(
        private readonly SlotResponsibility $responsibility,
    ) {}

    public function canFill(Teacher $teacher, Activity $activity, ActivitySubmissionSlot $slot): bool
    {
        return $this->responsibility->canFill($teacher, $activity, $slot);
    }
}
