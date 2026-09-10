<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The backup was taken against a different database schema than the one currently deployed
 * (`doctrine_migration_versions` disagree). Restoring anyway needs an explicit override, since
 * the row data may not fit the live tables.
 */
final class SchemaMismatchException extends \RuntimeException
{
    public function __construct(
        public readonly ?string $backupSchemaVersion,
        public readonly ?string $databaseSchemaVersion,
    ) {
        parent::__construct(\sprintf(
            'Backup schema version (%s) does not match the database (%s).',
            $backupSchemaVersion ?? 'none',
            $databaseSchemaVersion ?? 'none',
        ));
    }
}
