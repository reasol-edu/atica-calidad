<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Backup;

use App\Service\Backup\BackupArchiveException;
use App\Service\Backup\DatabaseBackupService;
use App\Service\Backup\DatabaseRestoreService;
use App\Service\Backup\SchemaMigrator;
use App\Service\Backup\SchemaMismatchException;
use App\Service\Backup\SchemaTouchedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Restoring a backup made at an earlier migration: the schema is rebuilt at that migration, the data loaded, and the rest of the
 * migrations applied. It runs on a database of its own (a SQLite file with the REAL migrations), never on the suite's one, which is
 * built from the mapping: dropping every table there would leave the other tests without their schema.
 */
final class RestoreOlderSchemaTest extends KernelTestCase
{
    /** Before the search index (document_file_text) and the settings that later migrations seed. */
    private const OLD = 'DoctrineMigrations\Version20261115100000';

    private string $workDir;
    private Connection $db;
    private SchemaMigrator $migrator;
    private DatabaseRestoreService $restore;
    private DatabaseBackupService $backup;

    private mixed $previousDatabaseUrl;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();

        $this->workDir = sys_get_temp_dir() . '/atica-older-schema-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0o700, true);

        // The whole container works on a database file of its own: some migrations call services that use the default connection,
        // so a second connection beside it would not do. The suite's database is left alone.
        $this->previousDatabaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        $url                       = 'sqlite:///' . $this->workDir . '/db.sqlite';
        $_SERVER['DATABASE_URL']   = $_ENV['DATABASE_URL'] = $url;
        putenv('DATABASE_URL=' . $url);

        self::bootKernel();

