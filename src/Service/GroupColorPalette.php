<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Asigna de forma determinista una combinación de colores a un grupo, de modo
 * que el mismo grupo obtenga siempre el mismo color y los distintos grupos se
 * distingan visualmente entre sí (usado en las barras de sanciones del calendario).
 */
final class GroupColorPalette
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
    public function colorFor(string $groupId): array
    {
        $index = crc32($groupId) % count(self::PALETTE);

        return self::PALETTE[$index];
    }
}
