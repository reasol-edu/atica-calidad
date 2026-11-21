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

    /** Name prefix of the scheduled backups: the only ones retention may delete. */
    public const AUTO_PREFIX = 'atica-calidad-auto';

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

    /**
     * The scheduled nightly backup. Written under a `.part` name that the listing pattern does not match and renamed
     * when complete, so a crash (or a run still going) never leaves a half-written archive that looks like a backup.
     *
     * @throws BackupException
     */
    public function saveAutomatic(): StoredBackup
    {
        $name = \sprintf('%s-%s.zip', self::AUTO_PREFIX, $this->clock->now()->format('Y-m-d-His'));
        $part = $name . '.part';

        try {
            $this->backup->create($this->dir, $part);
            if (!@rename($this->dir . '/' . $part, $this->dir . '/' . $name)) {
                throw new \RuntimeException('The finished backup could not be renamed.');
            }
        } catch (\Throwable $e) {
            @unlink($this->dir . '/' . $part);

            throw new BackupException($e->getMessage(), previous: $e);
        }

        return $this->find($name) ?? throw new BackupException('The backup was created but it is not in the backups folder.');
    }

    /**
     * Prunes the automatic backups according to the retention policy: it keeps the newest backup of each of the last N days,
     * of the last N weeks and of the last N months that have one (see BackupRetentionPolicy) and deletes the rest, plus any
     * `.part` leftover of an interrupted run. If `$maxBytes` is above 0 it then deletes the oldest of those kept until they
     * fit; the newest backup is always kept, even if it alone is bigger (better one backup too big than none). Only files
     * named `atica-calidad-auto-*.zip`: backups saved by hand, by `app:backup` or put there over SSH are never touched.
     *
     * The date of a backup is the one in its name (when it was made), not the file's modification time, which copying
     * or restoring a folder changes.
     *
     * @return int how many backups were deleted
     */
    public function pruneAutomatic(BackupRetentionPolicy $policy, int $maxBytes = 0): int
    {
        if (!$policy->isOn() || !is_dir($this->dir)) {
            return 0;
        }

        $automatic = [];
        foreach (scandir($this->dir) ?: [] as $name) {
            $path = $this->dir . '/' . $name;
            if (preg_match('/^' . preg_quote(self::AUTO_PREFIX, '/') . '-(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})\.zip$/', $name, $m) === 1 && is_file($path)) {
                $madeAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', \sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]));
                $automatic[$name] = $madeAt !== false ? $madeAt : (new \DateTimeImmutable())->setTimestamp((int) filemtime($path));
            } elseif (preg_match('/^' . preg_quote(self::AUTO_PREFIX, '/') . '-[0-9-]+\.zip\.part$/', $name) === 1 && is_file($path) && time() - (int) filemtime($path) > 6 * 3600) {
                @unlink($path);
            }
        }
        // Newest first.
        uksort($automatic, static fn (string $a, string $b): int => [$automatic[$b], $b] <=> [$automatic[$a], $a]);
        $names = array_keys($automatic);

        // Newest of each day / ISO week / month, for as many days / weeks / months as the policy asks.
        $keepNames = [];
        foreach (['daily' => 'Y-m-d', 'weekly' => 'o-\WW', 'monthly' => 'Y-m'] as $tier => $format) {
            $limit = $policy->tiers()[$tier];
            $seen  = [];
            foreach ($names as $name) {
                $bucket = $automatic[$name]->format($format);
                if (isset($seen[$bucket])) {
                    continue; // an even newer one already represents this day/week/month
                }
                if (\count($seen) >= $limit) {
                    break;
                }
                $seen[$bucket]    = true;
                $keepNames[$name] = true;
            }
        }
        if ($names !== []) {
            $keepNames[$names[0]] = true; // the newest is never pruned
        }

        $deleted = 0;
        foreach ($names as $name) {
            if (!isset($keepNames[$name]) && @unlink($this->dir . '/' . $name)) {
                ++$deleted;
            }
        }

        if ($maxBytes > 0) {
            $remaining = array_values(array_filter($names, static fn (string $name): bool => isset($keepNames[$name])));
            $total     = array_sum(array_map(fn (string $name): int => (int) @filesize($this->dir . '/' . $name), $remaining));
            while (\count($remaining) > 1 && $total > $maxBytes) {
                $oldest = array_pop($remaining);
                $total -= (int) @filesize($this->dir . '/' . $oldest);
                if (@unlink($this->dir . '/' . $oldest)) {
                    ++$deleted;
                }
            }
        }

        return $deleted;
    }

    /**
     * When the newest backup in the folder (manual or automatic) was written, or null if there is none. Cheap: it only
     * reads modification times, it opens no archive (the listing opens every one to describe it).
     */
    public function lastBackupAt(): ?int
    {
        if (!is_dir($this->dir)) {
            return null;
        }

        $latest = null;
        foreach (scandir($this->dir) ?: [] as $name) {
            if (preg_match(self::NAME_PATTERN, $name) === 1 && is_file($this->dir . '/' . $name)) {
                $latest = max($latest ?? 0, (int) filemtime($this->dir . '/' . $name));
            }
        }

        return $latest;
    }

    /**
     * Bytes taken by all the backups in the folder and how many there are (file sizes only, no archive is opened).
     *
     * @return array{bytes: int, count: int}
     */
    public function usage(): array
    {
        $bytes = 0;
        $count = 0;
        if (is_dir($this->dir)) {
            foreach (scandir($this->dir) ?: [] as $name) {
                if (preg_match(self::NAME_PATTERN, $name) === 1 && is_file($this->dir . '/' . $name)) {
                    $bytes += (int) filesize($this->dir . '/' . $name);
                    ++$count;
                }
            }
        }

        return ['bytes' => $bytes, 'count' => $count];
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

        // A backup from another migration is only a problem when this application does not know it (it is from a newer
        // version): one from an earlier version is restored by rebuilding the schema at its migration (DatabaseRestoreService).
        $problem = $manifest->schemaVersion !== $this->restore->databaseSchemaVersion() && !$this->restore->isKnownSchema($manifest)
            ? StoredBackup::PROBLEM_SCHEMA_MISMATCH
            : null;

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
