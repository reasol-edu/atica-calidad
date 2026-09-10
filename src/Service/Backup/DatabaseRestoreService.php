<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;

/**
 * Loads a backup written by {@see DatabaseBackupService} back into the database: a full replace
 * — every table the backup covers is emptied and repopulated from its NDJSON, inside one
 * transaction, with foreign-key enforcement suspended for the duration.
 *
 * The schema is NOT recreated: the target database must already be at the same migration as the
 * backup (`inspect()` / the `schemaVersion` check enforces this unless overridden). Values wrapped
 * as `{"@b64": "…"}` are decoded back to raw bytes; everything else is inserted as the JSON type
 * it was stored as (string, int, bool, null).
 *
 * Foreign-key suspension is engine-specific: `PRAGMA defer_foreign_keys` on SQLite (checked at
 * COMMIT), `SET FOREIGN_KEY_CHECKS = 0` on MySQL/MariaDB, `SET session_replication_role = replica`
 * on PostgreSQL — the last needs a privileged role and fails loudly if it isn't available.
 */
final class DatabaseRestoreService
{
    private const SUPPORTED_FORMAT         = 'atica-calidad-backup';
    private const SUPPORTED_FORMAT_VERSION = 1;

    /** Transient tables the backup never captures; emptied on restore so nothing stale survives. */
    private const TRANSIENT_TABLES = ['messenger_messages'];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Parse and validate the archive's manifest without touching the database.
     *
     * @throws BackupArchiveException
     */
    public function inspect(string $archivePath, ?string $password = null): BackupManifest
    {
        $zip = $this->openArchive($archivePath, $password);
        try {
            return $this->readManifest($zip, $password !== null);
        } finally {
            $zip->close();
        }
    }

    /**
     * @throws BackupArchiveException  archive missing/unreadable/wrong password/unsupported format
     * @throws SchemaMismatchException backup schema != database schema and $force is false
     * @throws \RuntimeException       a covered table is missing, FK suspension unavailable, row
     *                                 counts don't add up
     */
    public function restore(string $archivePath, ?string $password = null, bool $force = false): DatabaseRestore
    {
        $zip = $this->openArchive($archivePath, $password);

        try {
            $manifest = $this->readManifest($zip, $password !== null);

            $dbSchemaVersion = $this->databaseSchemaVersion();
            $schemaMismatch  = $manifest->schemaVersion !== $dbSchemaVersion;
            if ($schemaMismatch && !$force) {
                throw new SchemaMismatchException($manifest->schemaVersion, $dbSchemaVersion);
            }

            $existingTables = $this->connection->createSchemaManager()->listTableNames();
            foreach (array_keys($manifest->tables) as $table) {
                if (!\in_array($table, $existingTables, true)) {
                    throw new \RuntimeException(\sprintf(
                        'The backup covers a table ("%s") that does not exist in this database. Run the migrations first, or restore with --force.',
                        $table,
                    ));
                }
            }

            $rowsPerTable = $this->load($zip, $manifest, $existingTables);
        } finally {
            $zip->close();
        }

        return new DatabaseRestore(
            $rowsPerTable,
            $manifest->appVersion,
            $manifest->schemaVersion,
            $manifest->createdAt,
            $schemaMismatch,
        );
    }

    private function openArchive(string $archivePath, ?string $password): \ZipArchive
    {
        if (!is_file($archivePath)) {
            throw new BackupArchiveException(\sprintf('Backup archive "%s" does not exist.', $archivePath));
        }

        $zip  = new \ZipArchive();
        $code = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($code !== true) {
            throw new BackupArchiveException(\sprintf('"%s" is not a readable ZIP archive.', $archivePath));
        }

        if ($password !== null) {
            $zip->setPassword($password);
        }

        return $zip;
    }

