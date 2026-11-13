<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * State of a restore in progress or finished, kept **in files** (not in the database, which is exactly
 * what is being replaced): a lock while it lasts, the result of the last one and the time the last
 * successful one ended.
 *
 * - While the lock exists the application answers 503 (see RestoreGuardSubscriber). The lock holds the
 *   PID and the start time: if the process has died, or a few hours have passed, it counts as
 *   abandoned, so a failure never leaves the site down for good.
 * - Sessions created before the last restore are closed: the users in the backup may not be the same.
 *
 * It uses `time()` and not the application clock on purpose: it is compared with the creation time of
 * the session, which PHP computes with `time()`, and that comparison must not depend on a mocked clock.
 */
class RestoreState
{
    /** A lock older than this is taken as abandoned (a restore takes minutes, not hours). */
    private const STALE_AFTER = 4 * 3600;

    /** Seconds a freshly launched process is given to write its PID into the lock. */
    private const NO_PID_AFTER = 60;

    public function __construct(
        #[Autowire('%app.restore_state_dir%')]
        private readonly string $dir,
    ) {}

    /** A restore is running. A file check, no database: it runs on every request. */
    public function isRunning(): bool
    {
        $lock = $this->lockPath();
        if (!is_file($lock)) {
            return false;
        }

        $info    = $this->readJson($lock);
        $started = \is_int($info['started'] ?? null) ? $info['started'] : (int) @filemtime($lock);
        $pid     = \is_int($info['pid'] ?? null) ? $info['pid'] : 0;

        // No PID means the child process has not started yet: if it takes more than a minute, it never will.
        $neverStarted = $pid === 0 && time() - $started > self::NO_PID_AFTER;
        if (time() - $started > self::STALE_AFTER || $neverStarted || ($pid > 0 && !$this->isAlive($pid))) {
            @unlink($lock);

            return false;
        }

        return true;
    }

    /** Takes the lock. False if a restore is already running (another request, or the console). */
    public function acquire(string $by, string $file): bool
    {
        $this->ensureDir();
        if ($this->isRunning()) {
            return false;
        }

        $handle = @fopen($this->lockPath(), 'x');
        if ($handle === false) {
            return false;
        }
        fwrite($handle, json_encode(['started' => time(), 'pid' => 0, 'by' => $by, 'file' => $file], JSON_THROW_ON_ERROR));
        fclose($handle);

        return true;
    }

    /** Records the process holding the lock (the launcher does not know the child's PID until it starts). */
    public function attachPid(int $pid): void
    {
        $info = $this->readJson($this->lockPath());
        if ($info === []) {
            return;
        }
        $info['pid'] = $pid;
        file_put_contents($this->lockPath(), json_encode($info, JSON_THROW_ON_ERROR));
    }

    public function release(): void
    {
        @unlink($this->lockPath());
    }

    /** Removes a stuck lock by hand (`app:restore --unlock`). Returns whether there was one. */
    public function forceUnlock(): bool
    {
        $had = is_file($this->lockPath());
        @unlink($this->lockPath());

        return $had;
    }

    /** Notes that a restore ended well: sessions older than this instant stop being valid. */
    public function markRestored(): void
    {
        $this->ensureDir();
        file_put_contents($this->dir . '/restored-at', (string) time());
    }

    public function restoredAt(): ?int
    {
        $path = $this->dir . '/restored-at';
        if (!is_file($path)) {
            return null;
        }
        $value = trim((string) @file_get_contents($path));

        return ctype_digit($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $result */
    public function writeResult(array $result): void
    {
        $this->ensureDir();
        file_put_contents($this->dir . '/last-result.json', json_encode($result + ['at' => time()], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null the result of the last restore (ok, by, file, message…) */
    public function lastResult(): ?array
    {
        $data = $this->readJson($this->dir . '/last-result.json');

        return $data === [] ? null : $data;
    }

    /**
     * Who holds the lock and since when, if it exists.
     *
     * @return array<string, mixed>
     */
    public function lockInfo(): array
    {
        return $this->readJson($this->lockPath());
    }

    private function lockPath(): string
    {
        return $this->dir . '/restore.lock';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o700, true) && !is_dir($this->dir)) {
            throw new BackupException(\sprintf('The restore state folder "%s" could not be created.', $this->dir));
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        if (!\is_array($data)) {
            return [];
        }

        $clean = [];
        foreach ($data as $key => $value) {
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    private function isAlive(int $pid): bool
    {
        // Without the posix extension (Windows) it cannot be told: trust the lock's age.
        if (!\function_exists('posix_kill')) {
            return true;
        }

        // "Not permitted" (EPERM = 1) means the process exists, even if it belongs to another user.
        return @posix_kill($pid, 0) || posix_get_last_error() === 1;
    }
}
