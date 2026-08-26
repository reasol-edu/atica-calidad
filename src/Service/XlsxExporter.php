<?php

declare(strict_types=1);

namespace App\Service;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class XlsxExporter
{
    private const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param list<string>                                        $headers
     * @param iterable<array<int|string, string|int|float|null>> $rows
     */
    public function createResponse(string $filename, array $headers, iterable $rows): BinaryFileResponse
    {
        $tempPath = sys_get_temp_dir() . '/' . uniqid('gestconv_export_', true) . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($tempPath);

        $headerStyle = (new Style())->withFontBold(true);
        $writer->addRow(Row::fromValuesWithStyle($headers, $headerStyle));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_values(array_map(
                static fn (string|int|float|null $v): string => (string) ($v ?? ''),
                $row,
            ))));
        }

        $writer->close();

        $response = new BinaryFileResponse($tempPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->deleteFileAfterSend(true);

        return $response;
    }
}
