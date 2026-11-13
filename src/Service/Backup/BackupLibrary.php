<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The backups kept on the server (`APP_BACKUP_DIR`, `var/backups` by default — the folder
 * `app:backup` writes to too): list them, save a new one, delete them, find them. A backup is always
 * asked for **by name**, which must match a file of the folder exactly: a path is never built from
 * what comes in from the web.
 */
class BackupLibrary
{
    /** Letters, digits, dot, dash and underscore only, ending in .zip, not starting with a dot. */
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.zip$/';

    /** More than this are not listed (the screen is not a file explorer). */
    private const MAX_LISTED = 50;

    public function __construct(
        private readonly DatabaseBackupService $backup,
        private readonly DatabaseRestoreService $restore,
        private readonly ClockInterface $clock,
        #[Autowire('%app.backup_dir%')]
        private readonly string $dir,
    ) {}

    public function directory(): string
    {
        return $this->dir;
    }

    /** @return list<StoredBackup> newest first */
    public function list(): array
    {
        if (!is_dir($this->dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            if (preg_match(self::NAME_PATTERN, $name) === 1 && is_file($this->dir . '/' . $name)) {
                $files[$name] = (int) filemtime($this->dir . '/' . $name);
            }
        }
        arsort($files);

        $stored = [];
        foreach (\array_slice(array_keys($files), 0, self::MAX_LISTED) as $name) {
            $stored[] = $this->describe($name, $files[$name]);
        }

        return $stored;
    }

    /** The backup with exactly that name, if it is in the folder. */
    public function find(string $name): ?StoredBackup
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || !$this->isInside($name)) {
            return null;
        }

        return $this->describe($name, (int) filemtime($this->dir . '/' . $name));
    }

    /** Full path of a backup already located with find() / list(). */
    public function path(StoredBackup $backup): string
    {
        return $this->dir . '/' . $backup->name;
    }

    /**
     * Saves an unencrypted backup of the current data in the folder. (It stays on the server's own
     * disk, next to the database it holds; encrypt what you take off the server — see the download.)
     *
     * @throws BackupException when the folder or the archive cannot be created
     */
    public function save(string $prefix = 'atica-calidad-backup'): StoredBackup
    {
        $name = \sprintf('%s-%s.zip', $prefix, $this->clock->now()->format('Y-m-d-His'));

        try {
            $this->backup->create($this->dir, $name);
        } catch (\RuntimeException $e) {
            throw new BackupException($e->getMessage(), previous: $e);
        }

        return $this->find($name) ?? throw new BackupException('The backup was created but it is not in the backups folder.');
    }

    public function delete(string $name): bool
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1 || !$this->isInside($name)) {
            return false;
        }

        return @unlink($this->dir . '/' . $name);
    }

    /**
     * Whether the teacher with that username is in the backup (a restore replaces every account: whoever
     * restores must know which credentials they will come back with). Null if it cannot be checked.
     */
    public function containsUser(StoredBackup $backup, string $username): ?bool
    {
        if ($backup->manifest === null) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($this->path($backup), \ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $stream = $zip->getStream('tables/teacher.ndjson');
            if ($stream === false) {
                return null;
            }
            try {
                while (($line = fgets($stream)) !== false) {
                    $row = json_decode($line, true);
                    if (\is_array($row) && ($row['username'] ?? null) === $username) {
                        return true;
                    }
                }
            } finally {
                fclose($stream);
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    private function describe(string $name, int $modifiedAt): StoredBackup
    {
        $path = $this->dir . '/' . $name;
        $size = (int) filesize($path);

        if ($this->isEncrypted($path)) {
            return new StoredBackup($name, $size, $modifiedAt, null, StoredBackup::PROBLEM_ENCRYPTED);
        }

        try {
            $manifest = $this->restore->inspect($path);
        } catch (BackupException) {
            return new StoredBackup($name, $size, $modifiedAt, null, StoredBackup::PROBLEM_UNREADABLE);
        }

        $problem = $manifest->schemaVersion !== $this->restore->databaseSchemaVersion() ? StoredBackup::PROBLEM_SCHEMA_MISMATCH : null;

        return new StoredBackup($name, $size, $modifiedAt, $manifest, $problem);
    }

    /** `app:backup --password` encrypts every entry, the manifest included. */
    private function isEncrypted(string $path): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            $stat = $zip->statName('manifest.json');

            return \is_array($stat) && $stat['encryption_method'] !== \ZipArchive::EM_NONE;
        } finally {
            $zip->close();
        }
    }

    /** The file exists and, once symbolic links are resolved, lies inside the backups folder. */
    private function isInside(string $name): bool
    {
        $real = realpath($this->dir . '/' . $name);
        $base = realpath($this->dir);

        return $real !== false && $base !== false && is_file($real) && str_starts_with($real, $base . \DIRECTORY_SEPARATOR);
    }
}
