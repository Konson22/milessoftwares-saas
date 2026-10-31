import { dateTime, money } from './format';
import type { Counter, Sale } from './types';

type ReceiptSettings = Counter['receipt'];

const ESC = 0x1b;
const GS = 0x1d;

/** Characters per line in the printer's standard font. */
export const columnsFor = (paper: ReceiptSettings['paper_size']) => (paper === '58mm' ? 32 : 48);

/**
 * The printer's code pages differ from model to model: keep to ASCII, with
 * the few signs the receipt uses spelled out and accents dropped.
 */
export function ascii(text: string): string {
  return text
    .replace(/[×✕]/g, 'x')
    .replace(/[−–—]/g, '-')
    .replace(/[‘’]/g, "'")
    .replace(/[“”]/g, '"')
    .replace(/…/g, '...')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^\x20-\x7e\n]/g, '?');
}

/** ESC/POS commands and text, collected into the bytes a printer takes. */
export class EscPos {
  private bytes: number[] = [];

  constructor(readonly columns: number) {
    this.raw(ESC, 0x40); // initialise
  }

  raw(...values: number[]) {
    this.bytes.push(...values);
    return this;
  }

  text(value: string) {
    for (const char of ascii(value)) this.bytes.push(char.charCodeAt(0));
    return this;
  }

  line(value = '') {
    return this.text(value).raw(0x0a);
  }

  align(where: 'left' | 'center' | 'right') {
    return this.raw(ESC, 0x61, where === 'left' ? 0 : where === 'center' ? 1 : 2);
  }

  bold(on: boolean) {
    return this.raw(ESC, 0x45, on ? 1 : 0);
  }

  /** Double width and height, for the total. */
  large(on: boolean) {
    return this.raw(GS, 0x21, on ? 0x11 : 0x00);
  }

  rule() {
    return this.line('-'.repeat(this.columns));
  }

  /** Text on the left, an amount on the right; a long left side wraps above. */
  pair(left: string, right: string, columns = this.columns) {
    const l = ascii(left);
    const r = ascii(right);
    if (l.length + r.length + 1 > columns) {
      for (const part of wrap(l, columns)) this.line(part);
      return this.line(r.padStart(columns));
    }
    return this.line(l + ' '.repeat(columns - l.length - r.length) + r);
  }

  wrapped(value: string) {
    for (const part of wrap(ascii(value), this.columns)) this.line(part);
    return this;
  }

  feed(lines: number) {
    return this.raw(ESC, 0x64, lines);
  }

  /** Feeds past the cutter and cuts (printers without one ignore it). */
  cut() {
    return this.raw(GS, 0x56, 0x42, 0x00);
  }

  /** A pulse on the drawer port: opens a cash drawer wired to the printer. */
  kick() {
    return this.raw(ESC, 0x70, 0x00, 0x19, 0xfa);
  }

  build() {
    return Uint8Array.from(this.bytes);
  }
}

function wrap(text: string, columns: number): string[] {
  const out: string[] = [];
  for (const paragraph of text.split('\n')) {
    let current = '';
    for (const word of paragraph.split(/\s+/).filter(Boolean)) {
      if (word.length > columns) {
        if (current) out.push(current);
        for (let i = 0; i < word.length; i += columns) out.push(word.slice(i, i + columns));
        current = '';
      } else if (!current) {
        current = word;
      } else if (current.length + 1 + word.length <= columns) {
        current += ` ${word}`;
      } else {
        out.push(current);
        current = word;
      }
    }
    out.push(current);
  }
  return out;
}

/**
 * The branch's receipt as ESC/POS: what the HTML receipt shows, laid out in
 * the paper's columns. With openDrawer, the cash drawer opens first.
 */
export function receiptEscPos(sale: Sale, settings: ReceiptSettings, copy: number | null, openDrawer = false): Uint8Array {
  const p = new EscPos(columnsFor(settings.paper_size));
  if (openDrawer) p.kick();

  p.align('center');
  if (settings.business_name) p.bold(true).wrapped(settings.business_name).bold(false);
  if (settings.address) p.wrapped(settings.address);
  if (settings.contact) p.wrapped(settings.contact);
  if (settings.header) p.wrapped(settings.header);
  p.align('left').rule();

  if (copy !== null && copy > 1) p.align('center').bold(true).line(`COPY ${copy}`).bold(false).align('left');
  if (sale.status === 'voided') p.align('center').bold(true).line('VOIDED').bold(false).align('left');
  p.pair(sale.invoice ? 'Invoice' : 'Receipt', sale.number).pair('Date', dateTime(sale.sold_at));
  if (settings.show_staff) p.pair('Served by', sale.sold_by);
  if (settings.show_customer && sale.customer_name) p.pair('Customer', sale.customer_name);
  p.rule();

  for (const l of sale.receipt_lines) {
    p.wrapped(l.pack ? `${l.name} (${l.pack})` : l.name);
    p.pair(`  ${l.quantity} x ${money(l.unit_price_minor, false)}`, money(l.line_total_minor, false));
    if (l.discount_minor) p.pair('  Discount', `-${money(l.discount_minor, false)}`);
  }
  p.rule();

  if (sale.discount_minor) p.pair('Discount', `-${money(sale.discount_minor)}`);
  // Double width takes half the columns; a total too long for that stays normal size.
  const total = money(sale.total_minor);
  const half = Math.floor(p.columns / 2);
  if ('TOTAL'.length + 1 + total.length <= half) p.bold(true).large(true).pair('TOTAL', total, half).large(false).bold(false);
  else p.bold(true).pair('TOTAL', total).bold(false);
  p.rule();

  p.pair('Paid by', sale.payment_method_label);
  if (sale.amount_tendered_minor != null) p.pair('Cash given', money(sale.amount_tendered_minor));
  if (sale.change_minor != null) p.pair('Change', money(sale.change_minor));
  if (sale.payment_reference) p.pair('Reference', sale.payment_reference);
  if (sale.due_on) p.pair('Due', sale.due_on);

  if (settings.footer) p.rule().align('center').wrapped(settings.footer).align('left');

  return p.feed(4).cut().build();
}

/** A short page to check the printer and the paper width. */
export function testPage(paper: ReceiptSettings['paper_size'], printerName: string): Uint8Array {
  const p = new EscPos(columnsFor(paper));
  p.align('center').bold(true).line('Miles Retail').bold(false).line('Printer test').align('left').rule();
  p.pair('Printer', printerName).pair('Paper', `${paper ?? '80mm'}, ${p.columns} columns`).pair('Printed', dateTime(new Date().toISOString()));
  p.rule().line('1234567890'.repeat(Math.ceil(p.columns / 10)).slice(0, p.columns));
  return p.feed(4).cut().build();
}

/** Only the pulse that opens the cash drawer. */
export function drawerKick(): Uint8Array {
  return new EscPos(0).kick().build();
}
