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
  | 'BLUETOOTH_OFF'
  | 'BLUETOOTH_PERMISSION'
  | 'BLUETOOTH_NO_PRINTER'
  | 'BLUETOOTH_NOT_PAIRED'
  | 'BLUETOOTH_CONNECT_FAILED'
  | 'BLUETOOTH_WRITE_FAILED'
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
  BLUETOOTH_OFF: 'Bluetooth is off. Turn it on to print.',
  BLUETOOTH_PERMISSION: 'Allow this app to use nearby devices (Bluetooth) to print: Android settings, Apps, Bewe Miles Resto, Permissions.',
  BLUETOOTH_NO_PRINTER: 'No Bluetooth printer chosen. Pick one under Printer.',
  BLUETOOTH_NOT_PAIRED: 'The Bluetooth printer is not paired with this phone. Pair it in Bluetooth settings, then pick it under Printer.',
  BLUETOOTH_CONNECT_FAILED: 'Could not connect to the Bluetooth printer. Check that it is on and close by, then try again.',
  BLUETOOTH_WRITE_FAILED: 'The connection to the printer broke off. Check whether anything printed before printing again.',
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
    case 'BT_UNAVAILABLE':
      return new PrinterError('NOT_SUPPORTED_PLATFORM', message);
    case 'BT_OFF':
      return new PrinterError('BLUETOOTH_OFF', message);
    case 'BT_PERMISSION':
      return new PrinterError('BLUETOOTH_PERMISSION', message);
    case 'BT_NOT_PAIRED':
      return new PrinterError('BLUETOOTH_NOT_PAIRED', message);
    case 'BT_CONNECT_FAILED':
      return new PrinterError('BLUETOOTH_CONNECT_FAILED', message);
    case 'BT_WRITE_FAILED':
      return new PrinterError('BLUETOOTH_WRITE_FAILED', message);
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
      // A compatible (non-SUNMI) service's own code: not a known fault, so printing still goes ahead.
      return { state: 'unknown', label: `State ${code}` };
  }
}