        /** @var Connection $db */
        $db       = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->db = $db;
        /** @var SchemaMigrator $migrator */
        $migrator       = self::getContainer()->get(SchemaMigrator::class);
        $this->migrator = $migrator;
        /** @var DatabaseRestoreService $restore */
        $restore       = self::getContainer()->get(DatabaseRestoreService::class);
        $this->restore = $restore;
        /** @var DatabaseBackupService $backup */
        $backup       = self::getContainer()->get(DatabaseBackupService::class);
        $this->backup = $backup;
    }

    protected function tearDown(): void
    {
        $this->db->close();
        self::ensureKernelShutdown();

        if ($this->previousDatabaseUrl === null) {
            unset($_SERVER['DATABASE_URL'], $_ENV['DATABASE_URL']);
            putenv('DATABASE_URL');
        } else {
            $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $this->previousDatabaseUrl;
            putenv('DATABASE_URL=' . (string) $this->previousDatabaseUrl);
        }

        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->workDir);

        parent::tearDown();
    }

    private function addCentre(string $code, string $name): void
    {
        $this->db->executeStatement('INSERT INTO educational_centre (id, code, name, city) VALUES (?, ?, ?, ?)', [Uuid::v7()->toBinary(), $code, $name, 'Ciudad'], [ParameterType::BINARY]);
    }

    /** @return list<string> */
    private function centreNames(): array
    {
        /** @var list<string> $names */
        $names = $this->db->fetchFirstColumn('SELECT name FROM educational_centre ORDER BY name');

        return $names;
    }

    private function definitions(string $key): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM setting_definition WHERE "key" = ?', [$key]);
    }

    private function latestVersion(): mixed
    {
        return $this->db->fetchOne('SELECT MAX(version) FROM doctrine_migration_versions');
    }

    /** An old-schema backup of a database with one centre, and the database moved on to the latest migration with another centre. */
    private function oldBackupAndMovedOnDatabase(): string
    {
        $this->migrator->rebuildAt(self::OLD);
        self::assertSame(self::OLD, $this->latestVersion());
        self::assertSame(0, $this->definitions('search.ocr'), 'The old schema does not know the OCR setting yet.');
        $this->addCentre('41000001', 'IES Antiguo');

        $path = $this->backup->create($this->workDir, 'old.zip')->path;

        $this->migrator->migrateToLatest();
        $this->addCentre('41000002', 'IES Nuevo');

        return $path;
    }

    public function testItKnowsWhichMigrationsItShipsAndWhichIsTheLatest(): void
    {
        self::assertTrue($this->migrator->isKnown(self::OLD));
        self::assertFalse($this->migrator->isKnown('DoctrineMigrations\Version29990101000000'));
        self::assertTrue($this->migrator->isKnown($this->migrator->latest()));
        self::assertGreaterThan(self::OLD, $this->migrator->latest());
    }

    public function testACopyOfAnEarlierMigrationIsRestoredRebuildingTheSchemaAndMigratingToTheLatest(): void
    {
        $path = $this->oldBackupAndMovedOnDatabase();
        $copy = $this->restore->inspect($path);
        self::assertTrue($this->restore->needsRebuild($copy));
        self::assertSame(['IES Antiguo', 'IES Nuevo'][1], $this->centreNames()[1], 'Before restoring, the database holds the new centre.');

        $result = $this->restore->restore($path);

        self::assertTrue($result->rebuilt);
        self::assertFalse($result->schemaOverridden);
        self::assertSame(['IES Antiguo'], $this->centreNames(), 'The data of the copy is there, not the data from before.');
        self::assertSame($this->migrator->latest(), $this->latestVersion(), 'It is migrated to the latest afterwards.');
        // What the later migrations add exists, and the table they created works.
        self::assertSame(1, $this->definitions('search.ocr'));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(ocr_page) FROM document_file_text'));
    }

    public function testTheRebuildWinsOverForceBecauseLoadingOldRowsIntoTheNewTablesWouldBeWorse(): void
    {
        $path = $this->oldBackupAndMovedOnDatabase();

        $result = $this->restore->restore($path, null, true);

        self::assertTrue($result->rebuilt);
        self::assertFalse($result->schemaOverridden);
        self::assertSame(['IES Antiguo'], $this->centreNames());
    }

    public function testACopyOfTheSameMigrationIsRestoredInOneTransactionWithoutRebuilding(): void
    {
        $this->migrator->migrateToLatest();
        $this->addCentre('41000001', 'IES Uno');
        $path = $this->backup->create($this->workDir, 'now.zip')->path;
        $this->addCentre('41000002', 'IES Dos');

        $result = $this->restore->restore($path);

        self::assertFalse($result->rebuilt);
        self::assertSame(['IES Uno'], $this->centreNames());
        self::assertFalse($this->restore->needsRebuild($this->restore->inspect($path)));
    }

    public function testACopyFromAMigrationThisVersionDoesNotKnowIsRefusedBeforeTouchingAnything(): void
    {
        $this->migrator->migrateToLatest();
        $this->addCentre('41000001', 'IES Uno');
        $path = $this->backup->create($this->workDir, 'newer.zip')->path;
        $newer = $this->withManifest($path, static function (array $manifest): array {
            $manifest['schemaVersion'] = 'DoctrineMigrations\Version29990101000000';

            return $manifest;
        });
        $this->addCentre('41000002', 'IES Dos');

        self::assertFalse($this->restore->needsRebuild($this->restore->inspect($newer)));
        try {
            $this->restore->restore($newer);
            self::fail('A copy from a newer version must be refused.');
        } catch (SchemaMismatchException $e) {
            self::assertSame('DoctrineMigrations\Version29990101000000', $e->backupSchemaVersion);
        }
        self::assertSame(['IES Dos', 'IES Uno'], $this->centreNames(), 'Nothing was touched.');
    }

    public function testAFailureAfterTheRebuildIsReportedAsTouchedAndTheGoodCopyBringsTheDatabaseBack(): void
    {
        $old  = $this->oldBackupAndMovedOnDatabase();
        $good = $this->backup->create($this->workDir, 'before.zip')->path; // the current data: what the console saves before restoring
        $bad  = $this->withManifest($old, static function (array $manifest): array {
            $manifest['tables']['educational_centre'] = (int) $manifest['tables']['educational_centre'] + 1; // the rows will not add up

            return $manifest;
        });

        try {
            $this->restore->restore($bad);
            self::fail('The load must fail.');
        } catch (SchemaTouchedException $e) {
            self::assertStringContainsString('intermediate state', $e->getMessage());
        }

        // Back to the data from before, rebuilding the schema once more (the versions table may be gone: `rebuild`).
        $this->restore->restore($good, null, true, true);

        self::assertSame(['IES Antiguo', 'IES Nuevo'], $this->centreNames());
        self::assertSame($this->migrator->latest(), $this->latestVersion());
        self::assertSame(1, $this->definitions('search.ocr'));
    }

    public function testAnArchiveThatIsNotACopyStillFailsBeforeTouchingAnything(): void
    {
        $this->migrator->migrateToLatest();
        $this->addCentre('41000001', 'IES Uno');
        file_put_contents($this->workDir . '/no.zip', 'esto no es un zip');

        $this->expectException(BackupArchiveException::class);
        try {
            $this->restore->restore($this->workDir . '/no.zip');
        } finally {
            self::assertSame(['IES Uno'], $this->centreNames());
        }
    }

    /**
     * A copy of the archive with its manifest rewritten.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    private function withManifest(string $path, \Closure $change): string
    {
        $copy = $this->workDir . '/' . bin2hex(random_bytes(4)) . '.zip';
        copy($path, $copy);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($copy));
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $zip->addFromString('manifest.json', json_encode($change($manifest), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $zip->close();

        return $copy;
    }
}
