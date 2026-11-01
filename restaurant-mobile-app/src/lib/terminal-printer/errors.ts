import type { PrinterState } from './types';

export type PrinterErrorCode =
  | 'NOT_SUPPORTED_PLATFORM'
  | 'NATIVE_MODULE_UNAVAILABLE'
  | 'NOT_SUNMI_DEVICE'
  | 'SERVICE_NOT_INSTALLED'
  | 'SERVICE_DISCONNECTED'
  | 'CONNECT_TIMEOUT'
  | 'NO_PRINTER'
  | 'PAPER_OUT'
  | 'OVERHEATED'
  | 'BUSY'
  | 'COVER_OPEN'
  | 'HARDWARE_ERROR'
  | 'PRINT_FAILED'
  | 'PRINT_TIMEOUT'
  | 'INVALID_DATA'
  | 'SDK_ERROR'
  | 'UNKNOWN';

export const PRINTER_ERROR_MESSAGES: Record<PrinterErrorCode, string> = {
  NOT_SUPPORTED_PLATFORM: 'Receipt printing is only available on the Android POS terminal.',
  NATIVE_MODULE_UNAVAILABLE:
    'This app build does not include the printer driver. Install the POS development or release build.',
  NOT_SUNMI_DEVICE: 'This device is not a SUNMI terminal, so it has no built-in printer.',
  SERVICE_NOT_INSTALLED: 'The SUNMI printer service is not installed on this device.',
  SERVICE_DISCONNECTED: 'Lost connection to the printer service. Try again.',
  CONNECT_TIMEOUT: 'The printer service did not respond. Try again in a moment.',
  NO_PRINTER: 'No printer was detected on this device.',
  PAPER_OUT: 'The printer is out of paper. Load a new roll and try again.',
  OVERHEATED: 'The printer is overheated. Let it cool down, then try again.',
  BUSY: 'The printer is busy or warming up. Try again in a moment.',
  COVER_OPEN: 'The printer cover is open. Close it and try again.',
  HARDWARE_ERROR: 'The printer reported a hardware problem.',
  PRINT_FAILED: 'The printer could not print the receipt. Nothing is lost — you can print it again.',
  PRINT_TIMEOUT:
    'The printer did not confirm the job. Check whether the receipt came out before printing again.',
  INVALID_DATA: 'The receipt data is invalid and was not sent to the printer.',
  SDK_ERROR: 'The printer service returned an error.',
  UNKNOWN: 'An unexpected printer error occurred.',
};

export class PrinterError extends Error {
  readonly code: PrinterErrorCode;
  readonly technical?: string;

  constructor(code: PrinterErrorCode, technical?: string) {
    super(PRINTER_ERROR_MESSAGES[code]);
    this.name = 'PrinterError';
    this.code = code;
    this.technical = technical;
  }
}

/** Maps a rejection from the native module (CodedException → `{ code, message }`). */
export function fromNativeError(error: unknown): PrinterError {
  if (error instanceof PrinterError) return error;
  const code = (error as { code?: unknown })?.code;
  const message = error instanceof Error ? error.message : String(error);
  switch (code) {
    case 'SERVICE_NOT_INSTALLED':
      return new PrinterError('SERVICE_NOT_INSTALLED', message);
    case 'BIND_FAILED':
    case 'CONNECT_TIMEOUT':
      return new PrinterError('CONNECT_TIMEOUT', message);
    case 'INVALID_COMMAND':
      return new PrinterError('INVALID_DATA', message);
    case 'REMOTE_ERROR':
      return new PrinterError('SERVICE_DISCONNECTED', message);
    case 'NATIVE_ERROR':
      return new PrinterError('SDK_ERROR', message);
    default:
      return new PrinterError('UNKNOWN', message);
  }
}

export type SunmiStateInfo = { state: PrinterState; errorCode?: PrinterErrorCode; label: string };

/** `updatePrinterState()` codes, per SUNMI's printer developer documentation. */
export function describeSunmiState(code: number | null): SunmiStateInfo {
  switch (code) {
    case 1:
      return { state: 'ready', label: 'Ready' };
    case 2:
      return { state: 'busy', errorCode: 'BUSY', label: 'Preparing' };
    case 3:
      return { state: 'error', errorCode: 'HARDWARE_ERROR', label: 'Communication error' };
    case 4:
      return { state: 'paper_out', errorCode: 'PAPER_OUT', label: 'Out of paper' };
    case 5:
      return { state: 'overheated', errorCode: 'OVERHEATED', label: 'Overheated' };
    case 6:
      return { state: 'error', errorCode: 'COVER_OPEN', label: 'Cover open' };
    case 7:
      return { state: 'error', errorCode: 'HARDWARE_ERROR', label: 'Cutter error' };
    case 8:
      return { state: 'error', errorCode: 'HARDWARE_ERROR', label: 'Cutter recovered' };
    case 9:
      return { state: 'error', errorCode: 'HARDWARE_ERROR', label: 'Black mark not detected' };
    case 505:
      return { state: 'unsupported', errorCode: 'NO_PRINTER', label: 'No printer detected' };
    case 507:
      return { state: 'error', errorCode: 'HARDWARE_ERROR', label: 'Printer firmware update failed' };
    case null:
      return { state: 'unknown', label: 'Unknown' };
    default:
      return { state: 'unknown', errorCode: 'UNKNOWN', label: `Unknown state (${code})` };
  }
}
