<?php

declare(strict_types=1);

namespace App\Service\Form\Layout;

/**
 * A cell of a table of the grid: where it starts, how many columns and rows it spans, and what it holds. The positions it covers
 * (to its right and below) are not cells of their own: the writers of each format know them from the span.
 */
final readonly class Cell
{
    /** @param list<Paragraph> $paragraphs */
    public function __construct(
        public int $row,
        public int $col,
        public int $colspan,
        public int $rowspan,
        public array $paragraphs,
        /** @var ?string a hex colour without '#' */
        public ?string $background = null,
        public bool $border = true,
        /** @var ?string 'top'|'middle'|'bottom' */
        public ?string $valign = null,
    ) {}
}
