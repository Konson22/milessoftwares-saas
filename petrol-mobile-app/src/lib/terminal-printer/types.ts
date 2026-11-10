import type { PrinterErrorCode } from './errors';

/** A driver-agnostic print document; each driver translates it into its own commands. */
export type PrintAlign = 'left' | 'center' | 'right';

export type PrintLine =
  | { kind: 'text'; text: string; align?: PrintAlign; bold?: boolean; size?: 'normal' | 'large' }
  | { kind: 'feed'; lines: number }
  | { kind: 'qr'; data: string; align?: PrintAlign }
  /** A black-and-white PNG (base64), `width` dots wide; keep within the paper (384 dots on 58 mm). */
  | { kind: 'image'; base64: string; width: number; height: number; align?: PrintAlign };

export type PrintDocument = { lines: PrintLine[] };

export type PrinterState =
  | 'ready'
  | 'busy'
  | 'paper_out'
  | 'overheated'
  | 'error'
  | 'disconnected'
  | 'unsupported'
  | 'unknown';

export type PrinterStatus = {
  available: boolean;
  connected: boolean;
  state: PrinterState;
  message: string;
  /** Diagnostics for the settings screen; null when the driver/model doesn't report them. */
  details: {
    driver: string;
    device: string | null;
    serviceInstalled: boolean | null;
    stateCode: number | null;
    paper: number | null;
    serviceVersion: string | null;
    printerVersion: string | null;
    printerModel: string | null;
    printerSerial: string | null;
  };
};

/**
 * - `completed`: the printer reported the job printed.
 * - `failed`: the printer reported failure — safe to reprint.
 * - `unknown`: no confirmation in time — the cashier must check the paper before reprinting.
 * - `not_printed`: nothing was sent (printer not ready, unavailable, invalid data).
 */
export type PrintOutcome = 'completed' | 'failed' | 'unknown' | 'not_printed';

export type PrinterResult = {
  success: boolean;
  jobId: string;
  outcome: PrintOutcome;
  errorCode?: PrinterErrorCode;
  /** Human-readable, for the cashier. */
  message: string;
  /** Technical detail for diagnostics; never shown as the primary message. */
  technical?: string;
};

export type DriverPrintResult = {
  outcome: PrintOutcome;
  errorCode?: PrinterErrorCode;
  technical?: string;
};

export type DeviceInfo = { label: string; isCompatible: boolean };

/** Contract every printer driver implements, so checkout code never depends on a specific printer. */
export interface PrinterDriver {
  readonly id: string;
  readonly label: string;
  /** Whether the native side for this driver is present in this build/platform at all. */
  isAvailable(): boolean;
  getDeviceInfo(): DeviceInfo | null;
  initialize(): Promise<void>;
  getStatus(): Promise<PrinterStatus>;
  print(document: PrintDocument, layout: PrintLayout): Promise<DriverPrintResult>;
  /** Returns an unsubscribe function. */
  onConnectionChange?(listener: (connected: boolean) => void): () => void;
}

export type PrintLayout = {
  /** Usable monospace columns per line at `fontSize`; calibrate per device with the ruler print. */
  charsPerLine: number;
  fontSize: number;
  largeFontSize: number;
  /** Blank lines fed after the job so the receipt clears the tear bar. */
  feedLinesAfter: number;
  /** Seconds to wait for the printer's result before reporting an unknown outcome. */
  timeoutSeconds: number;
};
