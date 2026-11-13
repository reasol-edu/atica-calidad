<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Something went wrong around a backup that is not about its contents: the backups folder cannot be
 * created, a restore process cannot be started, the restore state cannot be written.
 */
class BackupException extends \RuntimeException
{
}
