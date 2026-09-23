# ÁTICA Calidad — database and repositories

*Read this file before creating a migration, a repository, or any new query, or
when adding/modifying Doctrine entities.*

## Migrations: three platforms, not one

The project supports PostgreSQL, MySQL/MariaDB, and SQLite. Migrations **don't**
live in a single folder: there are three, in parallel, with the same version
number:

```
migrations/mysql/VersionYYYYMMDDNNNNNN.php
migrations/postgresql/VersionYYYYMMDDNNNNNN.php
migrations/sqlite/VersionYYYYMMDDNNNNNN.php
```

`config/packages/doctrine_migrations.yaml` points to a single path via the
`MIGRATIONS_PATH` environment variable — Doctrine only sees and runs the
migrations for the active platform in each environment.

**When adding or modifying anything in the schema, write the migration in all
three folders**, with the same version timestamp and semantically equivalent
content — the concrete SQL differs per platform (e.g. `gen_random_uuid()` in
PostgreSQL, MySQL's own syntax, and SQLite's `ALTER TABLE`/`INTEGER PRIMARY KEY`
limitations).

Structure of each migration:

```php
final class VersionYYYYMMDDNNNNNN extends AbstractMigration
{
    public function getDescription(): string { /* in Spanish: explains what changed and why */ }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration can only run on PostgreSQL.'
        );
        $this->addSql(<<<'SQL' ... SQL);
    }

    public function down(Schema $schema): void
    {
        // same platform guard; undoes exactly what up() did
    }
}
```

The **platform guard** (`abortIf` against `PostgreSQLPlatform`/`MySQLPlatform`/
`SqlitePlatform` depending on the folder) is mandatory in every file — it prevents
a migration from the wrong folder from running by mistake if `MIGRATIONS_PATH`
were misconfigured.

Verify manually against a disposable database before considering a migration done
(there's no automated suite that runs them against all three platforms).

## Repositories: named methods only, never generic access

A project-specific PHPStan rule
(`src/PHPStan/Rules/ForbidGenericDoctrineMethodsRule.php`, level `max`)
**forbids** calling `find()`/`getRepository()` on `EntityManagerInterface` or
`getRepository()` on `ManagerRegistry` **outside of** classes under
`App\Repository\`. Static analysis fails if you do.

In practice:

- Never do `$this->em->find(Teacher::class, $id)` nor
  `$this->em->getRepository(Teacher::class)->...` from a controller, service, or
  handler.
- Inject the concrete repository (e.g. `TeacherRepository`) and add an explicit,
  named method (`findByCentreAndId(...)`, `findByAcademicYearOrdered(...)`)
  instead of an ad-hoc query. This keeps all data-access logic — including the
  allowlisted sortable columns (`SORTABLE` constant in several repositories) and
  tenant filtering — centralized and reviewable in one place.
- Repositories extend `ServiceEntityRepository` with the standard constructor
  (`parent::__construct($registry, Entity::class)`).

## Academic year isolation

Pattern used in several repositories: an **optional, additive**
`?AcademicYear $year = null` parameter at the end of the method signature, which
adds a `WHERE ... = :year` filter only when passed. This allows reusing the same
method both for views scoped to one year and for cross-year historical queries.
Don't add the filter unconditionally to a method that's meant to support both.

## Entities

- Primary key: `Uuid` (v7) via `symfony/uid`, not integers.
- For file-typed values attached to a setting, use the generic, hash-deduplicated
  storage (`SettingFile` + FK from the value row, keyed by SHA-256 hash) instead
  of creating a table specific to a single use case.

## File contents (BLOB columns): never hydrate many at once

`DocumentFile::$content` (and `SettingFile`'s) is a BLOB column on the entity itself — Doctrine
can't lazy-load a single column, so hydrating a `DocumentFile` loads its whole content, and the
identity map keeps it for the rest of the request. Fine for one download; for anything that walks
over many files (a folder's ZIP, an export…) it multiplies memory by the total size (measured:
~3× the folder; an HTTP 500 past a few tens of MB).

- Read contents one file at a time with `DocumentFileRepository::writeContentTo($fileId, $path)`
  (a plain query per file, straight to disk), and get file metadata as plain columns
  (e.g. `DocumentRepository::findActiveFilesInFolder()`), not through `$revision->getFile()`.
- Never select several contents in one query: MySQL's driver buffers the whole result set.
- `AttachmentZipExporter` takes entries as `['name' => …, 'write' => fn (string $path) => …]` and
  adds them with `ZipArchive::addFile()`, so only one entry is in memory at a time.
  `FolderZipExporterMemoryTest` guards this.
