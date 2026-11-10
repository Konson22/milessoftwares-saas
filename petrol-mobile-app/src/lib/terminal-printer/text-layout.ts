/**
 * Monospace text layout for receipt printers.
 *
 * Everything that reaches the printer goes through `toPrintableText` first, which reduces text to
 * printable ASCII: one character is then exactly one column, so padding and right-alignment are
 * exact, and control characters (ESC/GS…) can never reach the printer as commands.
 */

const REPLACEMENTS: Record<string, string> = {
  '‘': "'",
  '’': "'",
  '‚': "'",
  '“': '"',
  '”': '"',
  '„': '"',
  '–': '-',
  '—': '-',
  '−': '-',
  '…': '...',
  '•': '*',
  '·': '-',
  '×': 'x',
  ' ': ' ',
  ' ': ' ',
  ' ': ' ',
  'ß': 'ss',
  'Æ': 'AE',
  'æ': 'ae',
  'Œ': 'OE',
  'œ': 'oe',
  'Ø': 'O',
  'ø': 'o',
  'Ł': 'L',
  'ł': 'l',
  '€': 'EUR',
  '£': 'GBP',
};

/** Text-safe fallback: transliterates common symbols/accents; anything else unprintable → `?`. */
export function toPrintableText(input: string): string {
  let out = '';
  for (const ch of input.normalize('NFC')) {
    if (REPLACEMENTS[ch] !== undefined) {
      out += REPLACEMENTS[ch];
      continue;
    }
    if (ch === '\t') {
      out += ' ';
      continue;
    }
    const code = ch.codePointAt(0)!;
    if (code >= 0x20 && code <= 0x7e) {
      out += ch;
      continue;
    }
    if (code < 0x20 || code === 0x7f || (code >= 0x80 && code < 0xa0)) {
      continue; // control characters are dropped, never forwarded
    }
    // Accented Latin letters: strip the combining marks (é → e).
    const base = ch.normalize('NFD').replace(/[̀-ͯ]/g, '');
    out += /^[\x20-\x7e]+$/.test(base) ? base : '?';
  }
  return out;
}

/** Word-wraps one paragraph to `width` columns; words longer than a line are hard-broken. */
export function wrapText(text: string, width: number): string[] {
  const clean = toPrintableText(text).replace(/\s+/g, ' ').trim();
  if (clean === '') return [''];
  const lines: string[] = [];
  let current = '';
  for (let word of clean.split(' ')) {
    while (word.length > width) {
      if (current !== '') {
        lines.push(current);
        current = '';
      }
      lines.push(word.slice(0, width));
      word = word.slice(width);
    }
    if (word === '') continue;
    if (current === '') current = word;
    else if (current.length + 1 + word.length <= width) current += ' ' + word;
    else {
      lines.push(current);
      current = word;
    }
  }
  if (current !== '') lines.push(current);
  return lines;
}

/** Wraps text that may contain explicit newlines. */
export function wrapParagraphs(text: string, width: number): string[] {
  return text.split(/\r?\n/).flatMap((paragraph) => wrapText(paragraph, width));
}

/**
 * `left` padded so `right` ends exactly at column `width`. When both don't fit on one line with at
 * least one space between them, `left` wraps and `right` goes right-aligned on its own last line.
 */
export function leftRight(left: string, right: string, width: number, indent = 0): string[] {
  const r = toPrintableText(right).trim();
  const pad = ' '.repeat(indent);
  const leftLines = wrapText(left, width - indent).map((l) => pad + l);
  const last = leftLines[leftLines.length - 1];
  if (last.trimEnd().length + 1 + r.length <= width) {
    leftLines[leftLines.length - 1] = last.trimEnd() + ' '.repeat(width - last.trimEnd().length - r.length) + r;
    return leftLines;
  }
  return [...leftLines, r.length >= width ? r.slice(0, width) : ' '.repeat(width - r.length) + r];
}

export function divider(width: number, char = '-'): string {
  return char.repeat(width);
}
