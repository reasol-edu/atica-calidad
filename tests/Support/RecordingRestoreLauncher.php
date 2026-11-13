<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Backup\BackupException;
use App\Service\Backup\RestoreLauncher;

/**
 * Stands in for the real launcher in tests: it starts no process (restoring for real would replace the
 * database), it only records what it was asked. The state is static because the test client reboots the
 * kernel between requests; call `clear()` at the start of each test.
 */
final class RecordingRestoreLauncher implements RestoreLauncher
{
    /** @var list<array{zip: string, by: string}> */
    public static array $launched = [];

    public static bool $available = true;
    public static ?string $failWith = null;

    public static function clear(): void
    {
        self::$launched  = [];
        self::$available = true;
        self::$failWith  = null;
    }

    public function isAvailable(): bool
    {
        return self::$available;
    }

    public function launch(string $zipPath, string $by): void
    {
        if (self::$failWith !== null) {
            throw new BackupException(self::$failWith);
        }
        self::$launched[] = ['zip' => $zipPath, 'by' => $by];
    }

    public function consoleCommand(string $zipPath): string
    {
        return 'php bin/console app:restore ' . escapeshellarg($zipPath);
    }
}
