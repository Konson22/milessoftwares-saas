<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Several tables as one Excel (.xlsx) download, a sheet each. Amounts
 * (keys ending in "Minor") become numbers in major units of the workspace
 * currency, so they add up in Excel; other text stays text, never a formula.
 */
final class XlsxWorkbook
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  list<array{name: string, columns: array<string, string>, rows: iterable<array<string, mixed>>}>  $sheets
     *                                                                                                                   columns: heading => row key
     */
    public static function download(string $file, array $sheets, ?string $currency = null): StreamedResponse
    {
        $decimals = Money::decimals($currency);

        return response()->streamDownload(function () use ($sheets, $decimals) {
            $writer = new Writer(new Options);
            $writer->openToFile('php://output');

            $bold = (new Style)->setFontBold();
            $amount = (new Style)->setFormat($decimals === 0 ? '#,##0' : '#,##0.'.str_repeat('0', $decimals));

            foreach ($sheets as $index => $sheet) {
                if ($index > 0) {
                    $writer->addNewSheetAndMakeItCurrent();
                }
                $current = $writer->getCurrentSheet();
                $current->setName(self::sheetName($sheet['name']));
                foreach (array_keys($sheet['columns']) as $i => $heading) {
                    $current->setColumnWidth(max(12, min(48, mb_strlen($heading) + 6)), $i + 1);
                }

                $writer->addRow(Row::fromValues(array_keys($sheet['columns']), $bold));
                foreach ($sheet['rows'] as $row) {
                    $writer->addRow(new Row(array_map(
                        fn (string $key) => self::cell($key, $row[$key] ?? null, $decimals, $amount),
                        array_values($sheet['columns']),
                    )));
                }
            }

            $writer->close();
        }, "{$file}.xlsx", ['Content-Type' => self::CONTENT_TYPE]);
    }

    private static function cell(string $key, mixed $value, int $decimals, Style $amount): Cell
    {
        if (is_int($value) && str_ends_with($key, 'Minor')) {
            return Cell::fromValue($value / (10 ** $decimals), $amount);
        }
        if (is_int($value) || is_float($value)) {
            return Cell::fromValue($value);
        }
        $text = match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => '',
        };

        // Always a text cell: Cell::fromValue would turn "=..." into a formula.
        return $text === '' ? Cell::fromValue(null) : new StringCell($text, null);
    }

    /** Excel allows 31 characters and none of []:*?/\ in a sheet's name. */
    private static function sheetName(string $name): string
    {
        $clean = trim(preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $name) ?? '');

        return mb_substr($clean === '' ? 'Sheet' : $clean, 0, 31);
    }
}
