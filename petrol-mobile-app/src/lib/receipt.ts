import * as Print from 'expo-print';

import { dateTime, litres, moneyIn } from './format';
import { TerminalDocument } from './terminal-printer/builder';
import { printOnTerminal, printsOnTerminal } from './terminal-printer/service';
import type { PrintDocument } from './terminal-printer/types';

/**
 * A receipt on paper: on the terminal's own printer (a SUNMI POS device), or
 * an 80 mm roll or A4 through the system print dialog.
 */

export interface PrintedBill {
  business: string;
  branch: string | null;
  title: string;
  /** Under the title: the receipt number, the pump, the time. */
  details: string[];
  lines: { name: string; quantity: string; unitPriceMinor: number; totalMinor: number }[];
  totalMinor: number;
  /** The currency the amounts are in; the books' when null. */
  currency: string | null;
  /** Below the total: how it was paid, the attendant… */
  footer: string[];
}

/** An amount without its code (the total carries it). */
const amount = (bill: PrintedBill, minor: number) => moneyIn(minor, bill.currency).replace(/^[A-Z]{3} /, '');

const escape = (text: string) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

export function billHtml(bill: PrintedBill): string {
  const rows = bill.lines
    .map(
      (line) =>
        `<tr><td>${escape(line.quantity)} ${escape(line.name)}<br><small>${escape(amount(bill, line.unitPriceMinor))} per litre</small></td><td class="r">${escape(amount(bill, line.totalMinor))}</td></tr>`,
    )
    .join('');

  return `<!doctype html><html><head><meta charset="utf-8"><title>${escape(bill.title)}</title><style>
    body { font-family: system-ui, sans-serif; font-size: 12px; width: 72mm; margin: 0 auto; padding: 4mm 0; color: #000; }
    h1 { font-size: 15px; text-align: center; margin: 0; }
    .c { text-align: center; } .r { text-align: right; white-space: nowrap; vertical-align: top; }
    table { width: 100%; border-collapse: collapse; margin: 8px 0; } td { padding: 3px 0; border-bottom: 1px dashed #999; }
    .total { font-size: 15px; font-weight: 700; display: flex; justify-content: space-between; margin-top: 6px; }
    small { color: #444; } p { margin: 2px 0; }
  </style></head><body>
    <h1>${escape(bill.business)}</h1>
    ${bill.branch && bill.branch !== bill.business ? `<p class="c">${escape(bill.branch)}</p>` : ''}
    <p class="c"><strong>${escape(bill.title)}</strong></p>
    ${bill.details.map((d) => `<p class="c">${escape(d)}</p>`).join('')}
    <table>${rows}</table>
    <div class="total"><span>Total</span><span>${escape(moneyIn(bill.totalMinor, bill.currency))}</span></div>
    ${bill.footer.map((f) => `<p>${escape(f)}</p>`).join('')}
    <p class="c" style="margin-top:10px">Printed ${escape(dateTime(new Date().toISOString()))}</p>
  </body></html>`;
}

/** The receipt for the terminal's printer, in its paper's columns. */
export function billDocument(bill: PrintedBill): PrintDocument {
  const d = new TerminalDocument();
  d.text(bill.business, { align: 'center', bold: true });
  if (bill.branch && bill.branch !== bill.business) d.text(bill.branch, { align: 'center' });
  d.text(bill.title, { align: 'center', bold: true });
  for (const detail of bill.details) d.text(detail, { align: 'center' });
  d.rule();
  for (const line of bill.lines) {
    d.pair(`${line.quantity} ${line.name}`, amount(bill, line.totalMinor));
    d.text(`  ${amount(bill, line.unitPriceMinor)} per litre`);
  }
  d.rule();
  d.pair('Total', moneyIn(bill.totalMinor, bill.currency), { bold: true });
  d.rule();
  for (const footer of bill.footer) d.text(footer);
  d.blank().text(`Printed ${dateTime(new Date().toISOString())}`, { align: 'center' });
  return d.build();
}

/** The terminal's printer, or the system print dialog (a Wi-Fi or Bluetooth printer the phone knows, or a PDF). */
export async function printBill(bill: PrintedBill): Promise<void> {
  if (printsOnTerminal()) return printOnTerminal(billDocument(bill), 'receipt');
  await Print.printAsync({ html: billHtml(bill) });
}

/** Who a printed paper is from (see useBusiness). */
export interface Business {
  name: string;
  branch: string | null;
  footer: string | null;
}

/** A fuel sale as the sale page has it. */
export interface FuelSaleReceipt {
  number: string;
  status: string;
  product: string;
  nozzle: string;
  attendant: string;
  litres: string;
  priceMinor: number;
  totalMinor: number;
  currency: string;
  soldAt: string;
  payments: { method: string; amountMinor: number; reference: string | null }[];
}

/** The customer's receipt for a fuel sale. */
export function printFuelSale(sale: FuelSaleReceipt, business: Business): Promise<void> {
  return printBill({
    business: business.name,
    branch: business.branch,
    title: `Receipt ${sale.number}${sale.status === 'voided' ? ' (voided)' : ''}`,
    details: [`Pump ${sale.nozzle}`, dateTime(sale.soldAt)],
    lines: [{ name: sale.product, quantity: litres(sale.litres), unitPriceMinor: sale.priceMinor, totalMinor: sale.totalMinor }],
    totalMinor: sale.totalMinor,
    currency: sale.currency,
    footer: [
      ...sale.payments.map((p) => `Paid ${moneyIn(p.amountMinor, sale.currency)} by ${p.method.replace(/_/g, ' ')}${p.reference ? ` (${p.reference})` : ''}`),
      `Served by ${sale.attendant}.`,
      business.footer ?? 'Thank you!',
    ],
  });
}
