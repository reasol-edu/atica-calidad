<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AttachmentZipExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class AttachmentZipExporterTest extends TestCase
{
    private AttachmentZipExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new AttachmentZipExporter();
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

    public function testResponseIsAZipAttachmentNamedAsRequested(): void
    {
        $response = $this->exporter->createResponse('carpeta.zip', [['name' => 'a.txt', 'content' => 'A']]);

        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $disposition);
        self::assertStringContainsString('carpeta.zip', $disposition);
    }

    public function testWritesEveryEntryWithItsContentIncludingSubdirectories(): void
    {
        $response = $this->exporter->createResponse('c.zip', [
            ['name' => 'raiz.txt', 'content' => 'R'],
            ['name' => 'Perfil A/doc.txt', 'content' => 'D'],
        ]);

        self::assertSame(
            ['raiz.txt' => 'R', 'Perfil A/doc.txt' => 'D'],
            $this->readZip($response->getFile()->getPathname()),
        );
    }

    public function testDeduplicatesCollidingNamesKeepingDirectoryAndExtension(): void
    {
        $response = $this->exporter->createResponse('c.zip', [
            ['name' => 'Perfil A/informe.pdf', 'content' => '1'],
            ['name' => 'Perfil A/informe.pdf', 'content' => '2'],
            ['name' => 'Perfil A/informe.pdf', 'content' => '3'],
        ]);

        self::assertSame([
            'Perfil A/informe.pdf'     => '1',
            'Perfil A/informe (2).pdf' => '2',
            'Perfil A/informe (3).pdf' => '3',
        ], $this->readZip($response->getFile()->getPathname()));
    }

    public function testAnEmptyEntryListStillProducesAValidEmptyArchive(): void
    {
        $response = $this->exporter->createResponse('c.zip', []);
        $path     = $response->getFile()->getPathname();

        self::assertFileExists($path);
        self::assertSame("PK\x05\x06" . str_repeat("\x00", 18), (string) file_get_contents($path));
    }

    public function testNonAsciiZipNameKeepsTheUtf8NameButAddsAnAsciiFallback(): void
    {
        $response = $this->exporter->createResponse('Sección Ñ.zip', [['name' => 'a', 'content' => 'a']]);

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString("filename*=utf-8''", strtolower($disposition));
        // the plain filename="" fallback must not carry any non-ASCII byte
        self::assertSame(1, preg_match('/filename="([\x20-\x7E]*)"/', $disposition));
    }
}
