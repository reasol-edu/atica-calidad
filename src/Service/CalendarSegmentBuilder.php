<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Distributes date-range events (e.g. sanctions) into columns (visible days
 * of the week) and vertical "lanes", so that events overlapping in time
 * don't overlap visually when drawn as horizontal bars.
 */
final class CalendarSegmentBuilder
{
    /**
     * @param list<array{id: string, start: \DateTimeImmutable, end: \DateTimeImmutable}> $events
     * @param list<\DateTimeImmutable> $days visible days of the week, in order (e.g. school days only)
     * @return array{segments: list<array{id: string, startCol: int, span: int, lane: int}>, maxLane: int}
     */
    public function build(array $events, array $days): array
    {
        if ($days === []) {
            return ['segments' => [], 'maxLane' => -1];
        }

        $days = array_map(static fn (\DateTimeImmutable $d): \DateTimeImmutable => $d->setTime(0, 0, 0), $days);

        $rangeStart = $days[0];
        $rangeEnd   = $days[count($days) - 1];

        $items = [];
        foreach ($events as $event) {
            $start = $event['start']->setTime(0, 0, 0);
            $end   = $event['end']->setTime(0, 0, 0);
            if ($end < $rangeStart || $start > $rangeEnd) {
                continue;
            }

            $clampedStart = max($start, $rangeStart);
            $clampedEnd   = min($end, $rangeEnd);

            $startCol = null;
            foreach ($days as $index => $day) {
                if ($day >= $clampedStart) {
                    $startCol = $index;
                    break;
                }
            }

            $endCol = null;
            for ($index = count($days) - 1; $index >= 0; $index--) {
                if ($days[$index] <= $clampedEnd) {
                    $endCol = $index;
                    break;
                }
            }

            if ($startCol === null || $endCol === null || $endCol < $startCol) {
                continue;
            }

            $items[] = [
                'id'       => $event['id'],
                'startCol' => $startCol,
                'endCol'   => $endCol,
                'span'     => $endCol - $startCol + 1,
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['startCol'] <=> $b['startCol'] ?: $b['span'] <=> $a['span']);

        $laneEnds = [];
        $segments = [];
        foreach ($items as $item) {
            $lane = null;
            foreach ($laneEnds as $index => $laneEnd) {
                if ($laneEnd < $item['startCol']) {
                    $lane = $index;
                    break;
                }
            }
            if ($lane === null) {
                $lane = count($laneEnds);
            }
            $laneEnds[$lane] = $item['endCol'];

            $segments[] = [
                'id'       => $item['id'],
                'startCol' => $item['startCol'],
                'span'     => $item['span'],
                'lane'     => $lane,
            ];
        }

        return ['segments' => $segments, 'maxLane' => count($laneEnds) - 1];
    }
}
