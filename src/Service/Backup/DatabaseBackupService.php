<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\BinaryType;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Writes a full, engine-independent backup of the application database to a single ZIP file.
 *
 * Everything the application stores lives in the database — the document-revision and settings
 * file blobs included (see {@see \App\Entity\DocumentFile} / {@see \App\Entity\SettingFile}) — so
 * a database dump IS the whole backup; there are no on-disk data files to gather separately. The
 * one thing that lives outside and is NOT captured here is `APP_SECRET` (`.env.local`, or
 * `data/.secret` in the native binary); it has to be kept safe on its own.
 *
 * The dump is logical, not SQL for one dialect: every table is streamed as NDJSON (one JSON
 * object per row), so a backup taken on SQLite can be inspected — or, later, restored — on
 * PostgreSQL and vice versa. Any value that is not valid UTF-8 (BLOB columns on every engine,
 * BINARY(16) UUID columns on MySQL/SQLite) is wrapped as `{"@b64": "<base64>"}`.
 *
 * Encryption is optional: with no password the archive holds every centre's documents and every
 * teacher's password hash in the clear, so callers must store it accordingly; with a password
 * every entry (manifest included) is encrypted with WinZip AES-256. `APP_SECRET` lives outside
 * the database and is never part of the backup either way.
 */
final class DatabaseBackupService
{
    private const FORMAT         = 'atica-calidad-backup';
    private const FORMAT_VERSION = 1;

    /**
     * Transient tables deliberately left out: restoring their rows would do harm, not good.
     * `messenger_messages` is the async mail / scheduler queue — reviving old jobs on a restore
     * would fire outdated notifications. (In the test schema the table doesn't exist at all; the
     * list still documents the intent.)
     */
    private const EXCLUDED_TABLES = ['messenger_messages'];

    /** Rows read per query in a table with no file contents (one at a time in the ones that have them). */
    private const BATCH_ROWS = 200;

    /** A backup raises PHP's memory limit to this if it is lower: encoding one big file takes several times its size. */
    private const MIN_MEMORY_LIMIT_BYTES = 512 * 1048576;

