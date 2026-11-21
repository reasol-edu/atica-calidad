<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The restore failed **after** the schema was rebuilt: the database is no longer as it was (it may be empty, at an intermediate
 * migration or half loaded) and only restoring a backup fixes it.
 */
final class SchemaTouchedException extends \RuntimeException {}
