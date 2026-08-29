<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\CalendarMonthGridBuilder;
use App\Service\CalendarSegmentBuilder;
use PHPUnit\Framework\TestCase;

final class CalendarMonthGridBuilderTest extends TestCase
{
    private CalendarMonthGridBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CalendarMonthGridBuilder(new CalendarSegmentBuilder());
    }

    /** @return array{label: string, details: string, color: array{bg: string, text: string, border: string}} */
    private function decoration(): array
    {
        return ['label' => 'Evento', 'details' => 'Detalle', 'color' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'border' => 'border-blue-300']];
    }

    public function testGridContainsOnlyWeekdays(): void
    {
        $weeks = $this->builder->build(2025, 9, [], static fn (\stdClass $i): ?array => null, fn (\stdClass $i): array => $this->decoration());

        foreach ($weeks as $week) {
            self::assertCount(5, $week['days'], 'each week row must have exactly 5 weekdays, no Saturday/Sunday');
            foreach ($week['days'] as $day) {
                self::assertLessThanOrEqual(5, (int) $day->format('N'));
            }
        }
    }

    public function testGridCoversTheWholeMonth(): void
    {
        $weeks = $this->builder->build(2025, 9, [], static fn (\stdClass $i): ?array => null, fn (\stdClass $i): array => $this->decoration());

        $allDays = [];
        foreach ($weeks as $week) {
            foreach ($week['days'] as $day) {
                $allDays[] = $day->format('Y-m-d');
            }
        }

        // September 2025 has 30 days; confirm both ends are present.
        self::assertContains('2025-09-01', $allDays);
        self::assertContains('2025-09-30', $allDays);
    }

    public function testAnItemSkippedByToRangeProducesNoSegment(): void
    {
        $item  = new \stdClass();
        $weeks = $this->builder->build(2025, 9, [$item], static fn (\stdClass $i): ?array => null, fn (\stdClass $i): array => $this->decoration());

        foreach ($weeks as $week) {
            self::assertSame([], $week['segments']);
        }
    }

    public function testAnItemWithARangeProducesASegmentInTheCorrectWeek(): void
    {
        $item  = new \stdClass();
        $date  = new \DateTimeImmutable('2025-09-10'); // a Wednesday
        $weeks = $this->builder->build(
            2025,
            9,
            [$item],
            static fn (\stdClass $i): array => ['id' => 'x', 'start' => $date, 'end' => $date],
            fn (\stdClass $i): array => $this->decoration(),
        );

        $totalSegments = array_sum(array_map(static fn (array $w): int => count($w['segments']), $weeks));
        self::assertSame(1, $totalSegments);

        foreach ($weeks as $week) {
            foreach ($week['segments'] as $segment) {
                self::assertSame('Evento', $segment['label']);
                self::assertSame('Detalle', $segment['details']);
            }
        }
    }

    public function testDecorationIconDefaultsToNull(): void
    {
        $item  = new \stdClass();
        $date  = new \DateTimeImmutable('2025-09-10');
        $weeks = $this->builder->build(
            2025,
            9,
            [$item],
            static fn (\stdClass $i): array => ['id' => 'x', 'start' => $date, 'end' => $date],
            fn (\stdClass $i): array => $this->decoration(),
        );

        $found = false;
        foreach ($weeks as $week) {
            foreach ($week['segments'] as $segment) {
                $found = true;
                self::assertNull($segment['icon']);
            }
        }
        self::assertTrue($found);
    }
}
