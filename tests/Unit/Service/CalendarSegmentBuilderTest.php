<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CalendarSegmentBuilder;
use PHPUnit\Framework\TestCase;

final class CalendarSegmentBuilderTest extends TestCase
{
    private CalendarSegmentBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CalendarSegmentBuilder();
    }

    /** @return list<\DateTimeImmutable> Monday..Friday of a fixed week */
    private function weekDays(): array
    {
        $monday = new \DateTimeImmutable('2025-09-01');

        return array_map(static fn (int $i): \DateTimeImmutable => $monday->modify("+{$i} days"), range(0, 4));
    }

    public function testEmptyDaysReturnsNoSegments(): void
    {
        $result = $this->builder->build([], []);

        self::assertSame([], $result['segments']);
        self::assertSame(-1, $result['maxLane']);
    }

    public function testSingleDayEventOccupiesOneColumn(): void
    {
        $days = $this->weekDays();
        $result = $this->builder->build([
            ['id' => 'a', 'start' => $days[2], 'end' => $days[2]],
        ], $days);

        self::assertCount(1, $result['segments']);
        self::assertSame(2, $result['segments'][0]['startCol']);
        self::assertSame(1, $result['segments'][0]['span']);
        self::assertSame(0, $result['segments'][0]['lane']);
        self::assertSame(0, $result['maxLane']);
    }

    public function testMultiDayEventSpansMultipleColumns(): void
    {
        $days = $this->weekDays();
        $result = $this->builder->build([
            ['id' => 'a', 'start' => $days[1], 'end' => $days[3]],
        ], $days);

        self::assertSame(1, $result['segments'][0]['startCol']);
        self::assertSame(3, $result['segments'][0]['span']);
    }

    public function testEventEntirelyOutsideTheVisibleRangeIsExcluded(): void
    {
        $days  = $this->weekDays();
        $before = $days[0]->modify('-10 days');
        $result = $this->builder->build([
            ['id' => 'a', 'start' => $before, 'end' => $before],
        ], $days);

        self::assertSame([], $result['segments']);
    }

    public function testEventPartiallyOverlappingTheRangeIsClampedToIt(): void
    {
        $days   = $this->weekDays();
        $before = $days[0]->modify('-3 days');
        $result = $this->builder->build([
            ['id' => 'a', 'start' => $before, 'end' => $days[1]],
        ], $days);

        self::assertCount(1, $result['segments']);
        self::assertSame(0, $result['segments'][0]['startCol'], 'the segment must be clamped to the first visible day');
        self::assertSame(2, $result['segments'][0]['span']);
    }

    public function testOverlappingEventsAreAssignedDifferentLanes(): void
    {
        $days = $this->weekDays();
        $result = $this->builder->build([
            ['id' => 'a', 'start' => $days[0], 'end' => $days[2]],
            ['id' => 'b', 'start' => $days[1], 'end' => $days[3]],
        ], $days);

        self::assertCount(2, $result['segments']);
        $byId = [];
        foreach ($result['segments'] as $segment) {
            $byId[$segment['id']] = $segment;
        }
        self::assertNotSame($byId['a']['lane'], $byId['b']['lane'], 'overlapping events must never share a lane');
        self::assertSame(1, $result['maxLane']);
    }

    public function testNonOverlappingEventsShareTheSameLane(): void
    {
        $days = $this->weekDays();
        $result = $this->builder->build([
            ['id' => 'a', 'start' => $days[0], 'end' => $days[1]],
            ['id' => 'b', 'start' => $days[2], 'end' => $days[3]],
        ], $days);

        $byId = [];
        foreach ($result['segments'] as $segment) {
            $byId[$segment['id']] = $segment;
        }
        self::assertSame($byId['a']['lane'], $byId['b']['lane'], 'sequential, non-overlapping events can share a lane');
        self::assertSame(0, $result['maxLane']);
    }

    public function testLongerEventsAreLaidOutBeforeShorterOnesStartingTheSameDay(): void
    {
        $days = $this->weekDays();
        // A short event starting the same day as a longer one — sort must place the longer one
        // first so lane assignment is stable regardless of input order.
        $result = $this->builder->build([
            ['id' => 'short', 'start' => $days[0], 'end' => $days[0]],
            ['id' => 'long', 'start' => $days[0], 'end' => $days[4]],
        ], $days);

        $byId = [];
        foreach ($result['segments'] as $segment) {
            $byId[$segment['id']] = $segment;
        }
        self::assertSame(0, $byId['long']['lane']);
        self::assertSame(1, $byId['short']['lane']);
    }
}
