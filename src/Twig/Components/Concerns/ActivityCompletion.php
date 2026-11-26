<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\Activity;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityWindow;
use App\Model\OwnCompletionOutcome;
use App\Service\ActivityObligationFinder;
use App\Service\ActivityWindowChecker;
use App\Service\OwnCompletionManager;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;

/**
 * Completion of an activity: windows, status and marking/unmarking it as done.
 */
trait ActivityCompletion
{
    // ── Completion ────────────────────────────────────────────────────────────

    /** Whether the current teacher's own completion is tracked as a single "me" owner (Individual scope, or no folder at all). */
    public function hasIndividualCompletionOwner(Activity $activity): bool
    {
        return $this->completion->hasIndividualCompletionOwner($activity);
    }

    /** @return array<int, array{profile: SpecificProfile, listItem: ?ListItem}> distinct upload rows the current teacher holds among this activity's slots (ByProfile scope only). */
    public function getMyCompletionOwners(Activity $activity): array
    {
        return $this->completion->getMyCompletionOwners($this->teacher(), $activity);
    }

    public function isCompletedFor(Activity $activity, ?SpecificProfile $profile, ?ListItem $listItem, ?Teacher $teacher, ?ListItem $leaf = null, string $combination = ''): bool
    {
        return $this->completion->isCompletedFor($activity, $profile, $listItem, $teacher, $leaf, null, $combination === '' ? null : $combination);
    }

    /** Submission/completion window state for the current teacher — drives the deadline notices. $leaf resolves its own override, when it has one. */
    public function getActivityWindow(Activity $activity, ?ListItem $leaf = null): ActivityWindow
    {
        return $this->windowChecker->for($activity, $this->teacher(), $leaf);
    }

    /**
     * Colour-coded status of the activity's card for the current teacher: the most urgent status
     * among their own obligations for it (an ActivityObligationStatus value, the same the
     * dashboard and "Mis actividades" show — see ActivityObligationFinder), or "neutral" when the
     * activity isn't theirs at all: someone else's activity is never painted red just because its
     * deadline passed.
     */
    public function getActivityStatus(Activity $activity): string
    {
        $status = $this->obligations->worstStatusFor($this->teacher(), $activity);

        return $status === null ? 'neutral' : $status->value;
    }

    /**
     * The obligation behind getActivityStatus(): the current teacher's most urgent own one for the
     * activity, to say in words — and with the date — what the card's colour says; null when the
     * activity isn't theirs.
     */
    public function getActivityObligation(Activity $activity): ?ActivityDashboardItem
    {
        return $this->obligations->worstItemFor($this->teacher(), $activity);
    }

    #[LiveAction]
    public function askMarkCompleted(#[LiveArg] string $activityId, #[LiveArg] string $profileId = '', #[LiveArg] string $listItemId = '', #[LiveArg] string $leafId = '', #[LiveArg] string $combination = ''): void
    {
        $this->confirmingCompleteKey = $activityId . ':' . $profileId . ':' . $listItemId . ':' . $leafId . ($combination !== '' ? ':' . $combination : '');
    }

    #[LiveAction]
    public function cancelMarkCompleted(): void
    {
        $this->confirmingCompleteKey = '';
    }

    #[LiveAction]
    public function markCompleted(#[LiveArg] string $activityId, #[LiveArg] string $profileId = '', #[LiveArg] string $listItemId = '', #[LiveArg] string $leafId = '', #[LiveArg] string $combination = ''): void
    {
        $activity = $this->findActivity($activityId);
        // Auto-complete activities have nothing to mark: their status is always computed.
        if ($activity === null || $activity->isAutoComplete()) {
            $this->confirmingCompleteKey = '';

            return;
        }

        $this->confirmingCompleteKey = '';

        // Owner validation, window check, flush and the explicit log entry live in OwnCompletionManager
        // (shared with the dashboard's "Marcar hecha").
        match ($this->ownCompletions->mark($this->teacher(), $activity, $this->centre, $profileId, $listItemId, $leafId, $combination)) {
            OwnCompletionOutcome::OutOfWindow => $this->flashError($this->t('completion.error.out_of_window')),
            OwnCompletionOutcome::Marked      => $this->flashSuccess($this->t('activity.flash.completed')),
            OwnCompletionOutcome::Unchanged   => null,
        };
    }

    /** No confirmation required — undoing a completion is low-stakes and easy to redo. */
    #[LiveAction]
    public function unmarkCompleted(#[LiveArg] string $activityId, #[LiveArg] string $profileId = '', #[LiveArg] string $listItemId = '', #[LiveArg] string $leafId = '', #[LiveArg] string $combination = ''): void
    {
        $activity = $this->findActivity($activityId);
        if ($activity === null || $activity->isAutoComplete()) {
            return;
        }

        $teacher                                   = $this->teacher();
        [$profile, $listItem, $leaf, $combination] = $this->ownCompletions->resolveOwner($teacher, $activity, $this->centre, $profileId, $listItemId, $leafId, $combination);
        $targetTeacher                             = $profile === null ? $teacher : null;

        if (!$this->completion->unmarkCompleted($activity, $targetTeacher, $profile, $listItem, $leaf, $combination)) {
            return;
        }

        $this->em->flush();

        $this->flashSuccess($this->t('activity.flash.completion_undone'));
    }

    /**
     * Marks or unmarks another teacher's completion of a manual activity — anyone who manages it
     * may act on someone else's behalf (canManageActivity(): a responsable de calidad/admin, or a
     * teacher holding one of the activity's own responsible profiles). Deliberately bypasses
     * ActivityWindowChecker: unlike the teacher's own self-service button, a manager correcting
     * someone else's record is not meant to be blocked by the deadline window.
     */
    #[LiveAction]
    public function toggleManualCompletionForTeacher(#[LiveArg] string $activityId, #[LiveArg] string $teacherId): void
    {
        $activity = $this->findActivity($activityId);
        if ($activity === null || $activity->requiresSubmissions() || $activity->isAutoComplete()) {
            return;
        }
        if (!$this->canManageActivity($activity)) {
            throw $this->createAccessDeniedException();
        }

        $year    = $this->centre->getActiveAcademicYear();
        $teacher = $year === null ? null : $this->teachers->findByAcademicYearAndId($year, $teacherId);
        if ($teacher === null || !$this->completion->isApplicableToTeacher($teacher, $activity)) {
            return;
        }

        $completing = !$this->completion->isCompletedFor($activity, null, null, $teacher);
        if ($completing) {
            $created = $this->completion->markCompleted($activity, $teacher, null, null, $this->teacher());
        } else {
            $created = $this->completion->unmarkCompleted($activity, $teacher, null, null);
        }
        if (!$created) {
            return;
        }

        $this->em->flush();

        $logData = ['activity' => $activity->getTitle(), 'teacher' => $teacher->getName()->getLastName() . ', ' . $teacher->getName()->getFirstName()];
        $this->activityLogger->record($completing ? 'activity.mark_complete' : 'activity.unmark_complete', $logData, $this->centre);

        $this->flashSuccess($this->t($completing ? 'activity.flash.completed' : 'activity.flash.completion_undone'));
    }
}
