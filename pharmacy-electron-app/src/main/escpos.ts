import { RECEIPT_COLUMNS, type PrintLine } from '../shared/ipc';

/**
 * Receipts for a thermal printer spoken to directly (ESC/POS, the language
 * nearly every receipt printer understands), with no driver: a network
 * printer on port 9100. Text only, in the printer's own font.
 */

const ESC = 0x1b;
const GS = 0x1d;
const DLE = 0x10;
const EOT = 0x04;

export const COLUMNS = RECEIPT_COLUMNS;

const REPLACE: Record<string, string> = {
  '×': 'x',
  '’': "'",
  '‘': "'",
  '“': '"',
  '”': '"',
  '·': '-',
  '–': '-',
  '—': '-',
  '…': '...',
  '≈': '~',
  '•': '*',
};

/** As plain ASCII: accents dropped, typographic marks made plain, anything else a "?". */
export function ascii(text: string): string {
  return text
    .replace(/[×’‘“”·–—…≈•]/g, (c) => REPLACE[c])
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^\x20-\x7e]/g, '?');
}

/** The receipt as bytes: each line as it is laid out, then paper fed out (and cut). */
export function escpos(lines: PrintLine[], options: { cut: boolean }): Buffer {
  const out: number[] = [ESC, 0x40]; // Initialise.
  for (const line of lines) {
    out.push(ESC, 0x61, line.align === 'center' ? 1 : 0);
    out.push(ESC, 0x45, line.bold ? 1 : 0);
    out.push(GS, 0x21, line.big ? 0x01 : 0x00); // Twice the height, the same width: the line still fits.
    out.push(...Buffer.from(ascii(line.text), 'ascii'), 0x0a);
  }
  out.push(ESC, 0x61, 0, ESC, 0x45, 0, GS, 0x21, 0);
  out.push(ESC, 0x64, 4); // Feed past the cutter.
  if (options.cut) out.push(GS, 0x56, 0x42, 0x00);
  return Buffer.from(out);
}

/** Asks the paper sensor (DLE EOT 4); the printer answers one byte. */
export const PAPER_STATUS = Buffer.from([DLE, EOT, 4]);

export type Paper = 'ok' | 'low' | 'out';

export function paperFrom(reply: number): Paper {
  if (reply & 0x60) return 'out';
  if (reply & 0x0c) return 'low';
  return 'ok';
}
