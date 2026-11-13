<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Backup;

use App\Entity\EducationalCentre;
use App\Entity\PersonName;
use App\Entity\Teacher;
use App\Service\Backup\BackupLibrary;
use App\Service\Backup\BackupManifest;
use App\Service\Backup\DatabaseBackupService;
use App\Service\Backup\StoredBackup;
use App\Tests\Integration\RepositoryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Backups saved on the server: list, find by exact name, save, delete. */
final class BackupLibraryTest extends RepositoryTestCase
{
    private BackupLibrary $library;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BackupLibrary $library */
        $library       = self::getContainer()->get(BackupLibrary::class);
        $this->library = $library;
        $this->dir     = $library->directory();
    }

    public function testSavingCreatesAFileThatIsListedAndRestorable(): void
    {
        $this->persist((new EducationalCentre())->setCode('41000001')->setName('IES')->setCity('Sevilla'));

        $stored = $this->library->save();

        self::assertMatchesRegularExpression('/^atica-calidad-backup-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $stored->name);
        self::assertFileExists($this->dir . '/' . $stored->name);
        self::assertSame('SQLite', $stored->manifest?->platformName());
        self::assertTrue($stored->isRestorable());
        self::assertNull($stored->problem);

        $list = $this->library->list();
        self::assertCount(1, $list);
        self::assertSame($stored->name, $list[0]->name);
    }

    public function testACopyIsOnlyFoundByItsExactName(): void
    {
        $stored = $this->library->save();

        self::assertNotNull($this->library->find($stored->name));
        self::assertNull($this->library->find('does-not-exist.zip'));
        self::assertNull($this->library->find($stored->name . '.bak'));
        self::assertNull($this->library->find(strtoupper($stored->name)));
    }

    /** @return iterable<string, array{string}> */
    public static function dangerousNames(): iterable
    {
        yield 'traversal' => ['../secret.zip'];
        yield 'subfolder' => ['sub/backup.zip'];
        yield 'absolute path' => ['/etc/passwd.zip'];
        yield 'null byte' => ["backup.zip\0.txt"];
        yield 'no extension' => ['backup'];
        yield 'hidden' => ['.hidden.zip'];
        yield 'with spaces' => ['my backup.zip'];
        yield 'backslash' => ['..\\backup.zip'];
    }

    #[DataProvider('dangerousNames')]
    public function testNamesThatCouldEscapeTheFolderAreRefused(string $name): void
    {
        // A file outside the folder with a "valid" name that someone tries to reach.
        file_put_contents(\dirname($this->dir) . '/secret.zip', 'x');
        @mkdir($this->dir, 0o700, true);

        self::assertNull($this->library->find($name));
        self::assertFalse($this->library->delete($name));
        self::assertFileExists(\dirname($this->dir) . '/secret.zip');

        @unlink(\dirname($this->dir) . '/secret.zip');
    }

    public function testASymlinkLeadingOutsideTheFolderIsRefused(): void
    {
        @mkdir($this->dir, 0o700, true);
        $outside = sys_get_temp_dir() . '/atica-outside-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($outside, 'I am not a backup');
        symlink($outside, $this->dir . '/link.zip');

        self::assertNull($this->library->find('link.zip'), 'A link pointing outside the folder does not count.');
        self::assertFalse($this->library->delete('link.zip'));
        self::assertFileExists($outside);

        @unlink($this->dir . '/link.zip');
        @unlink($outside);
    }

    public function testAFileThatIsNotABackupIsListedAsUnreadable(): void
    {
        @mkdir($this->dir, 0o700, true);
        file_put_contents($this->dir . '/garbage.zip', 'this is not a zip');

        $stored = $this->library->find('garbage.zip');

        self::assertNotNull($stored);
        self::assertNull($stored->manifest);
        self::assertFalse($stored->isRestorable());
        self::assertSame(StoredBackup::PROBLEM_UNREADABLE, $stored->problem);
    }

    public function testACopyWithAnotherSchemaVersionIsListedWithItsProblem(): void
    {
        $stored = $this->library->save();
        $path   = $this->library->path($stored);

        $zip = new \ZipArchive();
        $zip->open($path);
        $data                  = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $data['schemaVersion'] = 'DoctrineMigrations\\Version20991231000000';
        $zip->addFromString('manifest.json', json_encode($data, JSON_THROW_ON_ERROR));
        $zip->close();

        $found = $this->library->find($stored->name);
        self::assertNotNull($found);
        self::assertFalse($found->isRestorable());
        self::assertSame(StoredBackup::PROBLEM_SCHEMA_MISMATCH, $found->problem);
        self::assertInstanceOf(BackupManifest::class, $found->manifest);
    }

    public function testAnEncryptedCopyIsListedButOnlyRestorableFromTheConsole(): void
    {
        /** @var DatabaseBackupService $backup */
        $backup = self::getContainer()->get(DatabaseBackupService::class);
        $backup->create($this->dir, 'locked.zip', 'secret-password');

        $found = $this->library->find('locked.zip');

        self::assertNotNull($found);
        self::assertTrue($found->isEncrypted());
        self::assertFalse($found->isRestorable(), 'A password cannot travel to the background process.');
        self::assertNull($found->manifest);
    }

    public function testDeletingRemovesTheFile(): void
    {
        $stored = $this->library->save();

        self::assertTrue($this->library->delete($stored->name));
        self::assertFileDoesNotExist($this->dir . '/' . $stored->name);
        self::assertSame([], $this->library->list());
    }

    public function testItSaysWhetherAUserIsInsideTheCopy(): void
    {
        $this->persist((new Teacher(new PersonName('Ana', 'López')))->setUsername('ana.lopez'));
        $stored = $this->library->save();

        self::assertTrue($this->library->containsUser($stored, 'ana.lopez'));
        self::assertFalse($this->library->containsUser($stored, 'someone.else'));
    }

    public function testAnEmptyOrMissingFolderListsNothing(): void
    {
        self::assertSame([], $this->library->list());
    }
}
