import type { QueuedSale } from '@/offline/outbox';
import { packOf, linePrice } from '@/store/cart';

import { RECEIPT_COLUMNS, type PrintLine } from '../../../shared/ipc';
import { dateTime, money } from './format';
import type { Counter, Sale } from './types';

type ReceiptSettings = Counter['receipt'];

const esc = (s: string | null | undefined) =>
  (s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

/**
 * The branch's receipt, as the web counter prints it. A sale made offline
 * prints with its local reference and a note: the pharmacy gives it a
 * receipt number when it is sent.
 */
export function receiptHtml(sale: Sale, settings: ReceiptSettings, copy: number | null = null, note: string | null = null): string {
  const width = settings.paper_size === '58mm' ? 58 : 80;
  const lines = sale.receipt_lines
    .map(
      (l) => `
      <tr><td colspan="3">${esc(l.name)}${l.pack ? ` <small>(${esc(l.pack)})</small>` : ''}</td></tr>
      <tr class="sub">
        <td>${l.quantity} × ${money(l.unit_price_minor, false)}</td>
        <td>${l.discount_minor ? `-${money(l.discount_minor, false)}` : ''}</td>
        <td class="r">${money(l.line_total_minor, false)}</td>
      </tr>`,
    )
    .join('');

  return `<!doctype html><html><head><meta charset="utf-8">
<style>
  @page { size: ${width}mm auto; margin: 3mm; }
  body { font-family: 'Segoe UI', Roboto, Helvetica, sans-serif; font-size: 12px; color: #000; width: ${width - 8}mm; margin: 0 auto; }
  h1 { font-size: 15px; margin: 0; text-align: center; }
  .c { text-align: center; } .r { text-align: right; } .sub td { color: #333; font-size: 11px; padding-bottom: 4px; }
  table { width: 100%; border-collapse: collapse; } hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .tot td { font-size: 14px; font-weight: bold; } .void { text-align: center; font-weight: bold; font-size: 14px; }
</style></head><body>
  ${settings.business_name ? `<h1>${esc(settings.business_name)}</h1>` : ''}
  ${settings.address ? `<div class="c">${esc(settings.address)}</div>` : ''}
  ${settings.contact ? `<div class="c">${esc(settings.contact)}</div>` : ''}
  ${settings.header ? `<div class="c">${esc(settings.header)}</div>` : ''}
  <hr>
  ${copy !== null && copy > 1 ? `<div class="c"><b>COPY ${copy}</b></div>` : ''}
  ${sale.status === 'voided' ? '<div class="void">VOIDED</div>' : ''}
  <div>Receipt: <b>${esc(sale.number)}</b></div>
  <div>Date: ${dateTime(sale.sold_at)}</div>
  ${settings.show_staff ? `<div>Served by: ${esc(sale.sold_by)}</div>` : ''}
  ${settings.show_customer && sale.customer_name ? `<div>Customer: ${esc(sale.customer_name)}</div>` : ''}
  ${sale.prescription ? `<div>Prescription: ${esc(sale.prescription.number)}</div>` : ''}
  <hr>
  <table>${lines}</table>
  <hr>
  <table>
    ${sale.discount_minor ? `<tr><td>Discount</td><td class="r">-${money(sale.discount_minor)}</td></tr>` : ''}
    <tr class="tot"><td>TOTAL</td><td class="r">${money(sale.total_minor)}</td></tr>
  </table>
  <hr>
  <table>
    <tr><td>Paid by</td><td class="r">${esc(sale.payment_method_label)}</td></tr>
    ${sale.amount_tendered_minor != null ? `<tr><td>Cash given</td><td class="r">${money(sale.amount_tendered_minor)}</td></tr>` : ''}
    ${sale.change_minor != null ? `<tr><td>Change</td><td class="r">${money(sale.change_minor)}</td></tr>` : ''}
    ${sale.payment_reference ? `<tr><td>Reference</td><td class="r">${esc(sale.payment_reference)}</td></tr>` : ''}
  </table>
  ${note ? `<hr><div class="c"><small>${esc(note)}</small></div>` : ''}
  ${settings.footer ? `<hr><div class="c">${esc(settings.footer)}</div>` : ''}
</body></html>`;
}

/** A sale waiting in the outbox, shaped as a receipt. */
export function queuedSaleAsSale(entry: QueuedSale, currency: string): Sale {
  const tendered = entry.payload.amount_tendered_minor ?? null;
  return {
    id: entry.id,
    number: `OFF-${entry.id.slice(0, 8).toUpperCase()}`,
    status: 'completed',
    sold_at: entry.queued_at,
    sold_by: entry.sold_by,
    customer_name: entry.payload.customer_name ?? null,
    customer_phone: entry.payload.customer_phone ?? null,
    prescription: null,
    currency,
    total_minor: entry.total_minor,
    discount_minor: entry.discount_minor,
    payment_method: entry.payment_method,
    payment_method_label: entry.payment_label,
    payment_reference: entry.payload.payment_reference ?? null,
    amount_tendered_minor: tendered,
    change_minor: tendered != null ? Math.max(0, tendered - entry.total_minor) : null,
    voided_at: null,
    voided_by: null,
    void_reason: null,
    receipt_lines: entry.lines.map((line) => {
      const pack = packOf(line);
      const { gross, discount, total } = linePrice(line);
      return {
        name: [line.item.name, line.item.strength].filter(Boolean).join(' '),
        pack: pack?.name ?? null,
        quantity: line.quantity,
        unit_price_minor: Math.round(gross / line.quantity),
        discount_minor: discount,
        line_total_minor: total,
      };
    }),
    lines: [],
    returns: [],
    can_void: false,
    can_return: false,
  };
}

export const OFFLINE_NOTE = 'Recorded offline. This sale gets its receipt number when the connection is back.';

/** Words wrapped to the width; a word longer than a line is cut. */
function wrap(text: string, width: number): string[] {
  const out: string[] = [];
  let line = '';
  for (const word of text.split(/\s+/).filter(Boolean)) {
    if (!line) line = word;
    else if (line.length + 1 + word.length <= width) line += ` ${word}`;
    else {
      out.push(line);
      line = word;
    }
    while (line.length > width) {
      out.push(line.slice(0, width));
      line = line.slice(width);
    }
  }
  if (line) out.push(line);
  return out;
}

/** Left and right on one line ("TOTAL ....... 1,250.00"); the left is cut to make room. */
const pair = (left: string, right: string, width: number) => {
  const room = Math.max(0, width - right.length - 1);
  const l = left.length > room ? left.slice(0, room) : left;
  return `${l}${' '.repeat(width - l.length - right.length)}${right}`;
};

/** The same receipt as lines of text, for a thermal printer spoken to directly. */
export function receiptLines(sale: Sale, settings: ReceiptSettings, copy: number | null = null, note: string | null = null): PrintLine[] {
  const width = RECEIPT_COLUMNS[settings.paper_size === '58mm' ? '58mm' : '80mm'];
  const rule: PrintLine = { text: '-'.repeat(width) };
  const centered = (text: string | null | undefined, extra: Partial<PrintLine> = {}) =>
    wrap(text ?? '', width).map((t): PrintLine => ({ text: t, align: 'center', ...extra }));
  const out: PrintLine[] = [];

  out.push(...centered(settings.business_name, { bold: true, big: true }), ...centered(settings.address), ...centered(settings.contact), ...centered(settings.header));
  out.push(rule);
  if (copy !== null && copy > 1) out.push({ text: `COPY ${copy}`, align: 'center', bold: true });
  if (sale.status === 'voided') out.push({ text: 'VOIDED', align: 'center', bold: true, big: true });
  out.push({ text: `Receipt: ${sale.number}` }, { text: `Date: ${dateTime(sale.sold_at)}` });
  if (settings.show_staff) out.push({ text: `Served by: ${sale.sold_by}` });
  if (settings.show_customer && sale.customer_name) out.push({ text: `Customer: ${sale.customer_name}` });
  if (sale.prescription) out.push({ text: `Prescription: ${sale.prescription.number}` });
  out.push(rule);

  for (const l of sale.receipt_lines) {
    out.push(...wrap(`${l.name}${l.pack ? ` (${l.pack})` : ''}`, width).map((text) => ({ text })));
    const each = `  ${l.quantity} x ${money(l.unit_price_minor, false)}${l.discount_minor ? ` -${money(l.discount_minor, false)}` : ''}`;
    out.push({ text: pair(each, money(l.line_total_minor, false), width) });
  }
  out.push(rule);
  if (sale.discount_minor) out.push({ text: pair('Discount', `-${money(sale.discount_minor)}`, width) });
  out.push({ text: pair('TOTAL', money(sale.total_minor), width), bold: true, big: true });
  out.push(rule);
  out.push({ text: pair('Paid by', sale.payment_method_label, width) });
  if (sale.amount_tendered_minor != null) out.push({ text: pair('Cash given', money(sale.amount_tendered_minor), width) });
  if (sale.change_minor != null) out.push({ text: pair('Change', money(sale.change_minor), width) });
  if (sale.payment_reference) out.push({ text: pair('Reference', sale.payment_reference, width) });
  if (note) out.push(rule, ...centered(note));
  if (settings.footer) out.push(rule, ...centered(settings.footer));
  return out;
}

export function printReceipt(sale: Sale, settings: ReceiptSettings, copy: number | null = null, note: string | null = null) {
  return window.desktop.print(receiptHtml(sale, settings, copy, note), receiptLines(sale, settings, copy, note));
}
