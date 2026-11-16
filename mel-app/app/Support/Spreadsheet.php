<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel in and out (OpenSpout, as Finance and Payroll).
 *
 * Out: text always goes into text cells, so "=..." never becomes a
 * formula; numbers given as int, float or a numeric string with
 * `number: true` become number cells.
 * In: the first sheet of an .xlsx, or a .csv, as rows of strings.
 */
final class Spreadsheet
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  list<array{title: string, columns: list<string>, rows: iterable<list<mixed>>, numeric?: list<int>, notes?: list<string>}>  $sheets
     *                                                                                                                                             numeric: indexes of columns holding numbers
     */
    public static function download(string $fileName, array $sheets): StreamedResponse
    {
        return response()->streamDownload(function () use ($sheets) {
            $writer = new Writer(new Options);
            $writer->openToFile('php://output');
            $bold = (new Style)->setFontBold();

            foreach ($sheets as $i => $sheet) {
                $current = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $current->setName(self::sheetName($sheet['title']));

                foreach ($sheet['notes'] ?? [] as $note) {
                    $writer->addRow(new Row([new StringCell($note, null)]));
                }
                $writer->addRow(new Row(array_map(fn (string $heading) => new StringCell($heading, $bold), $sheet['columns'])));

                $numeric = array_flip($sheet['numeric'] ?? []);
                foreach ($sheet['rows'] as $row) {
                    $writer->addRow(new Row(array_map(fn (mixed $value, int $column) => self::cell($value, isset($numeric[$column])), $row, array_keys($row))));
                }
            }

            $writer->close();
        }, $fileName, ['Content-Type' => self::CONTENT_TYPE]);
    }

    /**
     * Rows of the first sheet, each a list of trimmed strings.
     *
     * @return list<list<string>>
     */
    public static function read(string $path, string $extension): array
    {
        $reader = strtolower($extension) === 'csv' ? new CsvReader : new XlsxReader;
        $reader->open($path);
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn (mixed $value) => match (true) {
                        $value === null => '',
                        $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                        is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
                        is_scalar($value) => trim((string) $value),
                        default => '',
                    }, $row->toArray());

                    if (count($rows) > 5000) {
                        break 2;
                    }
                }
                break;
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private static function cell(mixed $value, bool $numeric): Cell
    {
        if ($numeric && (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))) {
            return Cell::fromValue(is_string($value) ? (float) $value : $value);
        }

        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => '',
        };

        return $text === '' ? Cell::fromValue(null) : new StringCell($text, null);
    }

    /** Excel allows 31 characters and none of []:*?/\ in a sheet's name. */
    private static function sheetName(string $name): string
    {
        $clean = trim(preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name) ?? '');

        return mb_substr($clean === '' ? 'Sheet1' : $clean, 0, 31);
    }
}
