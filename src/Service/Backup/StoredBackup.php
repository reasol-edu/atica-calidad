<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * A backup kept in the server's backups folder, with what is known about it without restoring it.
 *
 * `problem` says why it cannot be restored from the web (null = it can): `encrypted` (the manifest
 * is locked behind a password, so only `app:restore --password` can read it), `unreadable` (not a
 * backup of this application) or `schema_mismatch` (taken with another migration than the one the
 * database is at, so the rows may not fit).
 */
final readonly class StoredBackup
{
    public const PROBLEM_ENCRYPTED       = 'encrypted';
    public const PROBLEM_UNREADABLE      = 'unreadable';
    public const PROBLEM_SCHEMA_MISMATCH = 'schema_mismatch';

    public function __construct(
        public string $name,
        public int $size,
        public int $modifiedAt,
        public ?BackupManifest $manifest,
        public ?string $problem,
    ) {}

    public function isRestorable(): bool
    {
        return $this->manifest !== null && $this->problem === null;
    }

    public function isEncrypted(): bool
    {
        return $this->problem === self::PROBLEM_ENCRYPTED;
    }
}
