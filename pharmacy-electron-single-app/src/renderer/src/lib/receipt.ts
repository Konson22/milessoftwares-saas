
import { dateTime, money } from './format';
import type { Counter, Sale } from './types';

type ReceiptSettings = Counter['receipt'];

const esc = (s: string | null | undefined) =>
  (s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

/** The pharmacy's receipt, as printed at the counter. */
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

export function printReceipt(sale: Sale, settings: ReceiptSettings, copy: number | null = null, note: string | null = null) {
  return window.desktop.print(receiptHtml(sale, settings, copy, note));
}
