<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Backup\BackupLibrary;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What an administrator needs to see to know the platform is healthy: is the worker alive (heartbeat), how much room the
 * database and the backups take and how much disk is left. Every figure is best effort: a failure returns null, never an
 * exception, so the page always renders.
 */
class SystemStatus
{
    public const HEARTBEAT = 'heartbeat';

    /** The heartbeat is written every 5 minutes; this many seconds without one and the worker is considered down. */
    public const HEARTBEAT_STALE_AFTER = 900;

    public const WORKER_OK    = 'ok';
    public const WORKER_STALE = 'stale';
    public const WORKER_NEVER = 'never';

    public function __construct(
        private readonly SystemState $state,
        private readonly Connection $connection,
        private readonly BackupLibrary $backups,
        #[Autowire('%app.backup_dir%')]
        private readonly string $backupDir,
    ) {}

    /** @return array{state: string, at: int|null} */
    public function worker(): array
    {
        $beat = $this->state->read(self::HEARTBEAT);
        $at   = isset($beat['at']) && \is_int($beat['at']) ? $beat['at'] : null;

        if ($at === null) {
            return ['state' => self::WORKER_NEVER, 'at' => null];
        }

        return ['state' => time() - $at <= self::HEARTBEAT_STALE_AFTER ? self::WORKER_OK : self::WORKER_STALE, 'at' => $at];
    }

    /** @return array{database: int|null, backups: int, backup_count: int, disk_free: int|null} sizes in bytes */
    public function storage(): array
    {
        $usage = $this->backups->usage();
        $free  = @disk_free_space(is_dir($this->backupDir) ? $this->backupDir : \dirname($this->backupDir));

        return [
            'database'     => $this->databaseSize(),
            'backups'      => $usage['bytes'],
            'backup_count' => $usage['count'],
            'disk_free'    => $free === false ? null : (int) $free,
        ];
    }

    /**
     * Size of the database as the engine reports it (data plus indexes, file contents included), never by summing the
     * documents: that would read them. Null when it cannot be known (an in-memory database, no permission).
     */
    public function databaseSize(): ?int
    {
        try {
            $platform = $this->connection->getDatabasePlatform();

            if ($platform instanceof SQLitePlatform) {
                $path = $this->connection->getParams()['path'] ?? null;

                return \is_string($path) && is_file($path) ? (int) filesize($path) : null;
            }
            if ($platform instanceof PostgreSQLPlatform) {
                $size = $this->connection->fetchOne('SELECT pg_database_size(current_database())');

                return \is_numeric($size) ? (int) $size : null;
            }
            if ($platform instanceof AbstractMySQLPlatform) {
                $size = $this->connection->fetchOne('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE()');

                return \is_numeric($size) ? (int) $size : null;
            }
        } catch (\Throwable) {
            // Not available: the page shows a dash.
        }

        return null;
    }
}
