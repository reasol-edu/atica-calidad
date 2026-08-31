<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\EducationalCentre;
use App\Entity\Teacher;
use App\Model\ActivityDashboardItem;
use App\Model\ActivityDashboardStatus;
use App\Service\MyActivitiesFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "Mis actividades" tab: every activity obligation the current teacher owns in the centre — pending
 * (in time), overdue, or completed — in one place, always sorted by deadline within whatever view
 * is active. $groupBy switches between a flat deadline-ordered list (the default) and three grouped
 * views (by profile/owner label, by category, by done/not-done status); grouping never changes the
 * within-group order, which stays deadline-ascending throughout — including for overdue items,
 * whose deadline already sits in the past, so they naturally surface first without special-casing.
 */
#[AsLiveComponent]
class MyActivitiesComponent extends AbstractController
{
    use DefaultActionTrait;

    private const array GROUP_MODES = ['deadline', 'profile', 'category', 'status'];

    #[LiveProp]
    public EducationalCentre $centre;

    #[LiveProp(writable: true)]
    public string $searchQuery = '';

    #[LiveProp(writable: true)]
    public string $groupBy = 'deadline';

    /** When true, getFilteredItems()/getGroups() drop completed obligations — pending and overdue only. */
    #[LiveProp(writable: true)]
    public bool $onlyPending = false;

    /** @var list<ActivityDashboardItem>|null */
    private ?array $items = null;

    public function __construct(
        private readonly MyActivitiesFinder $finder,
        private readonly TranslatorInterface $translator,
    ) {}

    public function mount(EducationalCentre $centre): void
    {
        $this->centre = $centre;
    }

    #[LiveAction]
    public function setGroupBy(#[LiveArg] string $mode): void
    {
        $this->groupBy = in_array($mode, self::GROUP_MODES, true) ? $mode : 'deadline';
    }

    #[LiveAction]
    public function clearSearch(): void
    {
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function toggleOnlyPending(): void
    {
        $this->onlyPending = !$this->onlyPending;
    }

    public function getTotal(): int
    {
        return count($this->getItems());
    }

    public function getCompletedCount(): int
    {
        return count(array_filter($this->getItems(), static fn (ActivityDashboardItem $i): bool => $i->status === ActivityDashboardStatus::Completed));
    }

    public function getPendingCount(): int
    {
        return count(array_filter($this->getItems(), static fn (ActivityDashboardItem $i): bool => $i->status === ActivityDashboardStatus::Pending));
    }

    public function getOverdueCount(): int
    {
        return count(array_filter($this->getItems(), static fn (ActivityDashboardItem $i): bool => $i->status === ActivityDashboardStatus::Overdue));
    }

    /**
     * @return list<ActivityDashboardItem> filtered by searchQuery and (when $onlyPending) status,
     *         sorted by deadline ascending — the flat view, and the base order every group's own
     *         items keep too.
     */
    public function getFilteredItems(): array
    {
        $items = $this->getItems();

        if ($this->onlyPending) {
            $items = array_values(array_filter(
                $items,
                static fn (ActivityDashboardItem $i): bool => $i->status !== ActivityDashboardStatus::Completed,
            ));
        }

        $query = mb_strtolower(trim($this->searchQuery));
        if ($query !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (ActivityDashboardItem $i): bool => str_contains(mb_strtolower($i->activity->getTitle()), $query)
                    || str_contains(mb_strtolower($i->categoryPath), $query)
                    || ($i->ownerLabel !== null && str_contains(mb_strtolower($i->ownerLabel), $query)),
            ));
        }

        usort($items, static fn (ActivityDashboardItem $a, ActivityDashboardItem $b): int => $a->deadline <=> $b->deadline);

        return $items;
    }

    /**
     * Only meaningful when $groupBy !== 'deadline' — the flat view renders getFilteredItems()
     * directly instead. Groups are themselves ordered by their most urgent (soonest-deadline)
     * item, except the 'status' grouping, which always puts "Pendientes" before "Completadas"
     * regardless of individual deadlines.
     *
     * @return list<array{label: string, items: list<ActivityDashboardItem>}>
     */
    public function getGroups(): array
    {
        $items = $this->getFilteredItems();

        /** @var array<string, list<ActivityDashboardItem>> $buckets */
        $buckets = [];
        foreach ($items as $item) {
            $buckets[$this->groupLabel($item)][] = $item;
        }

        if ($this->groupBy === 'status') {
            $pendingLabel   = $this->translator->trans('mine.group.pending', [], 'activity_content');
            $completedLabel = $this->translator->trans('mine.group.completed', [], 'activity_content');

            $groups = [];
            if (isset($buckets[$pendingLabel])) {
                $groups[] = ['label' => $pendingLabel, 'items' => $buckets[$pendingLabel]];
            }
            if (isset($buckets[$completedLabel])) {
                $groups[] = ['label' => $completedLabel, 'items' => $buckets[$completedLabel]];
            }

            return $groups;
        }

        $groups = [];
        foreach ($buckets as $label => $groupItems) {
            $groups[] = ['label' => $label, 'items' => $groupItems];
        }

        usort($groups, static fn (array $a, array $b): int => $a['items'][0]->deadline <=> $b['items'][0]->deadline);

        return $groups;
    }

    private function groupLabel(ActivityDashboardItem $item): string
    {
        return match ($this->groupBy) {
            'category' => $item->categoryPath,
            'profile'  => $item->ownerLabel ?? $this->translator->trans('mine.no_owner_label', [], 'activity_content'),
            'status'   => $item->status === ActivityDashboardStatus::Completed
                ? $this->translator->trans('mine.group.completed', [], 'activity_content')
                : $this->translator->trans('mine.group.pending', [], 'activity_content'),
            default => '',
        };
    }

    /** @return list<ActivityDashboardItem> */
    private function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        return $this->items = $this->finder->forTeacher($this->teacher(), $this->centre);
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
