<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Outcome of {@see DatabaseBackupService::create()}: where the archive landed, how big it is and
 * how many rows of each table went into it (in the order they were dumped).
 */
final readonly class DatabaseBackup
{
    /** @param array<string, int> $tableRowCounts table name => rows written */
    public function __construct(
        public string $path,
        public int $bytes,
        public array $tableRowCounts,
        public bool $encrypted = false,
    ) {}

    public function tableCount(): int
    {
        return \count($this->tableRowCounts);
    }

    public function totalRows(): int
    {
        return array_sum($this->tableRowCounts);
    }
}
