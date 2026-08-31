<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\DocumentRevision;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Service\ActivityDashboardSummaryBuilder;
use App\Service\PendingReviewFinder;
use App\Service\TenantContextInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Header bell: a live-computed queue of things the current teacher needs to act on in the
 * selected centre — no persisted notification, no read/unread state. An item drops out of the
 * queue the moment its underlying condition is resolved (activity completed, revision reviewed).
 */
#[AsLiveComponent]
class NotificationBellComponent extends AbstractController
{
    use DefaultActionTrait;

    private const int MAX_ITEMS = 8;

    /** @var list<array{type: 'activity'|'review', entity: ActivityDashboardItem|DocumentRevision, date: \DateTimeImmutable}>|null */
    private ?array $items = null;

    private int $total = 0;

    public function __construct(
        private readonly TenantContextInterface $tenant,
        private readonly ActivityDashboardSummaryBuilder $activitySummary,
        private readonly PendingReviewFinder $pendingReview,
    ) {}

    /** @return list<array{type: 'activity'|'review', entity: ActivityDashboardItem|DocumentRevision, date: \DateTimeImmutable}> */
    public function getVisibleItems(): array
    {
        $this->load();

        return array_slice($this->items ?? [], 0, self::MAX_ITEMS);
    }

    public function getTotal(): int
    {
        $this->load();

        return $this->total;
    }

    private function load(): void
    {
        if ($this->items !== null) {
            return;
        }

        $centre = $this->tenant->getSelectedCentre();
        $user   = $this->getUser();
        if ($centre === null || !$user instanceof Teacher) {
            $this->items = [];

            return;
        }

        $summary = $this->activitySummary->build($user, $centre);
        $reviews = $this->pendingReview->forTeacher($user, $centre);

        $this->total = $summary->pending + $summary->overdue + count($reviews);

        $items = [];
        foreach ($summary->items as $item) {
            $items[] = ['type' => 'activity', 'entity' => $item, 'date' => $item->deadline];
        }
        foreach ($reviews as $revision) {
            $items[] = ['type' => 'review', 'entity' => $revision, 'date' => $revision->getRevisedAt()];
        }

        usort($items, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        $this->items = $items;
    }
}
