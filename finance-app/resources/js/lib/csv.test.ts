import { describe, expect, it } from 'vite-plus/test';
import { parseAmount, parseCsv, parseDate } from './csv';

describe('parseCsv', () => {
    it('reads quoted cells with separators, quotes and line breaks inside', () => {
        const text =
            '﻿Date,Details,Amount\r\n01/10/2026,"Rent, October","-1,200.00"\r\n02/10/2026,"He said ""hi""\nthere",50\n\n';

        expect(parseCsv(text)).toEqual([
            ['Date', 'Details', 'Amount'],
            ['01/10/2026', 'Rent, October', '-1,200.00'],
            ['02/10/2026', 'He said "hi"\nthere', '50'],
        ]);
    });

    it('guesses semicolons and tabs', () => {
        expect(parseCsv('a;b;c\n1;2;3')).toEqual([
            ['a', 'b', 'c'],
            ['1', '2', '3'],
        ]);
        expect(parseCsv('a\tb\n1\t2')).toEqual([
            ['a', 'b'],
            ['1', '2'],
        ]);
    });
});

describe('parseDate', () => {
    it('reads the bank order and refuses impossible dates', () => {
        expect(parseDate('31/10/2026', 'd/m/Y')).toBe('2026-10-31');
        expect(parseDate('10/31/2026', 'm/d/Y')).toBe('2026-10-31');
        expect(parseDate('2026-10-31', 'Y-m-d')).toBe('2026-10-31');
        expect(parseDate('31.10.26', 'd.m.Y')).toBe('2026-10-31');
        expect(parseDate('31/02/2026', 'd/m/Y')).toBeNull();
        expect(parseDate('Total', 'd/m/Y')).toBeNull();
    });
});

describe('parseAmount', () => {
    it('handles decimal marks, thousands, currencies and negative forms', () => {
        expect(parseAmount('1,234.5', '.', 2)).toBe('1234.50');
        expect(parseAmount('1.234,50', ',', 2)).toBe('1234.50');
        expect(parseAmount('SSP -12.00', '.', 2)).toBe('-12.00');
        expect(parseAmount('(45.10)', '.', 2)).toBe('-45.10');
        expect(parseAmount('45.10-', '.', 2)).toBe('-45.10');
        expect(parseAmount('2500', '.', 0)).toBe('2500');
        expect(parseAmount('', '.', 2)).toBe('');
        expect(parseAmount('0.00', '.', 2)).toBe('0');
        expect(parseAmount('n/a', '.', 2)).toBeNull();
    });
});
