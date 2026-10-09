<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardSummary;
use App\Model\AgendaEntry;
use App\Service\ActivityDashboardSummaryBuilder;
use App\Service\TeacherAgendaBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Home dashboard widget, "Tus próximos pasos": the few most urgent activity obligations the current
 * teacher can act on right now, plus one line of overall progress (see
 * ActivityDashboardSummaryBuilder; statuses from ActivityObligationFinder, like every other
 * screen). The full list and the totals by status live in "Mis actividades". Read-only: each item
 * links out to the Actividades section to act on it.
 */
#[AsLiveComponent]
class DashboardActivitySummaryComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    /** Most lines of the agenda listed here; the rest are in "Mis actividades" and the quality hub. */
    public const int MAX_AGENDA = 8;

    public function __construct(
        private readonly ActivityDashboardSummaryBuilder $builder,
        private readonly TeacherAgendaBuilder $agenda,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->centre = $centre;
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