    private function readManifest(\ZipArchive $zip, bool $passwordGiven): BackupManifest
    {
        $raw = $zip->getFromName('manifest.json');
        if ($raw === false) {
            throw new BackupArchiveException($passwordGiven
                ? 'Could not read the backup manifest — wrong password, or the archive is corrupt.'
                : 'Could not read the backup manifest. If the backup is encrypted, pass --password.');
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BackupArchiveException('The backup manifest is not valid JSON.', previous: $e);
        }

        if (($data['format'] ?? null) !== self::SUPPORTED_FORMAT) {
            throw new BackupArchiveException('This file is not an ÁTICA Calidad backup.');
        }

        $formatVersion = $data['formatVersion'] ?? null;
        if (!\is_int($formatVersion) || $formatVersion < 1 || $formatVersion > self::SUPPORTED_FORMAT_VERSION) {
            throw new BackupArchiveException('Unsupported backup format version — this build is too old to read it.');
        }

        $tables = $data['tables'] ?? null;
        if (!\is_array($tables)) {
            throw new BackupArchiveException('The backup manifest has no table list.');
        }

        /** @var array<string, int> $tableCounts */
        $tableCounts = [];
        foreach ($tables as $name => $count) {
            $tableCounts[(string) $name] = (int) (\is_numeric($count) ? $count : 0);
        }

        try {
            $createdAt = new \DateTimeImmutable($this->str($data, 'createdAt', 'now'));
        } catch (\Exception) {
            $createdAt = new \DateTimeImmutable('@0');
        }

        return new BackupManifest(
            formatVersion: $formatVersion,
            application: $this->str($data, 'application', 'ÁTICA Calidad'),
            appVersion: $this->str($data, 'appVersion', '?'),
            createdAt: $createdAt,
            databasePlatform: $this->str($data, 'databasePlatform', '?'),
            schemaVersion: isset($data['schemaVersion']) && \is_string($data['schemaVersion']) ? $data['schemaVersion'] : null,
            encrypted: (bool) ($data['encrypted'] ?? false),
            tables: $tableCounts,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function str(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param list<string> $existingTables
     *
     * @return array<string, int> table => rows inserted
     */
    private function load(\ZipArchive $zip, BackupManifest $manifest, array $existingTables): array
    {
        $restoreFk = $this->suspendForeignKeys();

        try {
            /** @var array<string, int> $rowsPerTable */
            $rowsPerTable = $this->connection->transactional(function () use ($zip, $manifest, $existingTables): array {
                if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
                    // Transaction-scoped: FK checks run at COMMIT, when the snapshot is whole again.
                    $this->connection->executeStatement('PRAGMA defer_foreign_keys = ON');
                }

                $wipe = array_values(array_unique([...array_keys($manifest->tables), ...self::TRANSIENT_TABLES]));
                foreach ($wipe as $table) {
                    if (\in_array($table, $existingTables, true)) {
                        $this->connection->executeStatement('DELETE FROM ' . $this->connection->quoteSingleIdentifier($table));
                    }
                }

                $counts = [];
                foreach (array_keys($manifest->tables) as $table) {
                    $counts[$table] = $this->loadTable($zip, $table);
                }

                foreach ($manifest->tables as $table => $expected) {
                    $count  = $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->connection->quoteSingleIdentifier($table));
                    $actual = (int) (\is_numeric($count) ? $count : -1);
                    if ($actual !== $expected) {
                        throw new \RuntimeException(\sprintf(
                            'Restore mismatch for "%s": expected %d rows, loaded %d. Rolled back.',
                            $table,
                            $expected,
                            $actual,
                        ));
                    }
                }

                return $counts;
            });
        } finally {
            $restoreFk();
        }

        return $rowsPerTable;
    }

    private function loadTable(\ZipArchive $zip, string $table): int
    {
        $ndjson = $zip->getFromName('tables/' . $table . '.ndjson');
        if ($ndjson === false) {
            throw new BackupArchiveException(\sprintf('The backup is missing the data file for table "%s".', $table));
        }

        $binaryColumns = $this->binaryColumnsOf($table);

        $statement = null;
        $columns   = [];
        $rows      = 0;

        foreach (explode("\n", $ndjson) as $line) {
            if ($line === '') {
                continue;
            }

            /** @var array<string, mixed> $row */
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            if ($statement === null) {
                $columns   = array_keys($row);
                $quoted    = array_map($this->connection->quoteSingleIdentifier(...), $columns);
                $statement = $this->connection->prepare(
                    'INSERT INTO ' . $this->connection->quoteSingleIdentifier($table)
                    . ' (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', array_fill(0, \count($columns), '?')) . ')',
                );
            }

            $index = 1;
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;

                if (\is_array($value) && isset($value['@b64']) && \is_string($value['@b64'])) {
                    $statement->bindValue($index, base64_decode($value['@b64'], true), ParameterType::BINARY);
                } else {
                    $statement->bindValue($index, $value, $this->parameterType($value, isset($binaryColumns[$column])));
                }

                ++$index;
            }

            $statement->executeStatement();
            ++$rows;
        }

        return $rows;
    }

    private function parameterType(mixed $value, bool $binaryColumn): ParameterType
    {
        return match (true) {
            $value === null   => ParameterType::NULL,
            $binaryColumn     => ParameterType::BINARY,
            \is_bool($value)  => ParameterType::BOOLEAN,
            \is_int($value)   => ParameterType::INTEGER,
            default           => ParameterType::STRING,
        };
    }

    /**
     * Column names of $table whose Doctrine type is binary/blob (lower-cased for lookup) — mirrors
     * {@see DatabaseBackupService}. A value the backup left as a plain string because it happened
     * to be valid UTF-8 still has to bind as bytes on the way back in.
     *
     * @return array<string, true>
     */
    private function binaryColumnsOf(string $table): array
    {
        $binary = [];
        foreach ($this->connection->createSchemaManager()->introspectTable($table)->getColumns() as $column) {
            $type = $column->getType();
            if ($type instanceof BlobType || $type instanceof BinaryType) {
                $binary[strtolower($column->getObjectName()->toString())] = true;
            }
        }

        return $binary;
    }

    private function databaseSchemaVersion(): ?string
    {
        try {
            $versions = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions');
        } catch (\Throwable) {
            return null;
        }

        if ($versions === []) {
            return null;
        }

        sort($versions);
        $latest = end($versions);

        return \is_string($latest) ? $latest : null;
    }

    /**
     * Turns off FK enforcement for the load and hands back a closure that turns it back on.
     * SQLite is handled inside the transaction instead (PRAGMA defer_foreign_keys), so here it's
     * a no-op.
     *
     * @return \Closure(): void
     */
    private function suspendForeignKeys(): \Closure
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

            return function (): void {
                $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            };
        }

        if ($platform instanceof PostgreSQLPlatform) {
            try {
                $this->connection->executeStatement("SET session_replication_role = 'replica'");
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Restoring on PostgreSQL needs a role allowed to disable foreign-key checks '
                    . '(SET session_replication_role). Run the restore as the database owner or a superuser.',
                    previous: $e,
                );
            }

            return function (): void {
                $this->connection->executeStatement("SET session_replication_role = 'origin'");
            };
        }

        return static function (): void {};
    }
}
