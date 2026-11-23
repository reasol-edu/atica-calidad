<?php

declare(strict_types=1);

namespace App\Service\Form\Layout;

/** A table of a header or footer as a grid: the share of the width of each column and the cells (each placed at its row and column). */
final readonly class Table
{
    /**
     * @param list<float> $widths the share of the width of each column (they add up to 1)
     * @param list<Cell>  $cells  in reading order
     */
    public function __construct(
        public array $widths,
        public int $rows,
        public array $cells,
    ) {}

    public function columns(): int
    {
        return \count($this->widths);
    }

    /**
     * Every position of the grid and what is on it: the cell that starts there (`origin` true) or the one that covers it from
     * a position to its left or above (`origin` false). A position nothing covers is missing. The writers of ODT and DOCX
     * need it: each one asks for the covered positions in its own way.
     *
     * @return array<int, array<int, array{cell: Cell, origin: bool}>> indexed by row, then column
     */
    public function placement(): array
    {
        $map = [];
        foreach ($this->cells as $cell) {
            for ($r = $cell->row; $r < $cell->row + $cell->rowspan; ++$r) {
                for ($c = $cell->col; $c < $cell->col + $cell->colspan; ++$c) {
                    $map[$r][$c] = ['cell' => $cell, 'origin' => $r === $cell->row && $c === $cell->col];
                }
            }
        }

        return $map;
    }
}
