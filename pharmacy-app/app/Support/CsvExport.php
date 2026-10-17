<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report table as a CSV download (?export=<table> on a report): the
 * same rows the page shows, amounts in major units.
 */
final class CsvExport
{
    /**
     * @param  array<string, string>  $columns  heading => row key; keys ending in "Minor" are amounts
     * @param  iterable<array<string, mixed>>  $rows
     */
    public static function download(string $name, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\u{FEFF}"); // so spreadsheet programs read UTF-8
            fputcsv($out, array_keys($columns), escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $key) => self::cell($key, $row[$key] ?? null), array_values($columns)), escape: '');
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function cell(string $key, mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) && str_ends_with($key, 'Minor')) {
            return Money::toMajor($value);
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        $text = is_scalar($value) ? (string) $value : '';

        // Text a spreadsheet would run as a formula is kept as text.
        return ! is_numeric($text) && preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'{$text}" : $text;
    }
}
