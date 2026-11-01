import AsyncStorage from '@react-native-async-storage/async-storage';

import type { PrintLayout } from './types';

export const MIN_CHARS_PER_LINE = 24;
export const MAX_CHARS_PER_LINE = 64;

export function clampWidth(charsPerLine: number): number {
  return Math.min(MAX_CHARS_PER_LINE, Math.max(MIN_CHARS_PER_LINE, Math.floor(charsPerLine)));
}

/**
 * The terminal printer on this device: whether receipts go to it, and how
 * its paper is laid out. `use` null: not chosen yet, so a SUNMI terminal
 * prints on its own printer and other phones keep their usual printing.
 */
export type TerminalPrinterSettings = PrintLayout & { use: boolean | null };

/**
 * 58 mm paper is 384 dots wide (SUNMI docs). 32 columns assumes the service's default font at size
 * 24 renders ASCII 12 dots wide; print the width calibration page to check on a given terminal.
 */
export const DEFAULT_PRINTER_SETTINGS: TerminalPrinterSettings = {
  use: null,
  charsPerLine: 32,
  fontSize: 24,
  largeFontSize: 32,
  feedLinesAfter: 3,
  timeoutSeconds: 30,
};

const KEY = 'miles.terminal-printer';

let settings: TerminalPrinterSettings = DEFAULT_PRINTER_SETTINGS;
const listeners = new Set<() => void>();

// Kept on the device: a terminal's paper does not change with who signs in.
void AsyncStorage.getItem(KEY)
  .then((raw) => {
    if (!raw) return;
    settings = { ...DEFAULT_PRINTER_SETTINGS, ...(JSON.parse(raw) as Partial<TerminalPrinterSettings>) };
    listeners.forEach((l) => l());
  })
  .catch(() => {});

export function getPrinterSettings(): TerminalPrinterSettings {
  return settings;
}

export function updatePrinterSettings(patch: Partial<TerminalPrinterSettings>): void {
  const next = { ...settings, ...patch };
  next.charsPerLine = clampWidth(next.charsPerLine);
  next.feedLinesAfter = Math.min(10, Math.max(0, Math.round(next.feedLinesAfter)));
  next.timeoutSeconds = Math.min(120, Math.max(5, Math.round(next.timeoutSeconds)));
  settings = next;
  AsyncStorage.setItem(KEY, JSON.stringify(next)).catch(() => {});
  listeners.forEach((l) => l());
}

export function subscribePrinterSettings(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}
