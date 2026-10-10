<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Activity;
use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardSummary;
use App\Model\AgendaEntry;
use App\Model\OwnCompletionOutcome;
use App\Repository\ActivityRepository;
use App\Service\ActivityDashboardSummaryBuilder;
use App\Service\OwnCompletionManager;
use App\Service\TeacherAgendaBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Home dashboard widget, "Tus próximos pasos": the few most urgent activity obligations the current
 * teacher can act on right now, plus one line of overall progress (see
 * ActivityDashboardSummaryBuilder; statuses from ActivityObligationFinder, like every other
 * screen). The full list and the totals by status live in "Mis actividades". Each line links out to
 * where it's done; a manual activity can also be ticked off right here (markDone()).
 */
#[AsLiveComponent]
class DashboardActivitySummaryComponent extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    /**
     * The activity just ticked off with markDone(), for the "Deshacer" line (null for none). Not
     * writable, so the browser can't alter it; undoing still re-checks the owner server-side.
     *
     * @var array{activityId: string, profileId: string, listItemId: string, leafId: string, title: string}|null
     */
    #[LiveProp]
    public ?array $lastDone = null;

    /** Most lines of the agenda listed here; the rest are in "Mis actividades" and the quality hub. */
    public const int MAX_AGENDA = 8;

    public function __construct(
        private readonly ActivityDashboardSummaryBuilder $builder,
        private readonly TeacherAgendaBuilder $agenda,
        private readonly OwnCompletionManager $ownCompletions,
        private readonly ActivityRepository $activities,
        private readonly TranslatorInterface $translator,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->centre = $centre;
    }

    /**
     * "Marcar hecha" on a manual activity's line of the list, without opening the activity. The
     * ids are client-supplied, so OwnCompletionManager re-resolves the owner and denies anything
     * the teacher isn't really offered; the list then re-renders without the line.
     */
    #[LiveAction]
    public function markDone(#[LiveArg] string $activityId, #[LiveArg] string $profileId = '', #[LiveArg] string $listItemId = '', #[LiveArg] string $leafId = ''): void
    {
        $activity = $this->requireActivity($activityId);

        $outcome = $this->ownCompletions->mark($this->teacher(), $activity, $this->centre, $profileId, $listItemId, $leafId);
        if ($outcome === OwnCompletionOutcome::Marked) {
            // Instead of a flash: a line in the card itself with "Deshacer", since the row is gone
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

    public function getSummary(): ActivityDashboardSummary
    {
        return $this->builder->build($this->teacher(), $this->centre);
    }

    /**
     * What to do now, activities and "Mejora continua" tasks in one list (TeacherAgendaBuilder):
     * the first MAX_AGENDA lines, how many more there are, how many of all are overdue, and
     * whether any is a task (to link to the quality hub).
     *
     * @return array{entries: list<AgendaEntry>, hidden: int, overdue: int, hasTasks: bool}
     */
    public function getAgenda(): array
    {
        $all = $this->agenda->build($this->teacher(), $this->centre);

        return [
            'entries'  => \array_slice($all, 0, self::MAX_AGENDA),
            'hidden'   => max(0, \count($all) - self::MAX_AGENDA),
            'overdue'  => \count(array_filter($all, static fn (AgendaEntry $e): bool => $e->bucket === AgendaEntry::OVERDUE)),
            'hasTasks' => array_filter($all, static fn (AgendaEntry $e): bool => $e->task !== null) !== [],
        ];
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
