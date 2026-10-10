<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Activity;
use App\Model\OwnCompletionOutcome;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * "Marcar hecha" on a manual activity's line of a list, and its "Deshacer", for the live components
 * that list a teacher's obligations (the dashboard's "Tus próximos pasos" and "Mis actividades"): the
 * buttons come from components/_activity_quick_actions.html.twig and the line from
 * components/_activity_done_undo.html.twig.
 *
 * The host must have $centre, $ownCompletions (OwnCompletionManager), $activities (ActivityRepository),
 * $translator and teacher(), and use ComponentToolsTrait (for the flash events).
 */
trait MarksActivitiesDoneTrait
{
    /**
     * The activity just ticked off with markDone(), for the "Deshacer" line (null for none). Not
     * writable, so the browser can't alter it; undoing still re-checks the owner server-side.
     *
     * @var array{activityId: string, profileId: string, listItemId: string, leafId: string, title: string}|null
     */
    #[LiveProp]
    public ?array $lastDone = null;

    /**
     * "Marcar hecha" on a manual activity's line, without opening the activity. The ids are
     * client-supplied, so OwnCompletionManager re-resolves the owner and denies anything the teacher
     * isn't really offered; the list then re-renders without the line.
     */
    #[LiveAction]
    public function markDone(#[LiveArg] string $activityId, #[LiveArg] string $profileId = '', #[LiveArg] string $listItemId = '', #[LiveArg] string $leafId = ''): void
    {
        $activity = $this->requireActivity($activityId);

        $outcome = $this->ownCompletions->mark($this->teacher(), $activity, $this->centre, $profileId, $listItemId, $leafId);
        if ($outcome === OwnCompletionOutcome::Marked) {
            // Instead of a flash: a line in the card itself with "Deshacer", since the row changes
            // and a stray tap (easy on a phone) would otherwise be a trip to the activity to undo.
            $this->lastDone = ['activityId' => $activityId, 'profileId' => $profileId, 'listItemId' => $listItemId, 'leafId' => $leafId, 'title' => $activity->getTitle()];
        } elseif ($outcome === OwnCompletionOutcome::OutOfWindow) {
            // Only this fragment re-renders, so the flash goes out as a browser event the layout shows.
            $this->dispatchBrowserEvent('flash:show', ['type' => 'error', 'message' => $this->translator->trans('completion.error.out_of_window', [], 'activity_content')]);
        }
    }

    /** "Deshacer" on the line markDone() leaves: takes the completion back (re-resolving the owner, like markDone()). */
    #[LiveAction]
    public function undoDone(): void
    {
        $done = $this->lastDone;
        if ($done === null) {
            return;
        }
        $this->lastDone = null;

        $activity = $this->requireActivity($done['activityId']);
        if ($this->ownCompletions->unmark($this->teacher(), $activity, $this->centre, $done['profileId'], $done['listItemId'], $done['leafId'])) {
            $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $this->translator->trans('activity.flash.completion_undone', [], 'admin')]);
        }
    }

    #[LiveAction]
    public function dismissDone(): void
    {
        $this->lastDone = null;
    }

    private function requireActivity(string $activityId): Activity
    {
        $activity = $this->activities->findById($activityId);
        if ($activity === null || $activity->isHidden()
            || $activity->getCategory()->getEducationalCentre()->getId()->toRfc4122() !== $this->centre->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        return $activity;
    }
}
