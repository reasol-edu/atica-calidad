<?php

declare(strict_types=1);

namespace App\Model;

/**
 * What a bulk edit would do — the action, its normalised parameters (what the preview carries to
 * the confirmation) and one row per selected activity.
 */
final readonly class ActivityBulkEditPlan
{
    /**
     * @param array<string, string>   $params
     * @param list<ActivityBulkEditRow> $rows
     */
    public function __construct(
        public string $action,
        public array $params,
        public array $rows,
    ) {}

    public function changeCount(): int
    {
        return count(array_filter($this->rows, static fn (ActivityBulkEditRow $row): bool => $row->changes()));
    }
}
