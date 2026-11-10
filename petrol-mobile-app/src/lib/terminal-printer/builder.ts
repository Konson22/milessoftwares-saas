import { getPrinterSettings } from './settings';
import { divider, leftRight, wrapParagraphs } from './text-layout';
import type { PrintAlign, PrintDocument, PrintLine } from './types';

/**
 * A receipt or ticket for the terminal printer, a line at a time, in the
 * paper's columns (the "characters per line" of the printer settings).
 */
export class TerminalDocument {
  readonly lines: PrintLine[] = [];

  constructor(readonly width = getPrinterSettings().charsPerLine) {}

  /** Text wrapped to the paper; a line of its own for each line it takes. */
  text(value: string, options: { align?: PrintAlign; bold?: boolean; large?: boolean } = {}) {
    // Large type takes about twice the width: wrap to half the columns.
    const width = options.large ? Math.floor(this.width / 2) : this.width;
    for (const text of wrapParagraphs(value, width)) {
      this.lines.push({ kind: 'text', text, align: options.align, bold: options.bold, size: options.large ? 'large' : 'normal' });
    }
    return this;
  }

  /** Text on the left, an amount on the right; a long left side wraps above. */
  pair(left: string, right: string, options: { bold?: boolean; indent?: number } = {}) {
    for (const text of leftRight(left, right, this.width, options.indent ?? 0)) this.lines.push({ kind: 'text', text, bold: options.bold });
    return this;
  }

  rule(char = '-') {
    this.lines.push({ kind: 'text', text: divider(this.width, char) });
    return this;
  }

  blank() {
    this.lines.push({ kind: 'text', text: '' });
    return this;
  }

  build(): PrintDocument {
    return { lines: this.lines };
  }
}
