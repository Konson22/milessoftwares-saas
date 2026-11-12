<?php

namespace App\Support;

use App\Finance\Money;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A table as an Excel (.xlsx) download (?export=<table> on a report): the
 * same rows the page shows. Amounts (keys ending in "Minor") are numbers in
 * major units of the books' currency, so they add up in Excel; other text,
 * such as account codes, stays text.
 */
final class XlsxExport
{
    public const CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  array<string, string>  $columns  heading => row key; keys ending in "Minor" are amounts
     * @param  iterable<array<string, mixed>>  $rows
     * @param  int|null  $decimals  of the amounts; the books' currency's when null (payroll always keeps two)
     */
    public static function download(string $name, array $columns, iterable $rows, ?int $decimals = null): StreamedResponse
    {
        $decimals ??= Money::decimals(Money::currency());

        return response()->streamDownload(function () use ($name, $columns, $rows, $decimals) {
            $options = new Options;
            foreach (array_keys($columns) as $i => $heading) {
                $options->setColumnWidth(max(12, min(48, mb_strlen($heading) + 4)), $i + 1);
            }

            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $writer->getCurrentSheet()->setName(self::sheetName($name));

            $writer->addRow(Row::fromValues(array_keys($columns), (new Style)->setFontBold()));

            $amount = (new Style)->setFormat($decimals === 0 ? '#,##0' : '#,##0.'.str_repeat('0', $decimals));
            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(
                    fn (string $key) => self::cell($key, $row[$key] ?? null, $decimals, $amount),
                    array_values($columns),
                )));
            }

            $writer->close();
        }, "{$name}.xlsx", ['Content-Type' => self::CONTENT_TYPE]);
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

        return mb_substr($clean === '' ? 'Sheet1' : $clean, 0, 31);
    }
}
