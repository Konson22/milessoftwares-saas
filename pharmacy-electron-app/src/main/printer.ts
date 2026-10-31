import { readFileSync, writeFileSync } from 'node:fs';
import { createConnection, isIP } from 'node:net';
import { join } from 'node:path';

import { app, BrowserWindow } from 'electron';

import type { PrintLine } from '../shared/ipc';
import { Validation } from './db/util';
import { COLUMNS, escpos, PAPER_STATUS, paperFrom, type Paper } from './escpos';

/**
 * The receipt printer on this PC, kept per PC in userData/printer.json (not
 * in the pharmacy's database: a backup restored on another PC finds other
 * printers). Two ways to reach it:
 *
 * - system: a printer installed in Windows (USB, or shared), through its
 *   driver. Straight to it, or through the print dialog.
 * - network: a thermal printer on the network by its address (port 9100),
 *   sent the receipt as ESC/POS text, with no driver at all.
 */

export type Connection = 'system' | 'network';

export interface PrinterSettings {
  connection: Connection;
  /** system: the printer's name; null: the system's default. */
  device: string | null;
  /** system: straight to the printer, without the dialog. */
  silent: boolean;
  /** network: its IP address (or name) and port. */
  host: string | null;
  port: number;
  /** network: cut the paper after each receipt. */
  cut: boolean;
  copies: number;
}

const DEFAULTS: PrinterSettings = { connection: 'system', device: null, silent: false, host: null, port: 9100, cut: true, copies: 1 };

const file = () => join(app.getPath('userData'), 'printer.json');

export function printerSettings(): PrinterSettings {
  try {
    return { ...DEFAULTS, ...(JSON.parse(readFileSync(file(), 'utf8')) as Partial<PrinterSettings>) };
  } catch {
    return DEFAULTS;
  }
}

/** Settings as sent from the window, checked. */
function parse(input: unknown): PrinterSettings {
  const data = (input && typeof input === 'object' ? input : {}) as Record<string, unknown>;
  const v = new Validation();
  const connection: Connection = data.connection === 'network' ? 'network' : 'system';
  const host = typeof data.host === 'string' && data.host.trim() ? data.host.trim() : null;
  const port = Number(data.port ?? 9100);
  const copies = Number(data.copies ?? 1);
  if (connection === 'network') {
    if (!host) v.add('host', 'Enter the printer’s IP address, as 192.168.1.50.');
    else if (!isIP(host) && !/^[a-zA-Z0-9.-]+$/.test(host)) v.add('host', 'An IP address, as 192.168.1.50.');
    if (!Number.isInteger(port) || port < 1 || port > 65535) v.add('port', 'From 1 to 65535; usually 9100.');
  }
  if (!Number.isInteger(copies) || copies < 1 || copies > 5) v.add('copies', 'From 1 to 5.');
  v.check();
  return {
    connection,
    device: typeof data.device === 'string' && data.device.trim() ? data.device.trim() : null,
    silent: data.silent === true,
    host,
    port,
    cut: data.cut !== false,
    copies,
  };
}

export function savePrinterSettings(input: unknown): PrinterSettings {
  const next = parse(input);
  writeFileSync(file(), JSON.stringify(next));
  return next;
}

/** The printers installed on this PC. */
export async function listPrinters(): Promise<{ name: string; label: string; isDefault: boolean }[]> {
  const window = BrowserWindow.getAllWindows()[0];
  if (!window) return [];
  const printers = await window.webContents.getPrintersAsync();
  return printers.map((p) => ({ name: p.name, label: p.displayName || p.name, isDefault: (p as { isDefault?: boolean }).isDefault === true }));
}

// Network ---------------------------------------------------------------------

/** Opens the connection, sends, and closes; refused or silent past the timeout, it fails. */
function send(host: string, port: number, data: Buffer, timeout = 5000): Promise<void> {
  return new Promise((resolve, reject) => {
    const socket = createConnection({ host, port });
    socket.setTimeout(timeout, () => socket.destroy(new Error('timeout')));
    socket.once('error', reject);
    socket.once('connect', () => socket.end(data));
    socket.once('close', (failed) => (failed ? undefined : resolve()));
  });
}

/** Whether the printer answers at its address, and what its paper sensor says (if it says). */
function probe(host: string, port: number): Promise<{ reached: true; paper: Paper | null } | { reached: false; reason: string }> {
  return new Promise((resolve) => {
    const socket = createConnection({ host, port });
    let connected = false;
    let done = false;
    const finish = (result: { reached: true; paper: Paper | null } | { reached: false; reason: string }) => {
      if (done) return;
      done = true;
      socket.destroy();
      resolve(result);
    };
    socket.setTimeout(4000, () => finish(connected ? { reached: true, paper: null } : { reached: false, reason: 'timeout' }));
    socket.once('error', (error: NodeJS.ErrnoException) => finish({ reached: false, reason: error.code ?? error.message }));
    socket.once('connect', () => {
      connected = true;
      socket.write(PAPER_STATUS);
      // Not every printer answers the status question: connected is enough.
      socket.setTimeout(1500, () => finish({ reached: true, paper: null }));
    });
    socket.once('data', (reply) => finish({ reached: true, paper: paperFrom(reply[0]) }));
  });
}

