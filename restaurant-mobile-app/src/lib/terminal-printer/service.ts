import { PRINTER_ERROR_MESSAGES, PrinterError } from './errors';
import SunmiPrinter from '../../../modules/sunmi-printer';
import { getPrinterSettings, printerSettingsLoaded, updatePrinterSettings } from './settings';
import { askBluetoothPermission, BluetoothDriver } from './bluetooth-driver';
import { SunmiDriver } from './sunmi-driver';
import { divider } from './text-layout';
import type { PrintDocument, PrinterDriver, PrinterResult, PrinterStatus, PrintLine } from './types';

export type PrintPurpose = 'test' | 'calibration' | 'receipt' | 'reprint' | 'ticket';

export type DiagnosticEntry = {
  at: string;
  kind: 'status' | 'print' | 'connection' | 'error';
  summary: string;
  /** Technical detail only — never receipt contents, customer data or credentials. */
  technical?: string;
};

type Snapshot = {
  busy: boolean;
  lastStatus: PrinterStatus | null;
  lastResult: PrinterResult | null;
  lastError: DiagnosticEntry | null;
  diagnostics: DiagnosticEntry[];
};

const MAX_DIAGNOSTICS = 50;

/**
 * Printing on the terminal's own printer (a SUNMI POS device). Screens call
 * this, never a driver directly, so other terminals can be added behind
 * `PrinterDriver`.
 *
 * Jobs run strictly one after another (a failed job doesn't block the next one), and a print
 * outcome is only reported as `completed` when the driver confirms it. Nothing here retries a
 * print automatically: an `unknown` outcome may already have produced paper.
 */
export class PrinterService {
  private queue: Promise<unknown> = Promise.resolve();
  private pending = 0;
  private jobCounter = 0;
  private state: Snapshot = { busy: false, lastStatus: null, lastResult: null, lastError: null, diagnostics: [] };
  private listeners = new Set<() => void>();
  private connectionUnsubscribe: (() => void) | null = null;

  constructor(private readonly driver: PrinterDriver) {}

  get driverLabel(): string {
    return this.driver.label;
  }

