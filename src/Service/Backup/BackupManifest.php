<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The `manifest.json` of a backup archive, parsed and validated. Produced by
 * {@see DatabaseRestoreService::inspect()} so a caller can describe a backup (and confirm the
 * destructive restore) before committing to it.
 */
final readonly class BackupManifest
{
    /**
     * @param array<string, int> $tables table name => row count in the backup
     */
    public function __construct(
        public int $formatVersion,
        public string $application,
        public string $appVersion,
        public \DateTimeImmutable $createdAt,
        public string $databasePlatform,
        public ?string $schemaVersion,
        public bool $encrypted,
        public array $tables,
    ) {}

    public function tableCount(): int
    {
        return \count($this->tables);
    }

    public function totalRows(): int
    {
        return array_sum($this->tables);
    }
}
