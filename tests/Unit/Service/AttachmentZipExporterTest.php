<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AttachmentZipExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class AttachmentZipExporterTest extends TestCase
{
    private AttachmentZipExporter $exporter;

    /** @var list<string> ZIPs created by a test, removed afterwards (never sent, so BinaryFileResponse doesn't delete them) */
    private array $created = [];

    protected function setUp(): void
    {
        $this->exporter = new AttachmentZipExporter();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param array<array-key, array{0: string, 1: string}> $entries [name, content] pairs
     */
    private function export(string $zipFilename, array $entries): BinaryFileResponse
    {
        $response = $this->exporter->createResponse($zipFilename, array_map(
            static fn (array $e): array => ['name' => $e[0], 'write' => static fn (string $path) => file_put_contents($path, $e[1])],
            array_values($entries),
        ));
        $this->created[] = $response->getFile()->getPathname();

        return $response;
    }

    /** @return array<string, string> entry path => content, in archive order */
    private function readZip(string $path): array
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));

        $out = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $out[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $out;
    }

    /** @return list<string> this exporter's leftovers in the temp directory (ZIPs and scratch directories) */
    private function tempLeftovers(): array
    {
        return array_values(array_diff(glob(sys_get_temp_dir() . '/atica_zip_*') ?: [], $this->created));
    }

    public function testResponseIsAZipAttachmentNamedAsRequested(): void
    {
        $response = $this->export('carpeta.zip', [['a.txt', 'A']]);

        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $disposition);
        self::assertStringContainsString('carpeta.zip', $disposition);
    }

    public function testWritesEveryEntryWithItsContentIncludingSubdirectories(): void
    {
        $response = $this->export('c.zip', [
            ['raiz.txt', 'R'],
            ['Perfil A/doc.txt', 'D'],
        ]);

        self::assertSame(
            ['raiz.txt' => 'R', 'Perfil A/doc.txt' => 'D'],
            $this->readZip($response->getFile()->getPathname()),
        );
    }

    public function testDeduplicatesCollidingNamesKeepingDirectoryAndExtension(): void
    {
        $response = $this->export('c.zip', [
            ['Perfil A/informe.pdf', '1'],
            ['Perfil A/informe.pdf', '2'],
            ['Perfil A/informe.pdf', '3'],
        ]);

        self::assertSame([
            'Perfil A/informe.pdf'     => '1',
            'Perfil A/informe (2).pdf' => '2',
            'Perfil A/informe (3).pdf' => '3',
        ], $this->readZip($response->getFile()->getPathname()));
    }

    public function testAnEmptyEntryListStillProducesAValidEmptyArchive(): void
    {
        $response = $this->export('c.zip', []);
        $path     = $response->getFile()->getPathname();

        self::assertFileExists($path);
        self::assertSame("PK\x05\x06" . str_repeat("\x00", 18), (string) file_get_contents($path));
    }

    public function testNonAsciiZipNameKeepsTheUtf8NameButAddsAnAsciiFallback(): void
    {
        $response = $this->export('Sección Ñ.zip', [['a', 'a']]);

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString("filename*=utf-8''", strtolower($disposition));
        // the plain filename="" fallback must not carry any non-ASCII byte
        self::assertSame(1, preg_match('/filename="([\x20-\x7E]*)"/', $disposition));
    }

    /** Already-compressed formats are stored as is; only text formats are deflated. */
    public function testOnlyTextFormatsAreCompressed(): void
    {
        $text     = str_repeat('texto repetido ', 1000);
        $response = $this->export('c.zip', [['acta.pdf', $text], ['notas.TXT', $text]]);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($response->getFile()->getPathname()));
        $pdf = $zip->statName('acta.pdf');
        $txt = $zip->statName('notas.TXT');
        $zip->close();

        self::assertIsArray($pdf);
        self::assertIsArray($txt);
        self::assertSame(\ZipArchive::CM_STORE, $pdf['comp_method']);
        self::assertSame(\ZipArchive::CM_DEFLATE, $txt['comp_method']);
    }

    /** Each entry is written to disk only when its turn comes, and no scratch file outlives the call. */
    public function testWritesEntriesOneByOneAndLeavesOnlyTheZipBehind(): void
    {
        $written = [];
        $entries = (static function () use (&$written): \Generator {
            foreach (['a.pdf', 'b.pdf', 'c.pdf'] as $name) {
                yield ['name' => $name, 'write' => static function (string $path) use ($name, &$written): void {
                    $written[] = $name;
                    file_put_contents($path, $name);
                }];
            }
        })();

        $response        = $this->exporter->createResponse('c.zip', $entries);
        $this->created[] = $response->getFile()->getPathname();

        self::assertSame(['a.pdf', 'b.pdf', 'c.pdf'], $written);
        self::assertSame(['a.pdf' => 'a.pdf', 'b.pdf' => 'b.pdf', 'c.pdf' => 'c.pdf'], $this->readZip($response->getFile()->getPathname()));
        self::assertSame([], $this->tempLeftovers());
    }

    public function testAFailingEntryLeavesNothingBehind(): void
    {
        $entries = [
            ['name' => 'ok.pdf', 'write' => static fn (string $path) => file_put_contents($path, 'ok')],
            ['name' => 'roto.pdf', 'write' => static function (): void {
                throw new \RuntimeException('Fallo al leer el fichero');
            }],
        ];

        try {
            $this->exporter->createResponse('c.zip', $entries);
            self::fail('the failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('Fallo al leer el fichero', $e->getMessage());
        }

        self::assertSame([], $this->tempLeftovers());
    }
}
