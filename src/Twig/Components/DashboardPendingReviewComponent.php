<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\DocumentReviewDashboardSummary;
use App\Service\PendingReviewFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Home dashboard widget: for a teacher with review access to at least one folder, how many
 * document revisions are awaiting their review and a capped list of them — renders nothing for a
 * teacher with no review access at all. Read-only: each item links out to the Document Tree
 * section (deep-linked to the exact section/folder/document) to act on it.
 */
#[AsLiveComponent]
class DashboardPendingReviewComponent extends AbstractController
{
    use DefaultActionTrait;

    private const int MAX_ITEMS = 8;

    #[LiveProp]
    public EducationalCentre $centre;

    private ?DocumentReviewDashboardSummary $summary = null;

    public function __construct(
        private readonly PendingReviewFinder $pendingReview,
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
        if (!$this->pendingReview->hasReviewAccess($teacher, $this->centre)) {
            return $this->summary = new DocumentReviewDashboardSummary(false, 0, []);
        }

        $pending = $this->pendingReview->forTeacher($teacher, $this->centre);

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