  getSnapshot = (): Snapshot => this.state;

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    if (!this.connectionUnsubscribe && this.driver.onConnectionChange) {
      this.connectionUnsubscribe = this.driver.onConnectionChange((connected) =>
        this.log({ kind: 'connection', summary: connected ? 'Printer service connected' : 'Printer service disconnected' }),
      );
    }
    return () => {
      this.listeners.delete(listener);
    };
  };

  /** The native module is in this build (an Android build, not Expo Go). */
  isDriverAvailable(): boolean {
    return this.driver.isAvailable();
  }

  /** This device has a built-in printer (a SUNMI terminal, or its printer service installed). */
  isTerminal(): boolean {
    return this.driver.getDeviceInfo()?.isCompatible ?? false;
  }

  deviceLabel(): string | null {
    return this.driver.getDeviceInfo()?.label ?? null;
  }

  async getStatus(): Promise<PrinterStatus> {
    try {
      const status = await this.driver.getStatus();
      this.set({ lastStatus: status });
      this.log({
        kind: 'status',
        summary: `Status: ${status.state} — ${status.message}`,
        technical: status.details.stateCode != null ? `stateCode ${status.details.stateCode}` : undefined,
      });
      return status;
    } catch (e) {
      const error = e instanceof PrinterError ? e : new PrinterError('UNKNOWN', String(e));
      this.log({ kind: 'error', summary: error.message, technical: error.technical });
      throw error;
    }
  }

  printTestPage(appName: string): Promise<PrinterResult> {
    const width = getPrinterSettings().charsPerLine;
    const t = (text: string, extra: Partial<Extract<PrintLine, { kind: 'text' }>> = {}): PrintLine => ({ kind: 'text', text, ...extra });
    return this.printDocument(
      {
        lines: [
          t('PRINTER TEST', { align: 'center', bold: true }),
          t(divider(width)),
          t(`Device: ${this.deviceLabel() ?? 'Unknown device'}`),
          t(`Printer: ${this.driver.label}`),
          t(`Time: ${new Date().toLocaleString()}`),
          t(''),
          t(appName, { align: 'center' }),
          t(''),
          t('If you can read this,'),
          t('the printer is working.'),
          t(divider(width)),
        ],
      },
      'test',
    );
  }

  /**
   * Width calibration ruler: the longest ruler line that prints on a single
   * line is the paper's "characters per line".
   */
  printCalibrationPage(): Promise<PrinterResult> {
    const ruler = (n: number) => {
      let s = '';
      for (let i = 1; i <= n; i++) s += i % 10 === 0 ? String((i / 10) % 10) : String(i % 10 === 5 ? '+' : '.');
      return s;
    };
    const lines: PrintLine[] = [
      { kind: 'text', text: 'WIDTH CALIBRATION', align: 'center', bold: true },
      { kind: 'text', text: 'Pick the longest line that is' },
      { kind: 'text', text: 'NOT wrapped onto two lines.' },
      { kind: 'text', text: '' },
    ];
    for (const n of [28, 30, 32, 34, 36, 38, 40, 42, 48]) {
      lines.push({ kind: 'text', text: `${n}` });
      lines.push({ kind: 'text', text: ruler(n) });
    }
    return this.printDocument({ lines }, 'calibration');
  }

  printDocument(document: PrintDocument, purpose: PrintPurpose): Promise<PrinterResult> {
    const jobId = this.nextJobId();
    this.pending++;
    this.set({ busy: true });
    const run = () => this.runJob(jobId, document, purpose);
    const job = this.queue.then(run, run);
    this.queue = job.catch(() => undefined);
    return job.finally(() => {
      this.pending--;
      if (this.pending === 0) this.set({ busy: false });
    });
  }

  private async runJob(jobId: string, document: PrintDocument, purpose: PrintPurpose): Promise<PrinterResult> {
    this.log({ kind: 'print', summary: `Job ${jobId} (${purpose}) submitted` });
    let outcome;
    try {
      outcome = await this.driver.print(document, getPrinterSettings());
    } catch (e) {
      const error = e instanceof PrinterError ? e : new PrinterError('UNKNOWN', String(e));
      return this.fail(jobId, error.code, error.technical);
    }
    if (outcome.outcome === 'completed') {
      const result: PrinterResult = {
        success: true,
        jobId,
        outcome: 'completed',
        message: 'Printer reported the job as printed.',
        technical: outcome.technical,
      };
      this.set({ lastResult: result });
      this.log({ kind: 'print', summary: `Job ${jobId} (${purpose}) completed`, technical: outcome.technical });
      return result;
    }
    return this.fail(jobId, outcome.errorCode ?? 'PRINT_FAILED', outcome.technical, outcome.outcome);
  }

  private fail(jobId: string, code: PrinterError['code'], technical?: string, outcome: PrinterResult['outcome'] = 'not_printed'): PrinterResult {
    // A generic message is no help at the counter: add what the printer itself reported.
    const generic = code === 'UNKNOWN' || code === 'SDK_ERROR' || code === 'PRINT_FAILED';
    const message = generic && technical ? `${PRINTER_ERROR_MESSAGES[code]} (${technical})` : PRINTER_ERROR_MESSAGES[code];
    const result: PrinterResult = { success: false, jobId, outcome, errorCode: code, message, technical };
    this.set({ lastResult: result });
    this.log({ kind: 'error', summary: `Job ${jobId} ${outcome}: ${code}`, technical });
    return result;
  }

  private nextJobId(): string {
    this.jobCounter += 1;
    return `job-${Date.now().toString(36)}-${this.jobCounter}`;
  }

  private log(entry: Omit<DiagnosticEntry, 'at'>) {
    const full: DiagnosticEntry = { at: new Date().toISOString(), ...entry };
    this.set({
      diagnostics: [full, ...this.state.diagnostics].slice(0, MAX_DIAGNOSTICS),
      ...(entry.kind === 'error' ? { lastError: full } : {}),
    });
  }

  private set(patch: Partial<Snapshot>) {
    this.state = { ...this.state, ...patch };
    this.listeners.forEach((l) => l());
  }
}

