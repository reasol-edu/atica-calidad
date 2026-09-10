<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The backup archive is missing, unreadable, not an ÁTICA Calidad backup, or needs a password
 * that was not supplied (or was wrong).
 */
final class BackupArchiveException extends \RuntimeException
{
}
