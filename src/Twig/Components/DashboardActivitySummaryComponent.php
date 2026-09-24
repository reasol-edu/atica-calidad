<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardSummary;
use App\Model\QualityTask;
use App\Service\ActivityDashboardSummaryBuilder;
use App\Service\QualityTaskFinder;
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

    /** Most "Mejora continua" tasks listed here; the rest are on its hub. */
    public const int MAX_QUALITY_TASKS = 5;

    public function __construct(
        private readonly ActivityDashboardSummaryBuilder $builder,
        private readonly QualityTaskFinder $qualityTasks,
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
     * What the teacher has to do in "Mejora continua" (QualityTaskFinder), shown under the
     * activities in the same card — so it's found where they already look.
     *
     * @return list<QualityTask>
     */
    public function getQualityTasks(): array
    {
        return $this->qualityTasks->forTeacher($this->teacher(), $this->centre);
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