/** The SUNMI printer service (a SUNMI terminal's printer, or a compatible service). */
export const printerService = new PrinterService(new SunmiDriver());

/** A paired Bluetooth ESC/POS printer. */
export const bluetoothPrinterService = new PrinterService(new BluetoothDriver());

/** The printer the settings send paper to (when they send it to one at all: see printsOnTerminal). */
export function activePrinter(): PrinterService {
  return getPrinterSettings().connection === 'bluetooth' ? bluetoothPrinterService : printerService;
}

/**
 * Whether paper goes straight to a printer rather than the print dialog:
 * a Bluetooth printer once one is chosen; the built-in one when chosen, or,
 * not chosen yet, on a terminal that has one.
 */
export function printsOnTerminal(): boolean {
  const { use, connection, bluetoothAddress } = getPrinterSettings();
  if (use === false) return false;
  if (connection === 'bluetooth') return bluetoothPrinterService.isDriverAvailable() && bluetoothAddress !== null;
  if (!printerService.isDriverAvailable()) return false;
  return use ?? printerService.isTerminal();
}

/** A POS terminal's built-in printer as Android lists it among paired Bluetooth devices. */
const INNER_PRINTER = /inner\s*printer/i;

/** Failures that mean the built-in printer service cannot be reached at all (nothing printed). */
const SERVICE_UNREACHABLE = new Set(['SERVICE_NOT_INSTALLED', 'CONNECT_TIMEOUT', 'NO_PRINTER']);

/**
 * The terminal's built-in printer over Bluetooth ("InnerPrinter", paired by the maker): how
 * terminals print whose printer service is not SUNMI's own. Chosen for good once found.
 */
async function adoptInnerPrinter(): Promise<boolean> {
  if (!bluetoothPrinterService.isDriverAvailable() || SunmiPrinter == null) return false;
  if (!SunmiPrinter.bluetoothState().enabled) return false;
  if (!(await askBluetoothPermission())) return false;
  const devices = await SunmiPrinter.bluetoothPrinters().catch(() => []);
  const inner = devices.find((d) => INNER_PRINTER.test(d.name));
  if (!inner) return false;
  updatePrinterSettings({ use: true, connection: 'bluetooth', bluetoothAddress: inner.address, bluetoothName: inner.name });
  return true;
}

/**
 * Whether this device prints straight to a printer, finding one when nothing is chosen yet:
 * the built-in printer service, else a terminal's paired "InnerPrinter". The print dialog is
 * left for phones with neither, or when it was chosen under Printer.
 */
export async function hasDirectPrinter(): Promise<boolean> {
  await printerSettingsLoaded;
  if (printsOnTerminal()) return true;
  if (getPrinterSettings().use !== null) return false;
  return adoptInnerPrinter().catch(() => false);
}

/** Prints on the chosen printer; a job the printer did not confirm comes back as an error, with what to do. */
export async function printOnTerminal(document: PrintDocument, purpose: PrintPurpose): Promise<void> {
  await printerSettingsLoaded;
  const auto = getPrinterSettings().use === null;
  let result = await activePrinter().printDocument(document, purpose);
  // Not chosen yet and the built-in service could not be reached, so nothing came out: a
  // terminal whose printer is paired over Bluetooth prints there instead (and keeps it).
  if (
    !result.success &&
    auto &&
    result.outcome === 'not_printed' &&
    getPrinterSettings().connection === 'builtin' &&
    SERVICE_UNREACHABLE.has(result.errorCode ?? '') &&
    (await adoptInnerPrinter().catch(() => false))
  ) {
    result = await bluetoothPrinterService.printDocument(document, purpose);
  }
  if (!result.success) throw new Error(result.message);
}