    /** @var array<string, true>|null */
    private ?array $fileTables = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[Autowire('%app.name%')]
        private readonly string $appName,
        #[Autowire('%app.version%')]
        private readonly string $appVersion,
    ) {}

    /**
     * @param string      $directory where to drop the archive; created if missing
     * @param string|null $filename  archive name; defaults to a timestamped one
     * @param string|null $password  when set, every entry is encrypted with WinZip AES-256 —
     *                               the archive then only opens with an AES-aware tool (7-Zip,
     *                               keka, WinRAR) or a future `app:restore`, and never without
     *                               this password
     *
     * @throws \RuntimeException if the destination is unusable or the archive cannot be written
     */
    public function create(string $directory, ?string $filename = null, ?string $password = null): DatabaseBackup
    {
        $directory = rtrim($directory, '/');
        if ($directory === '') {
            $directory = '.';
        }
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Backup directory "%s" could not be created.', $directory));
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException(\sprintf('Backup directory "%s" is not writable.', $directory));
        }

        $this->raiseMemoryLimit();

        $now      = $this->clock->now();
        $filename ??= \sprintf('atica-calidad-backup-%s.zip', $now->format('Y-m-d-His'));
        $target   = $directory . '/' . $filename;

        $work = $directory . '/.backup-' . bin2hex(random_bytes(6));
        if (!@mkdir($work, 0o700, true) && !is_dir($work)) {
            throw new \RuntimeException('Could not create a temporary working directory for the backup.');
        }

        try {
            $tableRowCounts = $this->dumpTables($work);

            $manifest = $this->buildManifest($now, $tableRowCounts, $password !== null);
            file_put_contents(
                $work . '/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
            );

            $this->zip($work, $target, array_keys($tableRowCounts), $password);
        } finally {
            $this->deleteDirectory($work);
        }

        $bytes = filesize($target);

        return new DatabaseBackup($target, $bytes === false ? 0 : $bytes, $tableRowCounts, $password !== null);
    }

    /**
     * Tables that hold file contents (an entity field of type `blob`: DocumentFile, SettingFile).
     *
     * @return array<string, true>
     */
    private function fileTables(): array
    {
        if ($this->fileTables !== null) {
            return $this->fileTables;
        }

        $tables = [];
        foreach ($this->entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->getFieldNames() as $field) {
                if ($metadata->getTypeOfField($field) === 'blob') {
                    $tables[$metadata->getTableName()] = true;
                }
            }
        }

        return $this->fileTables = $tables;
    }

    /** Dumping is paged, so memory no longer grows with the data, but one big file still needs a few times its size. */
    private function raiseMemoryLimit(): void
    {
        $raw = trim((string) \ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return;
        }

        $bytes = (int) $raw;
        $bytes *= match (strtoupper(substr($raw, -1))) {
            'G'     => 1073741824,
            'M'     => 1048576,
            'K'     => 1024,
            default => 1,
        };
        if ($bytes < self::MIN_MEMORY_LIMIT_BYTES) {
            @ini_set('memory_limit', (string) self::MIN_MEMORY_LIMIT_BYTES);
        }
    }

    /**
     * @return array<string, int> table => rows written, ordered by table name
     */
    private function dumpTables(string $work): array
    {
        if (!mkdir($work . '/tables', 0o700, true) && !is_dir($work . '/tables')) {
            throw new \RuntimeException('Could not create the tables directory for the backup.');
        }

        $tables = $this->connection->createSchemaManager()->listTableNames();
        sort($tables);

        $counts = [];
        foreach ($tables as $table) {
            if (\in_array($table, self::EXCLUDED_TABLES, true)) {
                continue;
            }
            $counts[$table] = $this->dumpTable($table, $work . '/tables/' . $table . '.ndjson');
        }

        return $counts;
    }

    private function dumpTable(string $table, string $path): int
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Could not open "%s" for writing.', $path));
        }

        $meta          = $this->connection->createSchemaManager()->introspectTable($table);
        $binaryColumns = $this->binaryColumnsOf($table);

        // One row at a time in a table that holds file contents; in batches in the rest. The tables with file contents
        // come from the ORM mapping, not from the column type: on SQLite a UUID key is a BLOB too.
        $batch    = isset($this->fileTables()[$table]) ? 1 : self::BATCH_ROWS;
        $pk       = $meta->getPrimaryKeyConstraint()?->getColumnNames() ?? [];
        $pkColumn = \count($pk) === 1 ? $pk[0]->toString() : null;
        $pkType   = null;
        if ($pkColumn !== null) {
            foreach ($meta->getColumns() as $column) {
                if ($column->getObjectName()->toString() === $pkColumn) {
                    $pkType = $column->getType();
                }
            }
        }

        $rows = 0;
        try {
            $quotedTable = $this->connection->quoteSingleIdentifier($table);
            if ($pkColumn === null) {
                // No single-column primary key (a join table, small): nothing to page by.
                foreach ($this->connection->iterateAssociative('SELECT * FROM ' . $quotedTable) as $row) {
                    $this->writeRow($handle, $row, $binaryColumns);
                    ++$rows;
                }

                return $rows;
            }

            // Keyset paging: the driver (pdo_mysql, pdo_pgsql) loads a whole result set in memory before handing the
            // first row over, so a single `SELECT *` on the file table would hold every file at once.
            $quotedPk = $this->connection->quoteSingleIdentifier($pkColumn);
            $last     = null;
            do {
                $sql    = 'SELECT * FROM ' . $quotedTable . ($last === null ? '' : ' WHERE ' . $quotedPk . ' > ?') . ' ORDER BY ' . $quotedPk . ' LIMIT ' . $batch;
                $params = $last === null ? [] : [$last];
                $types  = $last === null ? [] : [$pkType instanceof BinaryType || $pkType instanceof BlobType ? ParameterType::BINARY : (\is_int($last) ? ParameterType::INTEGER : ParameterType::STRING)];
                $result = $this->connection->fetchAllAssociative($sql, $params, $types);

                $previous = $last;
                foreach ($result as $row) {
                    $last = $row[$pkColumn] ?? $row[strtolower($pkColumn)];
                    $this->writeRow($handle, $row, $binaryColumns);
                    ++$rows;
                }
                $count = \count($result);
                unset($result, $row);

                if ($count > 0 && $last === $previous) {
                    throw new \RuntimeException(\sprintf('Paging of table "%s" does not advance (key "%s").', $table, $pkColumn));
                }
            } while ($count === $batch);
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param resource             $handle
     * @param array<string, mixed> $row
     * @param array<string, true>  $binaryColumns
     */
    private function writeRow($handle, array $row, array $binaryColumns): void
    {
        $line = json_encode($this->encodeRow($row, $binaryColumns), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        fwrite($handle, $line . "\n");
    }

    /**
     * Column names of $table whose Doctrine type is binary/blob, lower-cased for lookup. These
     * are always base64-wrapped so the archive format doesn't depend on how a given driver hands
     * bytes back (raw string, stream resource, or PostgreSQL's `\x…` hex text).
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

    /**
     * @param array<string, mixed> $row
     * @param array<string, true>  $binaryColumns
     *
     * @return array<string, mixed>
     */
    private function encodeRow(array $row, array $binaryColumns): array
    {
        foreach ($row as $column => $value) {
            if ($value === null) {
                continue;
            }

            if (isset($binaryColumns[strtolower((string) $column)])) {
                $row[$column] = ['@b64' => base64_encode($this->rawBytes($value))];

                continue;
            }

            if (\is_resource($value)) {
                $value = stream_get_contents($value);
            }

            $row[$column] = \is_string($value) && !mb_check_encoding($value, 'UTF-8')
                ? ['@b64' => base64_encode($value)]
                : $value;
        }

        return $row;
    }

    /** Whatever the driver returns for a binary column, normalised to its raw bytes. */
    private function rawBytes(mixed $value): string
    {
        if (\is_resource($value)) {
            return (string) stream_get_contents($value);
        }

        if (!\is_string($value)) {
            return \is_scalar($value) ? (string) $value : '';
        }

        // pdo_pgsql hands back `bytea` in PostgreSQL "hex format": a literal \x then hex digits.
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform && str_starts_with($value, '\x')) {
            $decoded = @hex2bin(substr($value, 2));
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * @param array<string, int> $tableRowCounts
     *
     * @return array<string, mixed>
     */
    private function buildManifest(\DateTimeImmutable $now, array $tableRowCounts, bool $encrypted): array
    {
        return [
            'format'           => self::FORMAT,
            'formatVersion'    => self::FORMAT_VERSION,
            'application'      => $this->appName,
            'appVersion'       => $this->appVersion,
            'createdAt'        => $now->format(\DateTimeInterface::ATOM),
            'databasePlatform' => $this->connection->getDatabasePlatform()::class,
            'schemaVersion'    => $this->latestMigration(),
            'encrypted'        => $encrypted,
            'tables'           => $tableRowCounts,
        ];
    }

    /** The newest applied Doctrine migration, or null if the table isn't there (e.g. the test schema). */
    private function latestMigration(): ?string
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
     * @param list<string> $tables
     */
    private function zip(string $work, string $target, array $tables, ?string $password): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException(\sprintf('Could not create the backup archive "%s".', $target));
        }

        if ($password !== null) {
            $zip->setPassword($password);
        }

        $entries = ['manifest.json'];
        foreach ($tables as $table) {
            $entries[] = 'tables/' . $table . '.ndjson';
        }

        foreach ($entries as $entry) {
            $zip->addFile($work . '/' . $entry, $entry);
            if ($password !== null && !$zip->setEncryptionName($entry, \ZipArchive::EM_AES_256)) {
                $zip->unchangeAll();
                $zip->close();
                @unlink($target);

                throw new \RuntimeException('This build cannot write AES-256 encrypted archives (libzip without a crypto backend).');
            }
        }

        // addFile() defers the actual read until close(), so the working files must still be
        // there now — they are; deleteDirectory() only runs after create() returns.
        if (!$zip->close()) {
            throw new \RuntimeException('The backup archive could not be finalised.');
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
