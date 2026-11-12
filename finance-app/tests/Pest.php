<?php

use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/**
 * An .xlsx download read back as CSV lines (numbers as PHP prints them:
 * 1000.0 → "1000", 6.5 → "6.5"), so tests can look for rows.
 */
function xlsxText(string $binary): string
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path, $binary);
    $reader = new Reader;
    $reader->open($path);
    $out = fopen('php://memory', 'w+');
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            fputcsv($out, array_map(fn ($value) => match (true) {
                $value === null => '',
                is_float($value) && floor($value) === $value => (string) (int) $value,
                default => (string) $value,
            }, $row->toArray()), escape: '');
        }
    }
    $reader->close();
    unlink($path);
    rewind($out);

    return stream_get_contents($out) ?: '';
}
