<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\SettingFile;
use App\Tests\Integration\RepositoryTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class BackupCommandTest extends RepositoryTestCase
{
    private CommandTester $tester;
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $this->tester = new CommandTester($application->find('app:backup'));

        $this->outputDir = sys_get_temp_dir() . '/atica-backup-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->outputDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                /** @var \SplFileInfo $entry */
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->outputDir);
        }

        parent::tearDown();
    }

    public function testWritesAZipWithAManifestAndOneNdjsonPerTable(): void
    {
        $this->persist(
            $this->settingFile('one'),
            $this->settingFile('two'),
        );

        $this->tester->execute(['destination' => $this->outputDir]);

        self::assertSame(0, $this->tester->getStatusCode());

        $zip      = $this->openTheArchive();
        $manifest = $this->manifestOf($zip);

        self::assertSame('atica-calidad-backup', $manifest['format']);
        self::assertSame(1, $manifest['formatVersion']);
        self::assertFalse($manifest['encrypted']);
        self::assertArrayHasKey('databasePlatform', $manifest);

        // Every mapped table is listed, empty ones included...
        self::assertArrayHasKey('teacher', $manifest['tables']);
        self::assertSame(0, $manifest['tables']['teacher']);
        // ...and the seeded one carries its real count.
        self::assertSame(2, $manifest['tables']['setting_file']);

        $ndjson = $zip->getFromName('tables/setting_file.ndjson');
        self::assertIsString($ndjson);
        $lines = array_values(array_filter(explode("\n", $ndjson), static fn (string $l): bool => $l !== ''));
        self::assertCount(2, $lines);
    }

    public function testExcludesTheTransientMessengerQueue(): void
    {
        $this->tester->execute(['destination' => $this->outputDir]);

        $zip      = $this->openTheArchive();
        $manifest = $this->manifestOf($zip);

        self::assertArrayNotHasKey('messenger_messages', $manifest['tables']);
        self::assertFalse($zip->locateName('tables/messenger_messages.ndjson'));
    }

    public function testEncodesNonUtf8ValuesAsBase64(): void
    {
        // "\x80" on its own is an invalid UTF-8 continuation byte, so this is guaranteed binary.
        $binary = "\x00\x80\xf5\xff" . random_bytes(48);
        $this->persist($this->settingFile('bin', $binary));

        $this->tester->execute(['destination' => $this->outputDir]);

        $zip    = $this->openTheArchive();
        $ndjson = $zip->getFromName('tables/setting_file.ndjson');
        self::assertIsString($ndjson);

        /** @var array<string, mixed> $row */
        $row = json_decode(trim($ndjson), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($row['content']);
        self::assertSame(base64_encode($binary), $row['content']['@b64']);
        // A plain UTF-8 column stays a plain string.
        self::assertIsString($row['mime_type']);
        self::assertSame('application/octet-stream', $row['mime_type']);
    }

    public function testAcceptsAnExplicitZipPath(): void
    {
        $target = $this->outputDir . '/nested/mi-copia.zip';

        $this->tester->execute(['destination' => $target]);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertFileExists($target);
    }

    public function testIsNotEncryptedByDefault(): void
    {
        $this->tester->execute(['destination' => $this->outputDir]);

        $zip = $this->openTheArchive();
        self::assertFalse($this->manifestOf($zip)['encrypted']);
        self::assertSame(\ZipArchive::EM_NONE, $zip->statName('manifest.json')['encryption_method']);
    }

    public function testEncryptsEveryEntryWhenAPasswordIsPassed(): void
    {
        $this->persist($this->settingFile('secret'));

        $this->tester->execute([
            'destination' => $this->outputDir,
            '--password'  => 's3cr3t-passphrase',
        ]);

        self::assertSame(0, $this->tester->getStatusCode());

        $zip = $this->openTheArchive();

        // Every entry carries AES-256, manifest and table dumps alike.
        self::assertSame(\ZipArchive::EM_AES_256, $zip->statName('manifest.json')['encryption_method']);
        self::assertSame(\ZipArchive::EM_AES_256, $zip->statName('tables/setting_file.ndjson')['encryption_method']);

        // Only the right password decrypts, and the manifest records the fact.
        $zip->setPassword('s3cr3t-passphrase');
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($manifest['encrypted']);
        self::assertSame(1, $manifest['tables']['setting_file']);
        self::assertIsString($zip->getFromName('tables/setting_file.ndjson'));
    }

    public function testPromptsForThePasswordWhenTheOptionCarriesNoValue(): void
    {
        $this->tester->setInputs(['prompted-pass', 'prompted-pass']);
        $this->tester->execute([
            'destination' => $this->outputDir,
            '--password'  => null,
        ]);

        self::assertSame(0, $this->tester->getStatusCode());

        $zip = $this->openTheArchive();
        self::assertSame(\ZipArchive::EM_AES_256, $zip->statName('manifest.json')['encryption_method']);
        $zip->setPassword('prompted-pass');
        self::assertTrue(json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR)['encrypted']);
    }

    public function testFailsWhenThePromptedPasswordsDoNotMatch(): void
    {
        $this->tester->setInputs(['one', 'two']);
        $this->tester->execute([
            'destination' => $this->outputDir,
            '--password'  => null,
        ]);

        self::assertSame(2, $this->tester->getStatusCode());
        self::assertStringContainsString('no coinciden', $this->tester->getDisplay());
        self::assertSame([], glob($this->outputDir . '/*.zip') ?: []);
    }

    public function testFailsWhenAskedToPromptNonInteractively(): void
    {
        $this->tester->execute(
            ['destination' => $this->outputDir, '--password' => null],
            ['interactive' => false],
        );

        self::assertSame(2, $this->tester->getStatusCode());
        self::assertSame([], glob($this->outputDir . '/*.zip') ?: []);
    }

    private function settingFile(string $seed, ?string $content = null): SettingFile
    {
        $content ??= 'contents-' . $seed;

        return new SettingFile(hash('sha256', $seed), $content, 'application/octet-stream', \strlen($content));
    }

    private function openTheArchive(): \ZipArchive
    {
        $zips = glob($this->outputDir . '/*.zip') ?: [];
        self::assertCount(1, $zips, 'exactly one backup archive is written');

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zips[0]) === true);

        return $zip;
    }

    /** @return array<string, mixed> */
    private function manifestOf(\ZipArchive $zip): array
    {
        $json = $zip->getFromName('manifest.json');
        self::assertIsString($json);

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $manifest;
    }
}
