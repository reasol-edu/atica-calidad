<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Outcome of {@see DatabaseRestoreService::restore()}: what was loaded, and from which backup.
 */
final readonly class DatabaseRestore
{
    /**
     * @param array<string, int> $tableRowCounts table name => rows inserted
     */
    public function __construct(
        public array $tableRowCounts,
        public string $fromAppVersion,
        public ?string $fromSchemaVersion,
        public \DateTimeImmutable $backupCreatedAt,
        public bool $schemaOverridden,
        /** The schema was rebuilt at the backup's migration and migrated to the latest afterwards (a backup from an earlier version). */
        public bool $rebuilt = false,
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
