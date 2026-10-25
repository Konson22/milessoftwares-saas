import { dateTime, money } from './format';

/**
 * A bill on paper (80 mm roll or A4), through the system print dialog
 * (window.desktop.print): before payment, for the table to check, or a
 * paid bill again.
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
    ${bill.branch ? `<p class="c">${escape(bill.branch)}</p>` : ''}
    <p class="c"><strong>${escape(bill.title)}</strong></p>
    ${bill.details.map((d) => `<p class="c">${escape(d)}</p>`).join('')}
    <table>${rows}</table>
    <div class="total"><span>Total</span><span>${escape(money(bill.totalMinor))}</span></div>
    ${bill.footer.map((f) => `<p>${escape(f)}</p>`).join('')}
    <p class="c" style="margin-top:10px">Printed ${escape(dateTime(new Date().toISOString()))}</p>
  </body></html>`;
}
