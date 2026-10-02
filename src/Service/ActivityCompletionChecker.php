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
        private readonly ActivityDeadlineChecker $deadline,
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

    /** $slot's submission for the occurrence $reference belongs to ("now" if null). */
    public function resolveSlot(Activity $activity, ActivitySubmissionSlot $slot, ?\DateTimeImmutable $reference = null): ?Document
    {
        return $this->slotBuilder->resolveSlot($activity, $slot, $reference);
    }

    /** Whether a teacher's own completion is tracked as a single "me" owner (Individual scope, or no folder at all). */
    public function hasIndividualCompletionOwner(Activity $activity): bool
    {
        return !$activity->requiresSubmissions() || $activity->getSubmissionScope() === ActivitySubmissionScope::Individual;
    }

    /**
     * Whether a manual (folder-less) activity even applies to $teacher — general activities and
     * folder-backed ones (ownership instead comes from the folder's own upload profiles, checked
     * separately) always do. A restricted activity applies to a teacher holding ANY of its
     * profile/subprofile restrictions, using the exact same holdsProfile() semantics folders use
     * for their own upload-profile matching (a "whole profile" restriction — $listItem === null on
     * a list-associated profile — matches a teacher holding any of its leaves).
     */
    public function isApplicableToTeacher(Teacher $teacher, Activity $activity): bool
    {
        if ($activity->requiresSubmissions() || $activity->isGeneral()) {
            return true;
        }

        foreach ($activity->getProfileRestrictions() as $restriction) {
            if ($this->access->holdsProfile($teacher, $restriction->getSpecificProfile(), $restriction->getListItem())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $teacher holds one of a manual activity's own responsible profiles — who can see its
     * completion stats alongside a responsable de calidad/admin (see
     * ActivityBrowserComponent::canManageActivity()), independent of any folder-management rights.
     * Always false for a folder-backed activity, which has no responsible profiles of its own.
     */
    public function isResponsibleFor(Teacher $teacher, Activity $activity): bool
    {
        foreach ($activity->getResponsibleProfiles() as $responsible) {
            if ($this->access->holdsProfile($teacher, $responsible->getSpecificProfile(), $responsible->getListItem())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Distinct upload rows $teacher holds among this activity's slots (ByProfile scope only) — one
     * per (profile, listItem, leaf): a list-backed activity names each leaf separately even when
     * several share the very same upload row, so each is tracked (and can be marked done) on its
     * own, with its own deadline — see ActivityObligationFinder.
     *
     * @return list<array{profile: SpecificProfile, listItem: ?ListItem, leaf: ?ListItem, displayName: string}>
     */
    public function getMyCompletionOwners(Teacher $teacher, Activity $activity): array
    {
        if ($this->hasIndividualCompletionOwner($activity)) {
            return [];
        }

        return $this->groupSlotsByOwner($this->getMySlots($teacher, $activity));
    }

    /**
     * @return list<array{profile: SpecificProfile, listItem: ?ListItem, leaf: ?ListItem, displayName: string}>
     *         distinct upload rows $teacher personally holds among this activity's slots (ByProfile
     *         scope only), ignoring any folder-management rights — see getMyOwnedSlots().
     */
    public function getMyOwnedCompletionOwners(Teacher $teacher, Activity $activity): array
    {
        if ($this->hasIndividualCompletionOwner($activity)) {
            return [];
        }

        return $this->groupSlotsByOwner($this->getMyOwnedSlots($teacher, $activity));
    }

    /**
     * @param  ActivitySubmissionSlot[] $slots
     * @return list<array{profile: SpecificProfile, listItem: ?ListItem, leaf: ?ListItem, displayName: string}>
     */
    private function groupSlotsByOwner(array $slots): array
    {
        $seen   = [];
        $owners = [];
        foreach ($slots as $slot) {
            $key = ProfileAssignmentRow::keyFor($slot->profile, $slot->listItem) . ':' . ($slot->nameListItem?->getId()->toRfc4122() ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $owners[]   = ['profile' => $slot->profile, 'listItem' => $slot->listItem, 'leaf' => $slot->nameListItem, 'displayName' => $slot->displayName];
        }

        return $owners;
    }

    /**
     * Every obligation $teacher personally owns for $activity, by upload profile — a no-folder
     * activity applies to every teacher individually; a folder-backed one only if $teacher
     * actually holds an upload slot (ignoring folder-management rights, see getMyOwnedSlots());
     * ByProfile scope can yield more than one owner row (e.g. head of two departments), and a
     * list-backed activity yields one per leaf even when several share the same upload row — each
     * is an independent obligation, with its own deadline and status (see ActivityObligationFinder).
     * Shared by the dashboard activity summary and the calendar.
     *
     * @return list<array{profile: ?SpecificProfile, listItem: ?ListItem, leaf: ?ListItem, teacher: ?Teacher, label: ?string, key: string}>
     */
    public function getMyOwnedObligations(Teacher $teacher, Activity $activity): array
    {
        if (!$activity->requiresSubmissions()) {
            if (!$this->isApplicableToTeacher($teacher, $activity)) {
                return [];
            }

            return [['profile' => null, 'listItem' => null, 'leaf' => null, 'teacher' => $teacher, 'label' => null, 'key' => '']];
        }

        if ($this->hasIndividualCompletionOwner($activity)) {
            if ($this->getMyOwnedSlots($teacher, $activity) === []) {
                return [];
            }

            return [['profile' => null, 'listItem' => null, 'leaf' => null, 'teacher' => $teacher, 'label' => null, 'key' => '']];
        }

        return array_map(
            static fn (array $owner): array => [
                'profile'  => $owner['profile'],
                'listItem' => $owner['listItem'],
                'leaf'     => $owner['leaf'],
                'teacher'  => null,
                'label'    => $owner['profile']->getName() . ($owner['listItem'] !== null ? ' ' . $owner['listItem']->getName() : '')
                              . ($owner['leaf'] !== null ? ' · ' . $owner['displayName'] : ''),
                'key'      => ProfileAssignmentRow::keyFor($owner['profile'], $owner['listItem']) . ':' . ($owner['leaf']?->getId()->toRfc4122() ?? ''),
            ],
            $this->getMyOwnedCompletionOwners($teacher, $activity),
        );
    }

    /**
     * Whether the owner has completed the occurrence of $activity that $reference belongs to
     * ("now" if null — the calendar passes the day it's showing, which may be in another
     * academic year). $leaf narrows a ByProfile owner down to one specific leaf of a list-backed
     * activity, when its upload row covers more than one — each is tracked independently.
     */
    public function isCompletedFor(Activity $activity, ?SpecificProfile $profile, ?ListItem $listItem, ?Teacher $teacher, ?ListItem $leaf = null, ?\DateTimeImmutable $reference = null): bool
    {
        if ($activity->isAutoComplete()) {
            foreach ($this->getAllSlots($activity) as $slot) {
                $owns = $teacher !== null
                    ? $slot->teacher === $teacher
                    : ($slot->profile === $profile && $slot->listItem === $listItem && $slot->teacher === null);
                if (!$owns || ($leaf !== null && $slot->nameListItem !== $leaf)) {
                    continue;
                }
                if ($this->resolveSlot($activity, $slot, $reference)?->getActiveRevision() === null) {
                    return false;
                }
            }

            return true;
        }

        return $this->completions->findOneForOwner($activity, $teacher, $profile, $listItem, $leaf, $this->cycleKey($activity, $leaf, $reference)) !== null;
    }

    private function cycleKey(Activity $activity, ?ListItem $leaf = null, ?\DateTimeImmutable $reference = null): int
    {
        return $reference === null ? $this->deadline->currentCycleKey($activity, $leaf) : $this->deadline->cycleKeyNear($activity, $reference, $leaf);
    }

    /**
     * Creates an ActivityCompletion for the given owner unless the activity is auto-complete or
     * one already exists. Does not flush — the caller decides when. Returns whether it created one.
     */
    public function markCompleted(Activity $activity, ?Teacher $targetTeacher, ?SpecificProfile $profile, ?ListItem $listItem, Teacher $completedBy, ?ListItem $leaf = null): bool
    {
        if ($activity->isAutoComplete()) {
            return false;
        }

        $cycleYear = $this->cycleKey($activity, $leaf);
        if ($this->completions->findOneForOwner($activity, $targetTeacher, $profile, $listItem, $leaf, $cycleYear) !== null) {
            return false;
        }

        $this->em->persist(new ActivityCompletion($activity, $targetTeacher, $profile, $listItem, $completedBy, $cycleYear, $leaf));

        return true;
    }

    /**
     * Removes the ActivityCompletion for the given owner, if any. Auto-complete activities have
     * nothing persisted to remove — their status is always computed, never stored. Does not
     * flush — the caller decides when. Returns whether it removed one.
     */
    public function unmarkCompleted(Activity $activity, ?Teacher $targetTeacher, ?SpecificProfile $profile, ?ListItem $listItem, ?ListItem $leaf = null): bool
    {
        if ($activity->isAutoComplete()) {
            return false;
        }

        $completion = $this->completions->findOneForOwner($activity, $targetTeacher, $profile, $listItem, $leaf, $this->cycleKey($activity, $leaf));
        if ($completion === null) {
            return false;
        }

        $this->em->remove($completion);

        return true;
    }

    /**
     * Completion breakdown of a manual (folder-less) activity across $candidateTeachers — for
     * whoever manages it (see ActivityBrowserComponent::canManageActivity()), who has no folder to
     * delegate "who's done it" to otherwise. $candidateTeachers is filtered down to the ones the
     * activity actually applies to (see isApplicableToTeacher()), so a restricted activity's stats
     * never list an irrelevant teacher. Matches findOneForOwner()'s exact-identity predicate (an
     * Individual-scope completion: teacher set, profile and listItem both null) for this
     * occurrence's cycle year, so a stats row never disagrees with what the mark/unmark buttons see.
     *
     * @param  Teacher[] $candidateTeachers
     * @return list<array{teacher: Teacher, completed: bool}>
     */
    public function manualCompletionStats(Activity $activity, array $candidateTeachers): array
    {
        $cycleYear           = $this->cycleKey($activity);
        $completedTeacherIds = [];
        foreach ($this->completions->findByActivity($activity) as $completion) {
            $teacher = $completion->getTeacher();
            if ($teacher !== null && $completion->getProfile() === null && $completion->getListItem() === null
                && $completion->getCycleYear() === $cycleYear) {
                $completedTeacherIds[$teacher->getId()->toRfc4122()] = true;
            }
        }

        $rows = [];
        foreach ($candidateTeachers as $teacher) {
            if (!$this->isApplicableToTeacher($teacher, $activity)) {
                continue;
            }
            $rows[] = ['teacher' => $teacher, 'completed' => isset($completedTeacherIds[$teacher->getId()->toRfc4122()])];
        }

        return $rows;
    }
}
