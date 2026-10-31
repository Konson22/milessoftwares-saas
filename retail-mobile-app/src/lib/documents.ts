import * as Print from 'expo-print';
import * as Sharing from 'expo-sharing';
import { Platform } from 'react-native';

import { dateTime, fullDate, money } from './format';
import type { Counter, Sale } from './types';

/**
 * A4 documents for customers, printed or shared as a PDF: a quotation, an
 * invoice and an account statement. The counter's own receipt is
 * lib/receipt.ts.
 */

type Shop = Counter['receipt'];

const esc = (s: string | null | undefined) =>
  (s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

export interface DocumentLine {
  name: string;
  /** "Box of 12", "10% off". */
  detail?: string | null;
  quantity: number;
  unitPriceMinor: number;
  totalMinor: number;
}

export interface CustomerDocument {
  title: 'Quotation' | 'Invoice';
  number: string;
  /** Label and value: the date, valid until or due on, the reference. */
  facts: [string, string][];
  to: { name: string; number?: string | null; phone?: string | null; email?: string | null; address?: string | null };
  lines: DocumentLine[];
  /** Label and amount, the last one the total. */
  totals: [string, number][];
  note?: string | null;
  /** Below everything: terms, or what was paid. */
  closing?: string | null;
}

const STYLE = `
  @page { size: A4; margin: 16mm; }
  body { font-family: -apple-system, Roboto, Helvetica, Arial, sans-serif; font-size: 11pt; color: #0f172a; margin: 0; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
  .shop h1 { font-size: 18pt; margin: 0 0 4px; color: #0a4bb4; }
  .shop div, .muted { color: #475569; }
  .title { text-align: right; }
  .title h2 { font-size: 22pt; margin: 0; letter-spacing: 1px; text-transform: uppercase; }
  .facts td { padding: 1px 0 1px 12px; text-align: right; }
  .to { margin: 24px 0 16px; padding: 12px 14px; background: #f1f5f9; }
  .to b { font-size: 12pt; }
  table.lines { width: 100%; border-collapse: collapse; margin-top: 8px; }
  table.lines th { text-align: left; font-size: 9pt; text-transform: uppercase; color: #475569; border-bottom: 2px solid #0f172a; padding: 6px 4px; }
  table.lines td { border-bottom: 1px solid #e2e8f0; padding: 7px 4px; vertical-align: top; }
  .r { text-align: right; white-space: nowrap; }
  .detail { color: #64748b; font-size: 9pt; }
  table.totals { margin: 12px 0 0 auto; min-width: 45%; border-collapse: collapse; }
  table.totals td { padding: 4px; }
  table.totals tr.total td { font-size: 14pt; font-weight: bold; border-top: 2px solid #0f172a; }
  .note { margin-top: 20px; padding: 10px 14px; border-left: 3px solid #0a4bb4; background: #f8fafc; }
  .closing { margin-top: 28px; color: #475569; }
  .void { color: #b91c1c; font-weight: bold; }
`;

function shopBlock(shop: Shop) {
  return `<div class="shop">
    <h1>${esc(shop.business_name)}</h1>
    ${shop.address ? `<div>${esc(shop.address)}</div>` : ''}
    ${shop.contact ? `<div>${esc(shop.contact)}</div>` : ''}
  </div>`;
}

function toBlock(to: CustomerDocument['to']) {
  const more = [to.number, to.phone, to.email].filter(Boolean).map(esc).join(' · ');
  return `<div class="to"><div class="muted">To</div><b>${esc(to.name)}</b>
    ${more ? `<div>${more}</div>` : ''}${to.address ? `<div>${esc(to.address)}</div>` : ''}</div>`;
}

export function documentHtml(shop: Shop, doc: CustomerDocument): string {
  const facts = doc.facts.map(([label, value]) => `<tr><td class="muted">${esc(label)}</td><td><b>${esc(value)}</b></td></tr>`).join('');
  const lines = doc.lines
    .map(
      (l, i) => `<tr>
        <td>${i + 1}</td>
        <td>${esc(l.name)}${l.detail ? `<div class="detail">${esc(l.detail)}</div>` : ''}</td>
        <td class="r">${l.quantity}</td>
        <td class="r">${money(l.unitPriceMinor, false)}</td>
        <td class="r">${money(l.totalMinor, false)}</td>
      </tr>`,
    )
    .join('');
  const totals = doc.totals
    .map(([label, amount], i) => `<tr class="${i === doc.totals.length - 1 ? 'total' : ''}"><td>${esc(label)}</td><td class="r">${money(amount)}</td></tr>`)
    .join('');
  return `<!doctype html><html><head><meta charset="utf-8"><style>${STYLE}</style></head><body>
    <div class="top">${shopBlock(shop)}
      <div class="title"><h2>${esc(doc.title)}</h2><table class="facts"><tr><td class="muted">Number</td><td><b>${esc(doc.number)}</b></td></tr>${facts}</table></div>
    </div>
    ${toBlock(doc.to)}
    <table class="lines">
      <thead><tr><th>#</th><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Amount</th></tr></thead>
      <tbody>${lines}</tbody>
    </table>
    <table class="totals">${totals}</table>
    ${doc.note ? `<div class="note">${esc(doc.note)}</div>` : ''}
    ${doc.closing ? `<div class="closing">${esc(doc.closing)}</div>` : ''}
    ${shop.footer ? `<div class="closing">${esc(shop.footer)}</div>` : ''}
  </body></html>`;
}

/** A sale on account as an A4 invoice. */
export function invoiceDocument(sale: Sale): CustomerDocument {
  const returned = sale.returns.reduce((sum, r) => sum + r.total_minor, 0);
  // Each line is already less its discount.
  const totals: [string, number][] = [];
  if (returned) totals.push(['Total', sale.total_minor], ['Returned', -returned]);
  totals.push([returned ? 'Due' : 'Total due', sale.total_minor - returned]);
  return {
    title: 'Invoice',
    number: sale.number,
    facts: [
      ['Date', fullDate(sale.sold_at.slice(0, 10))],
      ...(sale.due_on ? ([['Due', fullDate(sale.due_on)]] as [string, string][]) : []),
      ...(sale.payment_reference ? ([['Quotation', sale.payment_reference]] as [string, string][]) : []),
    ],
    to: { name: sale.customer?.name ?? sale.customer_name ?? '', number: sale.customer?.number, phone: sale.customer_phone },
    lines: sale.receipt_lines.map((l) => ({
      name: l.name,
      detail: [l.pack, l.discount_minor ? `less ${money(l.discount_minor)}` : null].filter(Boolean).join(' · ') || null,
      quantity: l.quantity,
      unitPriceMinor: l.unit_price_minor,
      totalMinor: l.line_total_minor,
    })),
    totals,
    note: sale.status === 'voided' ? `VOIDED: ${sale.void_reason ?? ''}` : null,
    closing: `On account. Served by ${sale.sold_by}, ${dateTime(sale.sold_at)}.`,
  };
}

export interface Statement {
  customer: { name: string; number: string; phone: string | null; email: string | null; address: string | null };
  balanceMinor: number;
  /** Oldest first. */
  entries: { date: string; what: string; chargeMinor: number; paymentMinor: number }[];
}

export function statementHtml(shop: Shop, st: Statement): string {
  let running = 0;
  const rows = st.entries
    .map((e) => {
      running += e.chargeMinor - e.paymentMinor;
      return `<tr><td>${esc(fullDate(e.date.slice(0, 10)))}</td><td>${esc(e.what)}</td>
        <td class="r">${e.chargeMinor ? money(e.chargeMinor, false) : ''}</td>
        <td class="r">${e.paymentMinor ? money(e.paymentMinor, false) : ''}</td>
        <td class="r">${money(running, false)}</td></tr>`;
    })
    .join('');
  return `<!doctype html><html><head><meta charset="utf-8"><style>${STYLE}</style></head><body>
    <div class="top">${shopBlock(shop)}
      <div class="title"><h2>Statement</h2><table class="facts"><tr><td class="muted">Date</td><td><b>${esc(fullDate(new Date().toISOString().slice(0, 10)))}</b></td></tr></table></div>
    </div>
    ${toBlock(st.customer)}
    <table class="lines">
      <thead><tr><th>Date</th><th>Item</th><th class="r">Charged</th><th class="r">Paid</th><th class="r">Balance</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="5" class="muted">Nothing on the account.</td></tr>'}</tbody>
    </table>
    <table class="totals"><tr class="total"><td>${st.balanceMinor > 0 ? 'Owed' : 'Balance'}</td><td class="r">${money(st.balanceMinor)}</td></tr></table>
    ${shop.footer ? `<div class="closing">${esc(shop.footer)}</div>` : ''}
  </body></html>`;
}

export async function printHtml(html: string) {
  await Print.printAsync({ html });
}

/** As a PDF to send: by chat, email, or saved. */
export async function shareHtml(html: string, name: string) {
  if (Platform.OS === 'web') return printHtml(html);
  const { uri } = await Print.printToFileAsync({ html });
  await Sharing.shareAsync(uri, { mimeType: 'application/pdf', UTI: 'com.adobe.pdf', dialogTitle: name });
}
