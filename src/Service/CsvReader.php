<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Parses uploaded CSV files into header-keyed rows, normalizing encoding (BOM/Windows-1252)
 * and skipping blank lines. Shared by the CSV-based import flows (students, centre teachers,
 * teacher assignments) to avoid re-implementing the same stream/fgetcsv boilerplate in each.
 *
 * @phpstan-type ParsedCsv array{headers: list<string>, rows: list<array<string, string>>}
 */
final class CsvReader
{
    /** @return ParsedCsv */
    public function parse(string $content): array
    {
        $content = ltrim($content, "\xEF\xBB\xBF");
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return ['headers' => [], 'rows' => []];
        }

        fwrite($stream, $content);
        rewind($stream);

        $headerRow = fgetcsv($stream, escape: '');
        if ($headerRow === false || $headerRow[0] === null) {
            fclose($stream);

            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(static fn (?string $h): string => trim($h ?? ''), $headerRow);

        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            if (count(array_filter($row, static fn ($v): bool => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $i => $name) {
                $assoc[$name] = trim((string) ($row[$i] ?? ''));
            }
            $rows[] = $assoc;
        }

        fclose($stream);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param list<string> $headers
     * @param list<string> $required
     */
    public function findMissingColumn(array $headers, array $required): ?string
    {
        foreach ($required as $col) {
            if (!in_array($col, $headers, true)) {
                return $col;
            }
        }

        return null;
    }
}
