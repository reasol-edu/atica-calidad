<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\ListItem;

/** One row of a SenecaListImportPlan: a single list item, for display in the import preview. */
final class SenecaListImportPlanItem
{
    /** @param string[] $path names from (but not including) the import's root down to this item, in order */
    public function __construct(
        public readonly array $path,
        public readonly ?ListItem $item = null,
    ) {}

    public function label(): string
    {
        return implode(' › ', $this->path);
    }
}
