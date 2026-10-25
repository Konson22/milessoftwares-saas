import * as Print from 'expo-print';
import * as Sharing from 'expo-sharing';
import { Platform, Share } from 'react-native';

import { usePrinter } from '@/store/printer';

import { drawerKick, receiptEscPos } from './escpos';
import { dateTime, money } from './format';
import { bluetoothPrinting, sendToPrinter } from './printer';
import type { Counter, Sale } from './types';

type ReceiptSettings = Counter['receipt'];

const esc = (s: string | null | undefined) =>
  (s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

/** The branch's receipt, as the web counter prints it, for the print dialog and a shared PDF. */
export function receiptHtml(sale: Sale, settings: ReceiptSettings, copy: number | null = null): string {
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
  body { font-family: -apple-system, Roboto, Helvetica, sans-serif; font-size: 12px; color: #000; width: ${width - 8}mm; margin: 0 auto; }
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
  ${settings.footer ? `<hr><div class="c">${esc(settings.footer)}</div>` : ''}
</body></html>`;
}

export function receiptText(sale: Sale, settings: ReceiptSettings): string {
  const out = [settings.business_name, settings.address, `Receipt ${sale.number}`, dateTime(sale.sold_at)].filter(Boolean) as string[];
  if (sale.status === 'voided') out.push('VOIDED');
  if (settings.show_staff) out.push(`Served by: ${sale.sold_by}`);
  if (settings.show_customer && sale.customer_name) out.push(`Customer: ${sale.customer_name}`);
  if (sale.prescription) out.push(`Prescription: ${sale.prescription.number}`);
  out.push('');
  for (const l of sale.receipt_lines) out.push(`${l.quantity} x ${l.name}${l.pack ? ` (${l.pack})` : ''} — ${money(l.line_total_minor)}`);
  out.push('');
  if (sale.discount_minor) out.push(`Discount: -${money(sale.discount_minor)}`);
  out.push(`TOTAL: ${money(sale.total_minor)}`, `Paid by ${sale.payment_method_label}`);
  if (sale.change_minor != null) out.push(`Change: ${money(sale.change_minor)}`);
  if (sale.payment_reference) out.push(`Ref: ${sale.payment_reference}`);
  if (settings.footer) out.push('', settings.footer);
  return out.join('\n');
}

/**
 * Prints to the device's Bluetooth receipt printer (ESC/POS) when one is
 * chosen and the build can, else through the OS print service (AirPrint /
 * the Android print service).
 */
export async function printReceipt(sale: Sale, settings: ReceiptSettings, copy: number | null) {
  const { printer } = usePrinter.getState();
  if (printer && bluetoothPrinting) {
    await sendToPrinter(printer.address, receiptEscPos(sale, settings, copy));
    return;
  }
  await Print.printAsync({ html: receiptHtml(sale, settings, copy) });
}

/**
 * After a cash sale: opens the drawer wired to the Bluetooth printer, when
 * this device is set to. Never in the way of the sale: false if it could not.
 */
export async function openCashDrawer(): Promise<boolean> {
  const { printer, openDrawer } = usePrinter.getState();
  if (!printer || !openDrawer || !bluetoothPrinting) return false;
  try {
    await sendToPrinter(printer.address, drawerKick());
    return true;
  } catch {
    return false;
  }
}

export async function shareReceiptPdf(sale: Sale, settings: ReceiptSettings, copy: number | null) {
  if (Platform.OS === 'web') return printReceipt(sale, settings, copy);
  const { uri } = await Print.printToFileAsync({ html: receiptHtml(sale, settings, copy), width: settings.paper_size === '58mm' ? 164 : 226 });
  await Sharing.shareAsync(uri, { mimeType: 'application/pdf', UTI: 'com.adobe.pdf', dialogTitle: sale.number });
}

export async function shareReceiptText(sale: Sale, settings: ReceiptSettings) {
  await Share.share({ message: receiptText(sale, settings), title: sale.number });
}
