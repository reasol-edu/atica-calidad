<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Builds the week-by-week grid (days + segments) for a given month, for the
 * unified calendar (CalendarComponent). Weekends are excluded from the grid
 * since there is no class on Saturday/Sunday.
 *
 * Decoupled from any specific entity: callers provide the items plus two
 * callables to turn each item into a date range and into the segment's
 * visual decoration (label, details, color, optional icon).
 */
final class CalendarMonthGridBuilder
{
    public function __construct(
        private readonly CalendarSegmentBuilder $segmentBuilder,
    ) {}

    /**
     * @template T of object
     *
     * @param list<T> $items
     * @param callable(T): (array{id: string, start: \DateTimeImmutable, end: \DateTimeImmutable}|null) $toRange returns null to skip an item (e.g. missing dates)
     * @param callable(T): array{label: string, details: string, color: array{bg: string, text: string, border: string}, icon?: string} $toSegment
     *
     * @return list<array{days: list<\DateTimeImmutable>, segments: list<array<string, mixed>>, maxLane: int}>
     */
    public function build(int $year, int $month, array $items, callable $toRange, callable $toSegment): array
    {
        $rangesById = [];
        $itemsById  = [];
        foreach ($items as $item) {
            $range = $toRange($item);
            if ($range === null) {
                continue;
            }
            $rangesById[$range['id']] = $range;
            $itemsById[$range['id']]  = $item;
        }

        $firstDay  = (new \DateTimeImmutable())->setDate($year, $month, 1)->setTime(0, 0, 0);
        $lastDay   = $firstDay->modify('last day of this month');
        $startDow  = (int) $firstDay->format('N');
        $gridStart = $firstDay->modify('-' . ($startDow - 1) . ' days');
        $endDow    = (int) $lastDay->format('N');
        $gridEnd   = $lastDay->modify('+' . (7 - $endDow) . ' days');

        $weeks  = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $days = [];
            $d    = $cursor;
            for ($i = 0; $i < 7; $i++) {
                // No hay clase en sábado y domingo (N: 6 y 7): no se muestran en el calendario.
                if ((int) $d->format('N') <= 5) {
                    $days[] = $d;
                }
                $d = $d->modify('+1 day');
            }

            $weekStart = $days[0];
            $weekEnd   = $days[count($days) - 1];

            $events = [];
            foreach ($rangesById as $range) {
                if ($range['end'] < $weekStart || $range['start'] > $weekEnd) {
                    continue;
                }
                $events[] = $range;
            }

            $layout   = $this->segmentBuilder->build($events, $days);
            $segments = array_map(function (array $segment) use ($itemsById, $toSegment): array {
                $decoration = $toSegment($itemsById[$segment['id']]);

                return [
                    'startCol' => $segment['startCol'],
                    'span'     => $segment['span'],
                    'lane'     => $segment['lane'],
                    'label'    => $decoration['label'],
                    'details'  => $decoration['details'],
                    'color'    => $decoration['color'],
                    'icon'     => $decoration['icon'] ?? null,
                ];
            }, $layout['segments']);

            $weeks[] = ['days' => $days, 'segments' => $segments, 'maxLane' => $layout['maxLane']];
            $cursor  = $cursor->modify('+7 days');
        }

        return $weeks;
    }
}
