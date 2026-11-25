<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The one place that decides who may fill in and deliver the document of a slot, and whose job it is — the two questions that used
 * to be repeated, as «the slot's teacher, otherwise whoever holds its profile», wherever a slot was looked at:
 *
 *  - canFill(): whoever manages the folder, the teacher of an individual slot, whoever holds the profile (and subprofile) the slot is
 *    owed by and, in an activity that asks for them (Activity::isTeachersDeliver()), the teachers of the slot's element.
 *  - owns(): whose task it is (Mis actividades, the bell, reminders, delays): the teacher of an individual slot; otherwise, when the
 *    element has teachers, those, and only when it has none whoever holds the profile — so the head of department is not asked for
 *    what a teacher of the department has to deliver. Never by managing the folder.
 *
 * The teachers never create or remove slots (their key does not change with them, so a draft stays put when a substitute arrives),
 * and only those active and in the active course count (see LeafTeachers).
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class SlotResponsibility implements ResetInterface
{
    /** @var array<string, list<Teacher>> teachersOf() per (activity, element) for this request */
    private array $teachersMemo = [];

    /** @var array<string, bool> canManageFolder() per (teacher, folder) for this request */
    private array $managesMemo = [];

    public function __construct(
        private readonly DocumentTreeAccessChecker $access,
        private readonly LeafTeachers $leafTeachers,
    ) {}

    /**
     * The teachers whose delivery the slot is: those associated with its element (or an ancestor of it) who are in the active course.
     * Empty when the activity does not ask for them, for an individual slot, or when the slot has no element.
     *
     * @return list<Teacher>
     */
    public function teachersOf(Activity $activity, ActivitySubmissionSlot $slot): array
    {
        $leaf = $slot->nameListItem;
        if ($leaf === null || $slot->teacher !== null || !$activity->isTeachersDeliver()) {
            return [];
        }

        return $this->teachersMemo[$activity->getId()->toRfc4122() . '|' . $leaf->getId()->toRfc4122()] ??= $this->leafTeachers->of($activity, $leaf);
    }

    public function canFill(Teacher $teacher, Activity $activity, ActivitySubmissionSlot $slot): bool
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return false;
        }
        if ($this->managesFolder($teacher, $folder)) {
            return true;
        }
        if ($slot->teacher !== null) {
            return $slot->teacher->getId()->equals($teacher->getId());
        }

        return $this->access->holdsProfile($teacher, $slot->profile, $slot->listItem)
            || LeafTeachers::isAmong($teacher, $this->teachersOf($activity, $slot));
    }

    public function owns(Teacher $teacher, Activity $activity, ActivitySubmissionSlot $slot): bool
    {
        if ($slot->teacher !== null) {
            return $slot->teacher->getId()->equals($teacher->getId());
        }

        $teachers = $this->teachersOf($activity, $slot);
        if ($teachers !== []) {
            return LeafTeachers::isAmong($teacher, $teachers);
        }

        return $this->access->holdsProfile($teacher, $slot->profile, $slot->listItem);
    }

    /** canManageFolder() once per teacher and folder: getMySlots() asks canFill() for every slot of an activity, all in one folder. */
    private function managesFolder(Teacher $teacher, Folder $folder): bool
    {
        return $this->managesMemo[$teacher->getId()->toRfc4122() . '|' . $folder->getId()->toRfc4122()] ??= $this->access->canManageFolder($teacher, $folder);
    }

    public function postFlush(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->teachersMemo = [];
        $this->managesMemo  = [];
    }
}
