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

### Gotcha: seed rows with a UUID id, on SQLite, need a blob literal

Doctrine's `uuid` type is 16 raw bytes on SQLite (`BLOB`), on MySQL (`BINARY(16)`, hence
`UNHEX(REPLACE(UUID(), '-', ''))`) and a native `uuid` on PostgreSQL. A migration that seeds a row
on SQLite with `'7d0c4e0a-3f5b-…'` (text) writes an id nothing can reference: every foreign key to
it fails when the application later tries to store a value (the old settings catalogue had this bug,
fixed by `Version20261024110000`). Write it as a hex blob literal: `X'7d0c4e0a3f5b4a9e9a625b1f0d8c2e41'`.

### Verifying a migration: all engines, with Docker whenever it is available

There's no automated suite that runs migrations against every platform, so verify each new or
changed migration by hand against disposable databases before considering it done — **on
PostgreSQL, MySQL and MariaDB with throwaway Docker containers whenever Docker is available**, plus
a scratch SQLite file. "Only tried on SQLite" is not enough: the SQL differs per platform. If Docker
isn't running, say so and list the engines left untested.

| Engine | Image | `MIGRATIONS_PATH` | `serverVersion` in `DATABASE_URL` |
|---|---|---|---|
| PostgreSQL | `postgres:16-alpine` | `migrations/postgresql` | `16` |
| MySQL | `mysql:8.0` | `migrations/mysql` | `8.0.32` |
| MariaDB | `mariadb:10.11` | `migrations/mysql` | `10.11.2-MariaDB` |
| SQLite | — (file under the scratchpad) | `migrations/sqlite` | — |

Per engine:

1. Start the container with its own name and a random host port (`docker run -d --name
   atica-mig-pg -e POSTGRES_PASSWORD=… -e POSTGRES_DB=app -p 127.0.0.1::5432 …`, then `docker
   port`). Use throwaway credentials and never touch containers that belong to other projects.
   Wait until it's really ready: MySQL/MariaDB answer a ping during their init phase and then
   restart, so sleep a few seconds after the first ping.
2. Migrate to the version *before* the new one (`doctrine:migrations:migrate
   'DoctrineMigrations\VersionXXXX'`) and seed rows that exercise whatever the migration moves or
   transforms. Parent rows aren't needed if you disable the checks for the session: `SET
   session_replication_role = replica` (PostgreSQL), `SET FOREIGN_KEY_CHECKS=0` (MySQL, MariaDB).
3. Migrate to the latest and check the data, not just that it ran.
4. `doctrine:schema:validate` must report the schema in sync with the mapping.
5. Run `down` (migrate back to the previous version) and `up` again. Data that `down` can't
   restore is expected to be lost — say so in the migration's description if it matters.
6. Remove the containers (`docker rm -f`).

Run PHPUnit only after the migrations are verified: the test suite runs on SQLite.

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

## Query count: no N+1, and the page-wide costs are paid once

A page's number of queries must not grow with the data it lists. Measured on the demo data, the
heavy pages ran 30–100 queries, almost all of them one lazy query per row. What keeps them flat:

- **A list that callers walk comes with what they read.** Fetch-join the *to-one* associations and
  at most one collection in the list's own query (see `ActivityRepository::findAllByCentre()`,
  `DocumentRepository::findByFolder()`). For several collections use the `PreloadsCollections`
  trait: one extra query per collection for the whole list (`ActivityRepository`,
  `FolderRepository::findBySection()`, `SchoolEventRepository`). Joining several collections into
  one query multiplies rows — don't. `ActivityRepositoryPreloadTest` shows how to assert "reading
  these collections doesn't query" with the `doctrine.debug_data_holder` query log.
- **Several lookups for one list → one query for the list.** `IN (...)` with one `:param` per id,
  each bound with type `'uuid'` (an array of entities or of `Uuid` objects binds wrongly on the
  BINARY(16) platforms and silently matches nothing). Examples: `ActivityCompletionRepository::
  findByOccurrences()` / `countByOccurrences()`, `FindingRepository::findByAudits()` /
  `findByCentreAndStatuses()`.
- **Per-request memos are shared state.** The app can run in a long-lived worker, so a memo on a
  service must implement `ResetInterface` (cleared between requests) and, if a write can change
  the answer, be dropped by a `postFlush` Doctrine listener — copy `QualityTaskFinder`,
  `ActivityCompletionChecker` or `DocumentTreeAccessChecker`. Tests keep one kernel across requests
  but run the same reset between them.
- **Tenant resolution runs on every request**: `TenantContext::getSelectedCentre()` reads the
  centre with its year, admins, quality managers and internal auditors in one query
  (`findByIdForTenant()`) and re-checks access with one `EXISTS` query (`isAccessibleByTeacher()`),
  none at all for a centre admin. Don't add a lazy read of those collections on the hot path.
- **Profile checks** (`DocumentTreeAccessChecker::can…/holds…`) answer from the teacher's
  assignments, loaded once per request (`holds()`), never with a query per folder or section.

To measure, boot the test kernel against a seeded SQLite database, request the page and read the
query log (`doctrine.debug_data_holder`), listing each query's table and the `src/` frame that
issued it — the repeated ones are the N+1.

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
