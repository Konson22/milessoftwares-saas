import { toPrintableText } from './text-layout';
import type { PrintAlign, PrintDocument, PrintLayout } from './types';

/**
 * A print document as raw ESC/POS bytes, for Bluetooth receipt printers.
 *
 * Only the plainest commands, the ones every ESC/POS printer takes: initialise, align, bold,
 * double size, line feed. No paper cut (printers without a cutter print it as junk), and no
 * line-spacing command (ESC 2: some built-in printers drop the ESC and print a stray "2").
 * Text is printable ASCII already (toPrintableText), so one byte is one column.
 */

const ESC = 0x1b;
const GS = 0x1d;
const LF = 0x0a;

const INIT = [ESC, 0x40];
const ALIGN: Record<PrintAlign, number[]> = { left: [ESC, 0x61, 0], center: [ESC, 0x61, 1], right: [ESC, 0x61, 2] };
const BOLD = (on: boolean) => [ESC, 0x45, on ? 1 : 0];
const SIZE = (large: boolean) => [GS, 0x21, large ? 0x11 : 0x00];

export function toEscPos(document: PrintDocument, layout: Pick<PrintLayout, 'feedLinesAfter'>): Uint8Array {
  const out: number[] = [...INIT];
  for (const line of document.lines) {
    switch (line.kind) {
      case 'text': {
        out.push(...ALIGN[line.align ?? 'left'], ...BOLD(line.bold ?? false), ...SIZE(line.size === 'large'));
        for (const ch of toPrintableText(line.text)) out.push(ch.charCodeAt(0));
        out.push(LF);
        break;
      }
      case 'feed':
        for (let i = 0; i < line.lines; i++) out.push(LF);
        break;
      // QR codes and images are not on the restaurant's receipts; a plain printer skips them.
      case 'qr':
      case 'image':
        break;
    }
  }
  out.push(...ALIGN.left, ...BOLD(false), ...SIZE(false));
  for (let i = 0; i < Math.max(1, layout.feedLinesAfter); i++) out.push(LF);
  return Uint8Array.from(out);
}

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

/** Bytes as base64, for the native call (no Buffer in React Native). */
export function toBase64(bytes: Uint8Array): string {
  let out = '';
  for (let i = 0; i < bytes.length; i += 3) {
    const a = bytes[i];
    const b = i + 1 < bytes.length ? bytes[i + 1] : 0;
    const c = i + 2 < bytes.length ? bytes[i + 2] : 0;
    const n = (a << 16) | (b << 8) | c;
    out += ALPHABET[(n >> 18) & 63] + ALPHABET[(n >> 12) & 63];
    out += i + 1 < bytes.length ? ALPHABET[(n >> 6) & 63] : '=';
    out += i + 2 < bytes.length ? ALPHABET[n & 63] : '=';
  }
  return out;
}

/**
 * How long the printer needs after the bytes are handed over: it is still feeding paper, and
 * a next job written on top of it prints garbled. About 1.3 s for a receipt (the timing that
 * works on the H10S terminal), more for a long one.
 */
export const settleMs = (bytes: Uint8Array) => Math.min(4000, 900 + Math.round(bytes.length / 4));
