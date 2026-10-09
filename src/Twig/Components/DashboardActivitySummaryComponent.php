<?php

declare(strict_types=1);

namespace App\Twig\Components;

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
        $activity = $this->activities->findById($activityId);
        if ($activity === null || $activity->isHidden()
            || $activity->getCategory()->getEducationalCentre()->getId()->toRfc4122() !== $this->centre->getId()->toRfc4122()) {
            throw $this->createNotFoundException();
        }

        $message = match ($this->ownCompletions->mark($this->teacher(), $activity, $this->centre, $profileId, $listItemId, $leafId)) {
            OwnCompletionOutcome::OutOfWindow => ['error', 'completion.error.out_of_window', 'activity_content'],
            OwnCompletionOutcome::Marked      => ['success', 'activity.flash.completed', 'admin'],
            OwnCompletionOutcome::Unchanged   => null,
        };
        if ($message !== null) {
            // Only this fragment re-renders, so the flash goes out as a browser event the layout shows.
            $this->dispatchBrowserEvent('flash:show', ['type' => $message[0], 'message' => $this->translator->trans($message[1], [], $message[2])]);
        }
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
