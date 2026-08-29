<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ActivityCompletion;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Model\ProfileAssignmentRow;
use App\Repository\ActivityCompletionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns everything about an activity's expected submissions (per-teacher) and completion state —
 * shared between ActivityBrowserComponent (the "Actividades" section) and the dashboard activity
 * summary, so both compute the exact same status for the exact same activity/owner.
 */
final class ActivityCompletionChecker
{
    public function __construct(
        private readonly ActivitySubmissionSlotBuilder $slotBuilder,
        private readonly ActivityCompletionRepository $completions,
        private readonly DocumentTreeAccessChecker $access,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return ActivitySubmissionSlot[] every expected submission of $activity. */
    public function getAllSlots(Activity $activity): array
    {
        return $this->slotBuilder->buildSlots($activity);
    }

    /** @return ActivitySubmissionSlot[] the slots $teacher is personally responsible for. */
    public function getMySlots(Teacher $teacher, Activity $activity): array
    {
        $folder = $activity->getFolder();
        if ($folder === null) {
            return [];
        }

        $canManage = $this->access->canManageFolder($teacher, $folder);

        return array_values(array_filter(
            $this->getAllSlots($activity),
            fn (ActivitySubmissionSlot $slot): bool => $canManage
                || ($slot->teacher !== null
                    ? $slot->teacher === $teacher
                    : $this->access->holdsProfile($teacher, $slot->profile, $slot->listItem)),
        ));
    }

    /**
     * @return ActivitySubmissionSlot[] the slots $teacher personally holds the upload profile for
     *         — unlike getMySlots(), never widened by folder-management rights. Used where "mine"
     *         must mean "I'm the one who has to upload it", not "I oversee it" (the dashboard's
     *         activity summary, not the "Actividades" browsing/review UI).
     */
    public function getMyOwnedSlots(Teacher $teacher, Activity $activity): array
    {
        if ($activity->getFolder() === null) {
            return [];
        }

        return array_values(array_filter(
            $this->getAllSlots($activity),
            fn (ActivitySubmissionSlot $slot): bool => $slot->teacher !== null
                ? $slot->teacher === $teacher
                : $this->access->holdsProfile($teacher, $slot->profile, $slot->listItem),
        ));
    }

    public function resolveSlot(Activity $activity, ActivitySubmissionSlot $slot): ?Document
    {
        return $this->slotBuilder->resolveSlot($activity, $slot);
    }

    /** Whether a teacher's own completion is tracked as a single "me" owner (Individual scope, or no folder at all). */
    public function hasIndividualCompletionOwner(Activity $activity): bool
    {
        return !$activity->requiresSubmissions() || $activity->getSubmissionScope() === ActivitySubmissionScope::Individual;
    }

    /** @return list<array{profile: SpecificProfile, listItem: ?ListItem}> distinct upload rows $teacher holds among this activity's slots (ByProfile scope only). */
    public function getMyCompletionOwners(Teacher $teacher, Activity $activity): array
    {
        if ($this->hasIndividualCompletionOwner($activity)) {
            return [];
        }

        $seen   = [];
        $owners = [];
        foreach ($this->getMySlots($teacher, $activity) as $slot) {
            $key = ProfileAssignmentRow::keyFor($slot->profile, $slot->listItem);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $owners[]   = ['profile' => $slot->profile, 'listItem' => $slot->listItem];
        }

        return $owners;
    }

    /** @return list<array{profile: SpecificProfile, listItem: ?ListItem}> distinct upload rows $teacher personally holds among this activity's slots (ByProfile scope only), ignoring any folder-management rights — see getMyOwnedSlots(). */
    public function getMyOwnedCompletionOwners(Teacher $teacher, Activity $activity): array
    {
        if ($this->hasIndividualCompletionOwner($activity)) {
            return [];
        }

        $seen   = [];
        $owners = [];
        foreach ($this->getMyOwnedSlots($teacher, $activity) as $slot) {
            $key = ProfileAssignmentRow::keyFor($slot->profile, $slot->listItem);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $owners[]   = ['profile' => $slot->profile, 'listItem' => $slot->listItem];
        }

        return $owners;
    }

    /**
     * Every obligation $teacher personally owns for $activity, by upload profile — a no-folder
     * activity applies to every teacher individually; a folder-backed one only if $teacher
     * actually holds an upload slot (ignoring folder-management rights, see getMyOwnedSlots());
     * ByProfile scope can yield more than one owner row (e.g. head of two departments). Shared by
     * the dashboard activity summary and the calendar.
     *
     * @return list<array{profile: ?SpecificProfile, listItem: ?ListItem, teacher: ?Teacher, label: ?string, key: string}>
     */
    public function getMyOwnedObligations(Teacher $teacher, Activity $activity): array
    {
        if (!$activity->requiresSubmissions()) {
            return [['profile' => null, 'listItem' => null, 'teacher' => $teacher, 'label' => null, 'key' => '']];
        }

        if ($this->hasIndividualCompletionOwner($activity)) {
            if ($this->getMyOwnedSlots($teacher, $activity) === []) {
                return [];
            }

            return [['profile' => null, 'listItem' => null, 'teacher' => $teacher, 'label' => null, 'key' => '']];
        }

        return array_map(
            static fn (array $owner): array => [
                'profile'  => $owner['profile'],
                'listItem' => $owner['listItem'],
                'teacher'  => null,
                'label'    => $owner['profile']->getName() . ($owner['listItem'] !== null ? ' ' . $owner['listItem']->getName() : ''),
                'key'      => ProfileAssignmentRow::keyFor($owner['profile'], $owner['listItem']),
            ],
            $this->getMyOwnedCompletionOwners($teacher, $activity),
        );
    }

    public function isCompletedFor(Activity $activity, ?SpecificProfile $profile, ?ListItem $listItem, ?Teacher $teacher): bool
    {
        if ($activity->isAutoComplete()) {
            foreach ($this->getAllSlots($activity) as $slot) {
                $owns = $teacher !== null
                    ? $slot->teacher === $teacher
                    : ($slot->profile === $profile && $slot->listItem === $listItem && $slot->teacher === null);
                if (!$owns) {
                    continue;
                }
                if ($this->resolveSlot($activity, $slot)?->getActiveRevision() === null) {
                    return false;
                }
            }

            return true;
        }

        return $this->completions->findOneForOwner($activity, $teacher, $profile, $listItem) !== null;
    }

    /**
     * Creates an ActivityCompletion for the given owner unless the activity is auto-complete or
     * one already exists. Does not flush — the caller decides when. Returns whether it created one.
     */
    public function markCompleted(Activity $activity, ?Teacher $targetTeacher, ?SpecificProfile $profile, ?ListItem $listItem, Teacher $completedBy): bool
    {
        if ($activity->isAutoComplete()) {
            return false;
        }

        if ($this->completions->findOneForOwner($activity, $targetTeacher, $profile, $listItem) !== null) {
            return false;
        }

        $this->em->persist(new ActivityCompletion($activity, $targetTeacher, $profile, $listItem, $completedBy));

        return true;
    }
}
