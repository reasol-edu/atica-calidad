<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\DocumentFile;
use App\Entity\SettingFile;
use App\Entity\TextIndexStatus;
use App\Repository\DocumentFileTextRepository;
use App\Service\DocumentTextIndexer;
use App\Tests\Integration\RepositoryTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class RestoreCommandTest extends RepositoryTestCase
{
    private Application $application;
    private CommandTester $restore;
    private Connection $connection;
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $this->application = new Application($kernel);
        $this->restore     = new CommandTester($this->application->find('app:restore'));

        /** @var Connection $connection */
        $connection       = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        $this->workDir = sys_get_temp_dir() . '/atica-restore-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->workDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->workDir);
        }

        parent::tearDown();
    }

    public function testRoundTripRestoresExactlyWhatWasBackedUp(): void
    {
        $binary = "\x00\x80\xf5\xff" . random_bytes(64);
        $this->persist(
            new SettingFile(hash('sha256', 'a'), 'first', 'text/plain', 5),
            new SettingFile(hash('sha256', 'b'), $binary, 'application/octet-stream', \strlen($binary)),
        );

        $archive = $this->backup();

        // Drift: an extra row appears after the backup.
        $this->persist(new SettingFile(hash('sha256', 'c'), 'added later', 'text/plain', 11));
        self::assertSame(3, $this->countRows('setting_file'));

        $this->restore->execute(['archive' => $archive, '--force' => true]);

        self::assertSame(0, $this->restore->getStatusCode(), $this->restore->getDisplay());
        self::assertSame(2, $this->countRows('setting_file'), 'the post-backup row is gone');

        $restoredBlob = $this->connection->fetchOne(
            "SELECT content FROM setting_file WHERE hash = ?",
            [hash('sha256', 'b')],
        );
        self::assertSame($binary, \is_resource($restoredBlob) ? stream_get_contents($restoredBlob) : $restoredBlob);
    }

    /**
     * The search index is derived data: a copy leaves it out (it would only make every copy bigger) and a restore empties it, so
     * no text survives for files the restore has just replaced; the worker reads the restored files again.
     */
    public function testTheSearchIndexIsNotBackedUpAndIsEmptiedByARestore(): void
    {
        $file = new DocumentFile(hash('sha256', 'doc'), 'Texto del documento', 'text/plain', 'doc.txt', 19);
        $this->persist($file);
        /** @var DocumentFileTextRepository $texts */
        $texts = self::getContainer()->get(DocumentFileTextRepository::class);
        $texts->save($file->getId(), TextIndexStatus::Indexed, DocumentTextIndexer::VERSION, 'Texto del documento', 'texto del documento', null, new \DateTimeImmutable());
        self::assertSame(1, $this->countRows('document_file_text'));

        $archive = $this->backup();
        $zip     = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        self::assertFalse($zip->locateName('tables/document_file_text.ndjson'), 'the index is not part of the copy');
        self::assertNotFalse($zip->locateName('tables/document_file.ndjson'));
        $zip->close();

        // A text row left behind by the old files (foreign keys are suspended during a restore, so nothing cascades).
        $this->restore->execute(['archive' => $archive, '--force' => true]);

        self::assertSame(0, $this->restore->getStatusCode(), $this->restore->getDisplay());
        self::assertSame(1, $this->countRows('document_file'));
        self::assertSame(0, $this->countRows('document_file_text'));
    }

    public function testRestoresAnEncryptedArchive(): void
    {
        $this->persist(new SettingFile(hash('sha256', 'x'), 'secret payload', 'text/plain', 14));

        $archive = $this->backup('cl4ve-secreta');
        $this->truncate('setting_file');
        self::assertSame(0, $this->countRows('setting_file'));

        $this->restore->execute([
            'archive'    => $archive,
            '--password' => 'cl4ve-secreta',
            '--force'    => true,
        ]);

        self::assertSame(0, $this->restore->getStatusCode(), $this->restore->getDisplay());
        self::assertSame(1, $this->countRows('setting_file'));
    }

    public function testFailsWithTheWrongPassword(): void
    {
        $this->persist(new SettingFile(hash('sha256', 'x'), 'payload', 'text/plain', 7));
        $archive = $this->backup('la-buena');
        $this->truncate('setting_file');

        $this->restore->execute([
            'archive'    => $archive,
            '--password' => 'la-mala',
            '--force'    => true,
        ]);

        self::assertSame(1, $this->restore->getStatusCode());
        self::assertSame(0, $this->countRows('setting_file'), 'a failed restore changes nothing');
    }

    public function testRefusesToRunUnattendedWithoutForce(): void
    {
        $this->persist(new SettingFile(hash('sha256', 'x'), 'payload', 'text/plain', 7));
        $archive = $this->backup();
        $this->persist(new SettingFile(hash('sha256', 'y'), 'kept', 'text/plain', 4));

        $this->restore->execute(['archive' => $archive], ['interactive' => false]); // no --force

        self::assertSame(2, $this->restore->getStatusCode()); // Command::INVALID
        self::assertSame(2, $this->countRows('setting_file'), 'nothing touched');
    }

    public function testInteractiveConfirmationCanAbort(): void
    {
        $this->persist(new SettingFile(hash('sha256', 'x'), 'payload', 'text/plain', 7));
        $archive = $this->backup();
        $this->persist(new SettingFile(hash('sha256', 'y'), 'kept', 'text/plain', 4));

        $this->restore->setInputs(['no']);
        $this->restore->execute(['archive' => $archive]);

        self::assertSame(0, $this->restore->getStatusCode());
        self::assertStringContainsString('cancelada', $this->restore->getDisplay());
        self::assertSame(2, $this->countRows('setting_file'));
    }

    public function testFailsWhenTheArchiveIsMissing(): void
    {
        $this->restore->execute([
            'archive' => $this->workDir . '/no-existe.zip',
            '--force' => true,
        ]);

        self::assertSame(1, $this->restore->getStatusCode());
    }

    public function testRefusesASchemaMismatchUnlessForced(): void
    {
        $this->persist(new SettingFile(hash('sha256', 'x'), 'payload', 'text/plain', 7));
        $archive = $this->backup();
        $this->retagSchemaVersion($archive, 'DoctrineMigrations\\Version99999999999999');
        $this->truncate('setting_file');

        // Confirmed by the operator, but the schema guard still refuses without --force.
        $this->restore->setInputs(['yes']);
        $this->restore->execute(['archive' => $archive]);
        self::assertSame(1, $this->restore->getStatusCode());
        self::assertStringContainsString('esquema', $this->restore->getDisplay());
        self::assertSame(0, $this->countRows('setting_file'));

        // With --force: loaded anyway, with a warning.
        $this->restore->execute(['archive' => $archive, '--force' => true]);
        self::assertSame(0, $this->restore->getStatusCode(), $this->restore->getDisplay());
        self::assertStringContainsString('--force', $this->restore->getDisplay());
        self::assertSame(1, $this->countRows('setting_file'));
    }

    private function backup(?string $password = null): string
    {
        $tester = new CommandTester($this->application->find('app:backup'));
        $args   = ['destination' => $this->workDir];
        if ($password !== null) {
            $args['--password'] = $password;
        }
        $tester->execute($args);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $zips = glob($this->workDir . '/*.zip') ?: [];
        self::assertCount(1, $zips);

        return $zips[0];
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $this->connection->quoteSingleIdentifier($table));
    }

    private function truncate(string $table): void
    {
        $this->connection->executeStatement('DELETE FROM ' . $this->connection->quoteSingleIdentifier($table));
    }

    private function retagSchemaVersion(string $archive, string $version): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['schemaVersion'] = $version;
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();
    }
}
