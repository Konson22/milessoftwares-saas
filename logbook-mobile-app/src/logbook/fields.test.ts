/// <reference types="jest" />
import { check, clean, defaults, number, summary, text, visible } from './fields';
import type { FieldDef } from './types';

const f = (type: FieldDef['type'], extra: Partial<FieldDef> = {}): FieldDef => ({ name: 'x', label: 'X', type, required: false, ...extra });

describe('field rules (the server\'s, on the phone)', () => {
  it('normalizes decimals exactly as the server keeps them', () => {
    expect(number('decimal', '0012.500')).toBe('12.5');
    expect(number('decimal', '-0.000')).toBe('0');
    expect(number('decimal', '3,25')).toBe('3.25');
    expect(number('decimal', '1.1234567')).toBeNull();
    expect(number('integer', '42')).toBe(42);
    expect(number('integer', '4.2')).toBeNull();
  });

  it('checks bounds, dates, times and choices', () => {
    expect(check(f('integer', { min: 0, max: 10 }), '11')).toEqual({ ok: false, error: 'At most 10.' });
    expect(check(f('date'), '2026-02-30')).toEqual({ ok: false, error: 'Enter a date (YYYY-MM-DD).' });
    expect(check(f('date'), '2024-02-29')).toEqual({ ok: true, value: '2024-02-29' });
    expect(check(f('time'), '24:00').ok).toBe(false);
    expect(check(f('datetime'), '2026-11-19 07:05')).toEqual({ ok: true, value: '2026-11-19T07:05' });
    const choices = [
      { value: 'a', label: 'A' },
      { value: 'b', label: 'B' },
    ];
    expect(check(f('select_multiple', { choices }), ['b', 'a'])).toEqual({ ok: true, value: ['a', 'b'] });
    expect(check(f('select_one', { choices }), 'c').ok).toBe(false);
    expect(check(f('text'), '  two   words ')).toEqual({ ok: true, value: 'two words' });
  });

  it('requires answers and files only when finishing', () => {
    const fields = [f('text', { name: 'note', required: true }), f('file', { name: 'photo', required: true })];
    expect(clean(fields, {}, {}, false).errors).toEqual({});
    expect(clean(fields, {}, {}, true).errors).toEqual({ note: 'This field is required.', photo: 'Attach at least one file.' });
    expect(clean(fields, { note: 'ok' }, { photo: 1 }, true).errors).toEqual({});
  });

  it('hides a conditional field, clears it and does not require it (the server\'s show_if)', () => {
    const fields = [
      f('yes_no', { name: 'leak', required: true }),
      f('text', { name: 'where', required: true, show_if: { field: 'leak', in: ['yes'] } }),
      f('text', { name: 'size', required: true, show_if: { field: 'where', in: ['x'] } }),
    ];
    expect(clean(fields, { leak: false, where: 'cleared' }, {}, true)).toEqual({ values: { leak: false }, errors: {} });
    expect(clean(fields, { leak: true }, {}, true).errors).toEqual({ where: 'This field is required.' });
    expect(clean(fields, { leak: 'yes', where: 'Pump 2' }, {}, true).values).toEqual({ leak: true, where: 'Pump 2' });
    // A field whose parent is hidden is hidden too.
    expect(visible(fields[2], { leak: false, where: 'x' }, fields)).toBe(false);
    const multi = f('text', { show_if: { field: 'checks', in: ['oil'] } });
    expect(visible(multi, { checks: ['coolant', 'oil'] })).toBe(true);
    expect(visible(multi, { checks: [] })).toBe(false);
  });

  it('takes a resource by id and reads it by name', () => {
    expect(check(f('resource'), 'not-an-id')).toEqual({ ok: false, error: 'Choose a resource.' });
    expect(check(f('resource'), '0193a0c4-1d2e-7f00-8a00-000000000001').ok).toBe(true);
    expect(text(f('resource'), 'r1', { r1: 'GEN-01 · Main generator' })).toBe('GEN-01 · Main generator');
  });

  it('reads values as people do', () => {
    const choices = [{ value: 'ok', label: 'All good' }];
    expect(text(f('select_one', { choices }), 'ok')).toBe('All good');
    expect(text(f('yes_no'), false)).toBe('No');
    expect(text(f('user'), 'u1', { u1: 'Ada' })).toBe('Ada');
    expect(summary([f('text', { name: 'a' }), f('integer', { name: 'b' })], { a: 'Pump 2', b: 7 })).toBe('Pump 2 · 7');
    expect(defaults([f('integer', { default: 3 }), f('text', { name: 'y' })])).toEqual({ x: 3 });
  });
});
