<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Activity;

/**
 * The obligations one teacher holds for the same activity (one per owner row of a ByProfile
 * activity, e.g. head of two departments), shown by "Mis actividades" as a single expandable row
 * with an "X/Y" count instead of repeating the activity once per obligation. A group with a
 * single item is rendered as a plain row.
 */
final readonly class ActivityObligationGroup
{
    /** @param non-empty-list<ActivityDashboardItem> $items in the list's own order */
    public function __construct(
        public array $items,
    ) {}

    public function getActivity(): Activity
    {
        return $this->items[0]->activity;
    }

    public function isSingle(): bool
    {
        return \count($this->items) === 1;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function doneCount(): int
    {
        return \count(array_filter($this->items, static fn (ActivityDashboardItem $i): bool => $i->status === ActivityObligationStatus::Completed));
    }

    /** The item whose status stands for the whole row: the most urgent one (Completed only when all are). */
    public function lead(): ActivityDashboardItem
    {
        $items = $this->items;
        usort($items, ActivityDashboardItem::compareByUrgency(...));

        return $items[0];
    }

    /**
     * Folds the items of a list into one group per activity, placed where its first item was.
     *
     * @param list<ActivityDashboardItem> $items
     *
     * @return list<self>
     */
    public static function fold(array $items): array
    {
        /** @var array<string, non-empty-list<ActivityDashboardItem>> $byActivity */
        $byActivity = [];
        foreach ($items as $item) {
            $byActivity[$item->activity->getId()->toRfc4122()][] = $item;
        }

        return array_values(array_map(static fn (array $group): self => new self($group), $byActivity));
    }
}
