<?php

declare(strict_types=1);

namespace App\Service\Form\Layout;

/**
 * A table of the body: the list of fields of a block (a label and its value on each row) or a grid of a table block (a header row,
 * the rows, and a row of totals). Cells are plain text.
 */
final readonly class DataTable
{
    /**
     * @param list<list<string>> $rows       every row, the header and the totals included
     * @param int                $headerRows how many rows at the top are the header (0 for a list of fields)
     * @param bool               $labelColumn the first column holds the labels of the rows (a list of fields, a table of fixed rows)
     * @param bool               $totalRow    the last row is the totals
     */
    public function __construct(
        public array $rows,
        public int $headerRows = 0,
        public bool $labelColumn = false,
        public bool $totalRow = false,
    ) {}

    public function columns(): int
    {
        return max(1, ...array_map(static fn (array $r): int => \count($r), $this->rows ?: [[]]));
    }
}