const REASONS: Record<string, string> = {
  timeout: 'Nothing answered at that address. Check the printer is on, its network cable is in, and the address is right (print its self-test page to see it).',
  ECONNREFUSED: 'Something is at that address but did not take the connection. Check the port (usually 9100).',
  EHOSTUNREACH: 'That address cannot be reached from this PC. Check both are on the same network.',
  ENETUNREACH: 'This PC is not on a network. Check its cable or Wi-Fi.',
  ENOTFOUND: 'No printer by that name. Use its IP address.',
};

export interface PrinterCheck {
  ok: boolean;
  message: string;
  paper?: Paper | null;
}

/** Whether receipts can reach the printer as set up (saved, or as being set up). */
export async function checkPrinter(input?: unknown): Promise<PrinterCheck> {
  const chosen = input === undefined ? printerSettings() : parse(input);
  if (chosen.connection === 'network') {
    const found = await probe(chosen.host!, chosen.port);
    if (!found.reached) return { ok: false, message: REASONS[found.reason] ?? `The printer could not be reached (${found.reason}).` };
    if (found.paper === 'out') return { ok: false, message: 'Connected, but the printer is out of paper (or its cover is open).', paper: 'out' };
    if (found.paper === 'low') return { ok: true, message: 'Connected. The paper roll is nearly finished.', paper: 'low' };
    return { ok: true, message: found.paper ? 'Connected, with paper.' : 'Connected.', paper: found.paper };
  }
  const printers = await listPrinters();
  if (printers.length === 0) return { ok: false, message: 'No printer is installed on this PC. Install the printer’s driver (from its CD or maker’s website), then look again.' };
  if (!chosen.device) {
    const fallback = printers.find((p) => p.isDefault);
    return { ok: true, message: fallback ? `Receipts go to the default printer, ${fallback.label}.` : 'Receipts go to the system’s default printer.' };
  }
  const found = printers.find((p) => p.name === chosen.device);
  if (!found) return { ok: false, message: `${chosen.device} is not installed or not connected. Plug it in and turn it on, or choose another.` };
  return { ok: true, message: `${found.label} is installed. Print a test page to be sure it is on and has paper.` };
}

// Printing --------------------------------------------------------------------

/**
 * A receipt to the printer set up here: the HTML page through the system, or
 * its lines to a network printer.
 */
export async function print(html: string, lines?: PrintLine[], chosen = printerSettings()): Promise<boolean> {
  if (chosen.connection === 'network') {
    if (!chosen.host || !lines?.length) return false;
    try {
      const data = escpos(lines, { cut: chosen.cut });
      for (let i = 0; i < chosen.copies; i++) await send(chosen.host, chosen.port, data);
      return true;
    } catch (error) {
      console.error('The network printer could not be reached', error);
      return false;
    }
  }

  const window = new BrowserWindow({ show: false, webPreferences: { sandbox: true, javascript: false } });
  return new Promise((resolve) => {
    window.webContents.once('did-finish-load', async () => {
      // A printer unplugged or removed: the default one instead.
      const installed = chosen.device ? (await window.webContents.getPrintersAsync()).some((p) => p.name === chosen.device) : false;
      window.webContents.print(
        { silent: chosen.silent, printBackground: true, copies: chosen.copies, ...(installed ? { deviceName: chosen.device! } : {}) },
        (success) => {
          window.destroy();
          resolve(success);
        },
      );
    });
    void window.loadURL(`data:text/html;charset=utf-8,${encodeURIComponent(html)}`);
  });
}

/** A short page to see the printer, the paper and its width work, as set up (saved or not). */
export async function printTestPage(name: string, paper: '80mm' | '58mm', input?: unknown): Promise<boolean> {
  const chosen = input === undefined ? printerSettings() : parse(input);
  const columns = COLUMNS[paper];
  const when = new Date().toLocaleString('en-GB');
  const how = chosen.connection === 'network' ? `Network ${chosen.host}:${chosen.port}` : (chosen.device ?? 'Default printer');
  const lines: PrintLine[] = [
    { text: name, align: 'center', bold: true, big: true },
    { text: 'Printer test', align: 'center' },
    { text: '-'.repeat(columns) },
    { text: `Paper: ${paper.replace('mm', ' mm')} roll (${columns} characters)` },
    { text: `Printer: ${how}`.slice(0, columns) },
    { text: when },
    { text: '-'.repeat(columns) },
    { text: `<${'-'.repeat(columns - 2)}>` },
    { text: 'If the line above fits, receipts fit.', align: 'center' },
  ];
  if (chosen.connection === 'network') return print('', lines, chosen);

  const width = paper === '58mm' ? 58 : 80;
  const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);
  const html = `<!doctype html><html><head><meta charset="utf-8">
<style>
  @page { size: ${width}mm auto; margin: 3mm; }
  body { font-family: 'Segoe UI', Roboto, Helvetica, sans-serif; font-size: 12px; color: #000; width: ${width - 8}mm; margin: 0 auto; text-align: center; }
  h1 { font-size: 15px; margin: 0 0 4px; } hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  .ruler { display: flex; justify-content: space-between; font-size: 10px; }
</style></head><body>
  <h1>${esc(name)}</h1>
  <div>Printer test</div>
  <hr>
  <div>Paper: ${width} mm roll</div>
  <div>Printer: ${esc(how)}</div>
  <div>${when}</div>
  <hr>
  <div class="ruler"><span>|&lt; left</span><span>right &gt;|</span></div>
  <hr>
  <div>If both edges show, the receipt fits.</div>
</body></html>`;
  return print(html, lines, chosen);
}
