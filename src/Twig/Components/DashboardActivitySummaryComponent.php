<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardSummary;
use App\Service\ActivityDashboardSummaryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Home dashboard widget: every activity applicable to the current teacher by upload profile
 * (narrower than the "Actividades" section's own relevance filter — see
 * ActivityDashboardSummaryBuilder), with completed/pending/overdue counts and a capped list of
 * what still needs attention. Read-only: each item links out to the Actividades section to act on
 * it — the actual upload/completion actions live there, not duplicated here.
 */
#[AsLiveComponent]
class DashboardActivitySummaryComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    public function __construct(
        private readonly ActivityDashboardSummaryBuilder $builder,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->centre = $centre;
    }

    public function getSummary(): ActivityDashboardSummary
    {
        return $this->builder->build($this->teacher(), $this->centre);
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
