<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Deterministically assigns a colour combination to a key (an event's restricted profile /
 * subprofile, an activity's category…), so the same key always gets the same colour and
 * different keys stay visually distinguishable on the calendar.
 *
 * The tints are deliberately light (`-50` fill, `-300` border, `-700`/`-800` text plus an `-500`
 * `accent` for a left rule): several colour bars stacked in a calendar cell read as calm chips
 * grouped by hue rather than a wall of saturated blocks.
 */
final class AssignmentColorPalette
{
    /**
     * @var list<array{bg: string, text: string, border: string, accent: string}>
     */
    private const PALETTE = [
        ['bg' => 'bg-blue-50', 'text' => 'text-blue-800', 'border' => 'border-blue-200', 'accent' => 'border-l-blue-500'],
        ['bg' => 'bg-purple-50', 'text' => 'text-purple-800', 'border' => 'border-purple-200', 'accent' => 'border-l-purple-500'],
        ['bg' => 'bg-amber-50', 'text' => 'text-amber-800', 'border' => 'border-amber-200', 'accent' => 'border-l-amber-500'],
        ['bg' => 'bg-pink-50', 'text' => 'text-pink-800', 'border' => 'border-pink-200', 'accent' => 'border-l-pink-500'],
        ['bg' => 'bg-teal-50', 'text' => 'text-teal-800', 'border' => 'border-teal-200', 'accent' => 'border-l-teal-500'],
        ['bg' => 'bg-rose-50', 'text' => 'text-rose-800', 'border' => 'border-rose-200', 'accent' => 'border-l-rose-500'],
        ['bg' => 'bg-indigo-50', 'text' => 'text-indigo-800', 'border' => 'border-indigo-200', 'accent' => 'border-l-indigo-500'],
        ['bg' => 'bg-lime-50', 'text' => 'text-lime-800', 'border' => 'border-lime-200', 'accent' => 'border-l-lime-500'],
        ['bg' => 'bg-cyan-50', 'text' => 'text-cyan-800', 'border' => 'border-cyan-200', 'accent' => 'border-l-cyan-500'],
        ['bg' => 'bg-orange-50', 'text' => 'text-orange-800', 'border' => 'border-orange-200', 'accent' => 'border-l-orange-500'],
        ['bg' => 'bg-fuchsia-50', 'text' => 'text-fuchsia-800', 'border' => 'border-fuchsia-200', 'accent' => 'border-l-fuchsia-500'],
        ['bg' => 'bg-emerald-50', 'text' => 'text-emerald-800', 'border' => 'border-emerald-200', 'accent' => 'border-l-emerald-500'],
    ];

    /**
     * @return array{bg: string, text: string, border: string, accent: string}
     */
    public function colorFor(string $key): array
    {
        $index = crc32($key) % count(self::PALETTE);

        return self::PALETTE[$index];
    }
}
