<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\DocumentReviewDashboardSummary;
use App\Service\DocumentTreeAccessChecker;
use App\Service\PendingReviewFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Home dashboard widget, admin/quality-manager only: every document revision pending review in
 * the centre, not just the ones the current teacher personally holds a review profile for (see
 * DashboardPendingReviewComponent for that personal view, and PendingReviewFinder's class docblock
 * for why the two are deliberately kept separate). Renders nothing for anyone else.
 */
#[AsLiveComponent]
class DashboardAllPendingReviewComponent extends AbstractController
{
    use DefaultActionTrait;

    private const int MAX_ITEMS = 8;

    #[LiveProp]
    public EducationalCentre $centre;

    private ?DocumentReviewDashboardSummary $summary = null;

    public function __construct(
        private readonly PendingReviewFinder $pendingReview,
        private readonly DocumentTreeAccessChecker $access,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->centre = $centre;
    }

    public function getSummary(): DocumentReviewDashboardSummary
    {
        if ($this->summary !== null) {
            return $this->summary;
        }

        $teacher = $this->teacher();
        if (!$this->access->isAdminOrQualityManager($teacher, $this->centre)) {
            return $this->summary = new DocumentReviewDashboardSummary(false, 0, []);
        }

        $pending = $this->pendingReview->allPendingForCentre($this->centre);

        return $this->summary = new DocumentReviewDashboardSummary(true, count($pending), array_slice($pending, 0, self::MAX_ITEMS));
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
