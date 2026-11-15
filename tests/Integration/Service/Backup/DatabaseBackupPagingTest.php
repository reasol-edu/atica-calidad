<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Backup;

use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use App\Service\Backup\DatabaseBackupService;
use App\Tests\Integration\RepositoryTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The dump reads each table by pages of its primary key (pdo_mysql and pdo_pgsql load a whole result set in memory
 * before returning the first row, so a single `SELECT *` on the file table held every file at once): every row must
 * still be written exactly once, across the batch boundaries and with binary (UUID) keys.
 */
final class DatabaseBackupPagingTest extends RepositoryTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var string $dir */
        $dir       = self::getContainer()->getParameter('app.backup_dir');
        $this->dir = $dir . '/paging';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.zip') ?: []);
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testATableLargerThanOneBatchIsWrittenCompleteAndWithoutDuplicates(): void
    {
        // More than two batches of 200 rows, so there are two page boundaries.
        foreach (range(1, 450) as $i) {
            $this->em->persist((new SettingDefinition())->setKey(\sprintf('paging.test_%03d', $i))->setType(SettingType::String)->setDefaultValue((string) $i));
        }
        $this->em->flush();

        $rows = $this->tableRows('setting_definition');

        $keys = array_map(static fn (array $row): string => (string) $row['key'], $rows);
        $paging = array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'paging.test_')));
        self::assertCount(450, $paging);
        self::assertCount(450, array_unique($keys), 'a row was written twice');
    }

    public function testFileContentsAreDumpedOneRowAtATimeAndStayIntact(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $expected   = [];
        foreach (range(1, 7) as $i) {
            $content = random_bytes(5000 + $i) . "\0\xff"; // binary, not valid UTF-8
            $id      = Uuid::v7();
            $connection->insert('document_file', [
                'id' => $id, 'hash' => hash('sha256', $content), 'content' => $content, 'mime_type' => 'application/octet-stream',
                'original_filename' => "f$i.bin", 'size' => \strlen($content), 'created_at' => new \DateTimeImmutable(),
            ], ['id' => 'uuid', 'content' => 'blob', 'created_at' => 'datetime_immutable']);
            $expected[hash('sha256', $content)] = $content;
        }

        $rows = $this->tableRows('document_file');

        self::assertCount(7, $rows);
        foreach ($rows as $row) {
            self::assertIsArray($row['content']);
            $decoded = base64_decode((string) $row['content']['@b64'], true);
            self::assertSame($expected[$row['hash']] ?? null, $decoded, 'a file came out different from what was stored');
            unset($expected[$row['hash']]);
        }
        self::assertSame([], $expected, 'a file is missing from the backup');
    }

    /** @return list<array<string, mixed>> */
    private function tableRows(string $table): array
    {
        /** @var DatabaseBackupService $service */
        $service = self::getContainer()->get(DatabaseBackupService::class);
        $backup  = $service->create($this->dir, 'paging.zip');

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($backup->path, \ZipArchive::RDONLY));
        $ndjson = (string) $zip->getFromName('tables/' . $table . '.ndjson');
        $zip->close();

        $rows = [];
        foreach (array_filter(explode("\n", $ndjson)) as $line) {
            /** @var array<string, mixed> $row */
            $row    = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            $rows[] = $row;
        }

        return $rows;
    }
}
