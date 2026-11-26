<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Activity;
use App\Entity\ListItem;
use App\Model\ActivitySubmissionSlot;
use App\Repository\ListItemRepository;

/**
 * The periods of an activity with a dimension with dates («una entrega por materia y evaluación»): one per value of that dimension, in
 * the list's order, each with its own dates (the activity's, overridden by the value's own range). Which one is «current» — the one
 * open now, else the next to open, else the last — is what the activity's card and its progress show first.
 */
final class ActivityPeriods
{
    public function __construct(
        private readonly ListItemRepository $listItems,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly \Symfony\Component\Clock\ClockInterface $clock,
    ) {}

    /** @return list<ListItem> the values of the dimension with dates, in order; empty without one */
    public function valuesOf(Activity $activity): array
    {
        $dimension = $activity->getDatedDimension();

        return $dimension === null ? [] : array_values($this->listItems->findLeafDescendants($dimension->getList()));
    }

    /** The value open now, else the next to open, else the last one (null without a dimension with dates). */
    public function current(Activity $activity): ?ListItem
    {
        $values = $this->valuesOf($activity);
        $now    = $this->clock->now();
        $next   = null;
        foreach ($values as $value) {
            $start = $this->deadline->currentCycleStartDate($activity, $value);
            $end   = $this->deadline->currentCycleEndDate($activity, $value);
            if ($start <= $now && $now <= $end->setTime(23, 59, 59)) {
                return $value;
            }
            if ($start > $now && ($next === null || $start < $this->deadline->currentCycleStartDate($activity, $next))) {
                $next = $value;
            }
        }

        return $next ?? ($values === [] ? null : $values[count($values) - 1]);
    }

    /**
     * $slots grouped by their value of the dimension with dates, in the list's order: what the activity's card shows one under the
     * other, the current one first-class. Empty without a dimension with dates.
     *
     * @param  list<ActivitySubmissionSlot> $slots
     * @return list<array{value: ListItem, start: \DateTimeImmutable, end: \DateTimeImmutable, current: bool, slots: list<ActivitySubmissionSlot>}>
     */
    public function group(Activity $activity, array $slots): array
    {
        $values = $this->valuesOf($activity);
        if ($values === []) {
            return [];
        }
        $current = $this->current($activity);
        $groups  = [];
        foreach ($values as $value) {
            $groups[$value->getId()->toRfc4122()] = [
                'value'   => $value,
                'start'   => $this->deadline->currentCycleStartDate($activity, $value),
                'end'     => $this->deadline->currentCycleEndDate($activity, $value),
                'current' => $current === $value,
                'slots'   => [],
            ];
        }
        foreach ($slots as $slot) {
            $key = $slot->deadlineItem($activity)?->getId()->toRfc4122();
            if ($key !== null && isset($groups[$key])) {
                $groups[$key]['slots'][] = $slot;
            }
        }

        return array_values(array_filter($groups, static fn (array $g): bool => $g['slots'] !== []));
    }
}
