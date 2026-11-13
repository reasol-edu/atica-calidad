<?php

declare(strict_types=1);

namespace App\Service\Backup;

/** Starts a restore in a separate process, so it does not depend on the web request that asked for it. */
interface RestoreLauncher
{
    /** Whether this server can start processes (shared hosting usually disables it). */
    public function isAvailable(): bool;

    /**
     * Starts `app:restore` on the backup and returns at once. The caller has already taken the lock:
     * the child process inherits it (`--lock-held`) and releases it when it ends.
     *
     * @throws BackupException if the process could not be started
     */
    public function launch(string $zipPath, string $by): void;

    /** The equivalent console command, to show when it cannot be launched from the web. */
    public function consoleCommand(string $zipPath): string;
}
