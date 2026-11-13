<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Container\ContainerInterface;

/**
 * Empties the restore-state and saved-backups folders the tests use. A lock or a backup surviving a
 * failed test would contaminate the following ones (the lock puts the application in 503).
 */
final class RestoreStateCleaner
{
    public static function clean(ContainerInterface $container): void
    {
        foreach (['app.restore_state_dir', 'app.backup_dir'] as $parameter) {
            try {
                $dir = $container->get('parameter_bag')->get($parameter);
            } catch (\Throwable) {
                continue;
            }
            if (\is_string($dir) && str_contains($dir, '/var/cache/test')) {
                self::remove($dir);
            }
        }
        RecordingRestoreLauncher::clear();
    }

    private static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::remove($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
