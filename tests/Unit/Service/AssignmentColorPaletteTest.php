<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AssignmentColorPalette;
use PHPUnit\Framework\TestCase;

final class AssignmentColorPaletteTest extends TestCase
{
    private AssignmentColorPalette $palette;

    protected function setUp(): void
    {
        $this->palette = new AssignmentColorPalette();
    }

    public function testTheSameKeyAlwaysReturnsTheSameColor(): void
    {
        $first  = $this->palette->colorFor('Tutor/a 1º ESO A');
        $second = $this->palette->colorFor('Tutor/a 1º ESO A');

        self::assertSame($first, $second);
    }

    public function testReturnsAllThreeColorChannels(): void
    {
        $color = $this->palette->colorFor('cualquier clave');

        self::assertArrayHasKey('bg', $color);
        self::assertArrayHasKey('text', $color);
        self::assertArrayHasKey('border', $color);
    }

    public function testDifferentKeysCanReceiveDifferentColors(): void
    {
        $colors = array_map(fn (string $k) => $this->palette->colorFor($k), ['a', 'b', 'c', 'd', 'e']);
        $unique = array_unique(array_map(static fn (array $c): string => $c['bg'], $colors));

        self::assertGreaterThan(1, count($unique), 'a small sample of distinct keys should not all collide onto the same color');
    }

    public function testEmptyStringKeyStillReturnsAValidColor(): void
    {
        $color = $this->palette->colorFor('');

        self::assertArrayHasKey('bg', $color);
    }
}
