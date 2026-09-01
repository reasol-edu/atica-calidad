<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Deterministically assigns a color combination to a key (today, the profile or
 * subprofile an event is restricted to), so that the same key always gets the
 * same color and different keys are visually distinguishable from each other on the calendar.
 */
final class AssignmentColorPalette
{
    /**
     * @var list<array{bg: string, text: string, border: string}>
     */
    private const PALETTE = [
        ['bg' => 'bg-blue-100', 'text' => 'text-blue-800', 'border' => 'border-blue-300'],
        ['bg' => 'bg-purple-100', 'text' => 'text-purple-800', 'border' => 'border-purple-300'],
        ['bg' => 'bg-amber-100', 'text' => 'text-amber-800', 'border' => 'border-amber-300'],
        ['bg' => 'bg-pink-100', 'text' => 'text-pink-800', 'border' => 'border-pink-300'],
        ['bg' => 'bg-teal-100', 'text' => 'text-teal-800', 'border' => 'border-teal-300'],
        ['bg' => 'bg-rose-100', 'text' => 'text-rose-800', 'border' => 'border-rose-300'],
        ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-800', 'border' => 'border-indigo-300'],
        ['bg' => 'bg-lime-100', 'text' => 'text-lime-800', 'border' => 'border-lime-300'],
        ['bg' => 'bg-cyan-100', 'text' => 'text-cyan-800', 'border' => 'border-cyan-300'],
        ['bg' => 'bg-orange-100', 'text' => 'text-orange-800', 'border' => 'border-orange-300'],
        ['bg' => 'bg-fuchsia-100', 'text' => 'text-fuchsia-800', 'border' => 'border-fuchsia-300'],
        ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-800', 'border' => 'border-emerald-300'],
    ];

    /**
     * @return array{bg: string, text: string, border: string}
     */
    public function colorFor(string $key): array
    {
        $index = crc32($key) % count(self::PALETTE);

        return self::PALETTE[$index];
    }
}
