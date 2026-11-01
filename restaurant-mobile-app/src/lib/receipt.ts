import * as Print from 'expo-print';

import { dateTime, money, time } from './format';
import { TerminalDocument } from './terminal-printer/builder';
import { printOnTerminal, printsOnTerminal } from './terminal-printer/service';
import type { PrintDocument } from './terminal-printer/types';

/**
 * A bill on paper: on the terminal's own printer (a SUNMI POS device), or
 * an 80 mm roll or A4 through the system print dialog:
 * before payment, for the table to check, or a paid bill again.
 */

export interface PrintedBill {
  business: string;
  branch: string | null;
  title: string;
  /** Under the title: the order or bill number, the table, the time. */
  details: string[];
  lines: { name: string; quantity: number; unitPriceMinor: number; totalMinor: number }[];
  totalMinor: number;
  /** Below the total: paid by, change… */
  footer: string[];
}

const escape = (text: string) => text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);

export function billHtml(bill: PrintedBill): string {
  const rows = bill.lines
    .map(
      (line) =>
        `<tr><td>${line.quantity} × ${escape(line.name)}<br><small>${escape(money(line.unitPriceMinor, false))} each</small></td><td class="r">${escape(money(line.totalMinor, false))}</td></tr>`,
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
    <div class="total"><span>Total</span><span>${escape(money(bill.totalMinor))}</span></div>
    ${bill.footer.map((f) => `<p>${escape(f)}</p>`).join('')}
    <p class="c" style="margin-top:10px">Printed ${escape(dateTime(new Date().toISOString()))}</p>
  </body></html>`;
}

/** The bill for the terminal's printer, in its paper's columns. */
export function billDocument(bill: PrintedBill): PrintDocument {
  const d = new TerminalDocument();
  d.text(bill.business, { align: 'center', bold: true });
  if (bill.branch && bill.branch !== bill.business) d.text(bill.branch, { align: 'center' });
  d.text(bill.title, { align: 'center', bold: true });
  for (const detail of bill.details) d.text(detail, { align: 'center' });
  d.rule();
  for (const line of bill.lines) {
    d.pair(`${line.quantity} x ${line.name}`, money(line.totalMinor, false));
    d.text(`  ${money(line.unitPriceMinor, false)} each`);
  }
  d.rule();
  d.pair('Total', money(bill.totalMinor), { bold: true });
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

/** A paid bill as the bill page has it. */
export interface PaidBill {
  number: string;
  status: 'paid' | 'voided';
  paidAt: string;
  paidBy: string;
  order: { number: string; table: string | null };
  customerName: string | null;
  totalMinor: number;
  paymentMethod: string;
  paymentReference: string | null;
  tenderedMinor: number | null;
  changeMinor: number | null;
  lines: { name: string; quantity: number; unitPriceMinor: number; totalMinor: number }[];
}

/** The customer's receipt for a paid bill. */
export function printPaidBill(bill: PaidBill, business: Business): Promise<void> {
  const where = bill.order.table ? `Table ${bill.order.table}` : (bill.customerName ?? 'Takeaway');
  return printBill({
    business: business.name,
    branch: business.branch,
    title: `Bill ${bill.number}${bill.status === 'voided' ? ' (voided)' : ''}`,
    details: [`${where} · ${bill.order.number}`, dateTime(bill.paidAt)],
    lines: bill.lines,
    totalMinor: bill.totalMinor,
    footer: [
      `Paid by ${bill.paymentMethod}${bill.paymentReference ? ` (${bill.paymentReference})` : ''}`,
      ...(bill.tenderedMinor !== null ? [`Given ${money(bill.tenderedMinor)} · change ${money(bill.changeMinor ?? 0)}`] : []),
      `Served by ${bill.paidBy}.`,
      business.footer ?? 'Thank you!',
    ],
  });
}

/** A kitchen ticket on paper: where it goes, what to cook, no prices. */
export interface KitchenTicket {
  number: string;
  where: string;
  orderNumber: string;
  sentAt: string;
  sentBy: string;
  lines: { name: string; quantity: number; note: string | null; voided: boolean }[];
}

export function kitchenTicketHtml(ticket: KitchenTicket): string {
  const rows = ticket.lines
    .map(
      (line) =>
        `<div class="line${line.voided ? ' void' : ''}"><b>${line.quantity} ×</b> ${escape(line.name)}${line.voided ? ' (VOID)' : ''}${line.note ? `<div class="note">» ${escape(line.note)}</div>` : ''}</div>`,
    )
    .join('');

  return `<!doctype html><html><head><meta charset="utf-8"><title>${escape(ticket.number)}</title><style>
    body { font-family: system-ui, sans-serif; font-size: 16px; width: 72mm; margin: 0 auto; padding: 4mm 0; color: #000; }
    h1 { font-size: 24px; text-align: center; margin: 0 0 4px; }
    p { margin: 2px 0; text-align: center; }
    .lines { border-top: 2px solid #000; border-bottom: 2px solid #000; margin: 8px 0; padding: 4px 0; }
    .line { font-size: 19px; padding: 5px 0; border-bottom: 1px dashed #999; } .line:last-child { border-bottom: 0; }
    .void { text-decoration: line-through; color: #555; }
    .note { font-size: 16px; font-weight: 700; padding-left: 8mm; }
  </style></head><body>
    <h1>${escape(ticket.where)}</h1>
    <p><strong>${escape(ticket.number)}</strong> · ${escape(ticket.orderNumber)}</p>
    <p>${escape(time(ticket.sentAt))} · ${escape(ticket.sentBy)}</p>
    <div class="lines">${rows}</div>
  </body></html>`;
}

/** The kitchen ticket for the terminal's printer: large where it goes, then what to cook. */
export function kitchenTicketDocument(ticket: KitchenTicket): PrintDocument {
  const d = new TerminalDocument();
  d.text(ticket.where, { align: 'center', bold: true, large: true });
  d.text(`${ticket.number} · ${ticket.orderNumber}`, { align: 'center', bold: true });
  d.text(`${time(ticket.sentAt)} · ${ticket.sentBy}`, { align: 'center' });
  d.rule('=');
  for (const line of ticket.lines) {
    d.text(`${line.quantity} x ${line.name}${line.voided ? ' (VOID)' : ''}`, { bold: !line.voided, large: !line.voided });
    if (line.note) d.text(`  >> ${line.note}`, { bold: true });
  }
  d.rule('=');
  return d.build();
}

export async function printKitchenTicket(ticket: KitchenTicket): Promise<void> {
  if (printsOnTerminal()) return printOnTerminal(kitchenTicketDocument(ticket), 'ticket');
  await Print.printAsync({ html: kitchenTicketHtml(ticket) });
}
