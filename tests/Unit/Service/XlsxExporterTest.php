<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\XlsxExporter;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class XlsxExporterTest extends TestCase
{
    private XlsxExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new XlsxExporter();
    }

    /** @return list<array<int|string, mixed>> */
    private function readRows(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    public function testResponseHasTheExpectedContentTypeAndFilename(): void
    {
        $response = $this->exporter->createResponse('listado.xlsx', ['Nombre'], [['Ana']]);

        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringStartsWith(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $disposition);
        self::assertStringContainsString('listado.xlsx', $disposition);
    }

    public function testWritesTheHeaderRowFollowedByEachDataRow(): void
    {
        $response = $this->exporter->createResponse('listado.xlsx', ['Nombre', 'Edad'], [
            ['Ana', 30],
            ['Luis', 25],
        ]);

        $rows = $this->readRows($response->getFile()->getPathname());

        self::assertSame([
            ['Nombre', 'Edad'],
            ['Ana', '30'],
            ['Luis', '25'],
        ], $rows);
    }

    public function testNullValuesAreWrittenAsEmptyStrings(): void
    {
        $response = $this->exporter->createResponse('listado.xlsx', ['Nombre', 'Comentario'], [
            ['Ana', null],
        ]);

        $rows = $this->readRows($response->getFile()->getPathname());

        self::assertSame(['Ana', ''], $rows[1]);
    }

    public function testAnEmptyRowIterableProducesOnlyTheHeaderRow(): void
    {
        $response = $this->exporter->createResponse('listado.xlsx', ['Nombre'], []);

        $rows = $this->readRows($response->getFile()->getPathname());

        self::assertCount(1, $rows);
        self::assertSame(['Nombre'], $rows[0]);
    }

}
