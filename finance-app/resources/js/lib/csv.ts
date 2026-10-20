/**
 * Reading banks' CSV exports in the browser: cells (quoted or not, comma,
 * semicolon or tab separated), dates in the bank's order, and amounts with
 * the bank's decimal mark.
 */

/** Splits CSV text into rows of cells; the separator is guessed from the first line. */
export function parseCsv(text: string): string[][] {
    const clean = text.replace(/^﻿/, '');
    const firstLine = clean.split(/\r?\n/, 1)[0] ?? '';
    const separator = [',', ';', '\t'].reduce((best, candidate) =>
        firstLine.split(candidate).length > firstLine.split(best).length
            ? candidate
            : best,
    );

    const rows: string[][] = [];
    let row: string[] = [];
    let cell = '';
    let quoted = false;

    for (let i = 0; i < clean.length; i++) {
        const char = clean[i];

        if (quoted) {
            if (char === '"' && clean[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (char === '"') {
                quoted = false;
            } else {
                cell += char;
            }
        } else if (char === '"') {
            quoted = true;
        } else if (char === separator) {
            row.push(cell.trim());
            cell = '';
        } else if (char === '\n' || char === '\r') {
            if (char === '\r' && clean[i + 1] === '\n') {
                i++;
            }
            row.push(cell.trim());
            if (row.some((value) => value !== '')) {
                rows.push(row);
            }
            row = [];
            cell = '';
        } else {
            cell += char;
        }
    }
    row.push(cell.trim());
    if (row.some((value) => value !== '')) {
        rows.push(row);
    }

    return rows;
}

export const DATE_FORMATS = [
    { value: 'Y-m-d', label: '2026-10-31' },
    { value: 'd/m/Y', label: '31/10/2026' },
    { value: 'm/d/Y', label: '10/31/2026' },
    { value: 'd.m.Y', label: '31.10.2026' },
    { value: 'd-m-Y', label: '31-10-2026' },
] as const;

/** "31/10/2026" in d/m/Y → "2026-10-31"; null when it is not such a date. */
export function parseDate(value: string, format: string): string | null {
    const parts = value
        .trim()
        .split(/[^0-9]+/)
        .filter(Boolean);
    if (parts.length < 3) {
        return null;
    }

    const order = format.replace(/[^Ymd]/g, '').split('');
    const get = (key: string) => Number(parts[order.indexOf(key)]);
    let year = get('Y');
    const month = get('m');
    const day = get('d');
    if (year < 100) {
        year += 2000;
    }

    const date = new Date(Date.UTC(year, month - 1, day));
    if (
        date.getUTCFullYear() !== year ||
        date.getUTCMonth() !== month - 1 ||
        date.getUTCDate() !== day
    ) {
        return null;
    }

    return date.toISOString().slice(0, 10);
}

/**
 * "1.234,50" with a comma decimal mark → "1234.50"; brackets and a trailing
 * minus mean negative. Null when it is not an amount; "" when blank.
 */
export function parseAmount(
    value: string,
    decimalMark: '.' | ',',
    decimals: number,
): string | null {
    let text = value.trim();
    if (text === '') {
        return '';
    }

    let negative = false;
    if (/^\(.*\)$/.test(text)) {
        negative = true;
        text = text.slice(1, -1);
    }
    if (text.endsWith('-')) {
        negative = true;
        text = text.slice(0, -1);
    }
    // A minus before the number, also after a currency code ("SSP -12").
    const firstDigit = text.search(/\d/);
    if (firstDigit > 0 && text.slice(0, firstDigit).includes('-')) {
        negative = !negative;
    }

    // Currency codes and symbols, spaces, and the thousands separator go.
    text = text.replace(/[^0-9.,]/g, '');
    text =
        decimalMark === ','
            ? text.replace(/\./g, '').replace(',', '.')
            : text.replace(/,/g, '');

    if (!/^\d+(\.\d+)?$/.test(text)) {
        return null;
    }

    const amount = Number(text);
    if (amount === 0) {
        return '0';
    }

    return `${negative ? '-' : ''}${amount.toFixed(decimals)}`;
}
