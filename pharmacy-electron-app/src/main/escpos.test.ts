import { describe, expect, it } from 'vitest';

import { ascii, escpos, paperFrom } from './escpos';

describe('ESC/POS receipts', () => {
  it('makes text plain ASCII a thermal printer prints', () => {
    expect(ascii('2 × Paracetamol · 500 mg — “Café”')).toBe('2 x Paracetamol - 500 mg - "Cafe"');
    expect(ascii('≈ USD 0.77 ✓')).toBe('~ USD 0.77 ?');
  });

  it('initialises, lays out each line, feeds and cuts', () => {
    const bytes = escpos([{ text: 'Juba Pharmacy', align: 'center', bold: true, big: true }, { text: 'Total' }], { cut: true });
    expect([...bytes.subarray(0, 2)]).toEqual([0x1b, 0x40]);
    expect(bytes.includes(Buffer.from([0x1b, 0x61, 1, 0x1b, 0x45, 1, 0x1d, 0x21, 0x01]))).toBe(true);
    expect(bytes.includes(Buffer.from('Juba Pharmacy\n'))).toBe(true);
    expect([...bytes.subarray(-4)]).toEqual([0x1d, 0x56, 0x42, 0x00]);
  });

  it('does not cut when told not to', () => {
    const bytes = escpos([{ text: 'x' }], { cut: false });
    expect([...bytes.subarray(-3)]).toEqual([0x1b, 0x64, 4]);
  });

  it('reads the paper sensor', () => {
    expect(paperFrom(0x12)).toBe('ok');
    expect(paperFrom(0x12 | 0x0c)).toBe('low');
    expect(paperFrom(0x12 | 0x60)).toBe('out');
  });
});
