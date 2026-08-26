<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Distribuye eventos con rango de fechas (p. ej. sanciones) en columnas (días
 * visibles de la semana) y "carriles" (lanes) verticales, de modo que los
 * eventos solapados en el tiempo no se solapen visualmente al dibujarse como
 * barras horizontales.
 */
final class CalendarSegmentBuilder
{
    /**
     * @param list<array{id: string, start: \DateTimeImmutable, end: \DateTimeImmutable}> $events
     * @param list<\DateTimeImmutable> $days días visibles de la semana, en orden (p. ej. solo lectivos)
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
