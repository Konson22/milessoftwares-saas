/** Error codes the native module rejects with (`error.code`). */
export type SunmiNativeErrorCode =
  | 'SERVICE_NOT_INSTALLED'
  | 'BIND_FAILED'
  | 'CONNECT_TIMEOUT'
  | 'INVALID_COMMAND'
  | 'REMOTE_ERROR'
  | 'NATIVE_ERROR';

export type SunmiDeviceInfo = {
  manufacturer: string;
  brand: string;
  model: string;
  device: string;
  isSunmi: boolean;
  serviceInstalled: boolean;
};

/** Raw readings from the printer service. Getters a model/firmware lacks come back as null. */
export type SunmiRawStatus = {
  serviceInstalled: boolean;
  connected: boolean;
  connectError: string | null;
  /** `updatePrinterState()`: 1 ready, 2 preparing, 3 comms error, 4 no paper, 5 overheated, 505 no printer… */
  stateCode: number | null;
  /** `getPrinterPaper()`: paper specification reported by the service. */
  paper: number | null;
  serviceVersion: string | null;
  printerVersion: string | null;
  printerModel: string | null;
  printerSerial: string | null;
};

export type SunmiTextCommand = {
  type: 'text';
  text: string;
  /** 0 left, 1 center, 2 right */
  align?: 0 | 1 | 2;
  bold?: boolean;
  fontSize?: number;
};
export type SunmiFeedCommand = { type: 'feed'; lines: number };
export type SunmiQrCommand = {
  type: 'qr';
  text: string;
  align?: 0 | 1 | 2;
  /** 4..16 */
  moduleSize?: number;
  /** 0..3 */
  errorLevel?: number;
};
export type SunmiBarcodeCommand = {
  type: 'barcode';
  text: string;
  align?: 0 | 1 | 2;
  /** 0 UPC-A … 8 CODE128 */
  symbology?: number;
  height?: number;
  width?: number;
  textPosition?: number;
};
/** A PNG/JPEG (base64), printed as a bitmap; at most 384 dots wide on 58 mm paper. */
export type SunmiImageCommand = { type: 'image'; base64: string; align?: 0 | 1 | 2 };
export type SunmiPrintCommand = SunmiTextCommand | SunmiFeedCommand | SunmiQrCommand | SunmiBarcodeCommand | SunmiImageCommand;

/**
 * - `completed`: the service reported the transaction printed (`onPrintResult` code 0).
 * - `failed`: the service reported the transaction failed; nothing (or only part) was printed.
 * - `unknown`: no result before the timeout — paper may or may not have come out.
 * - `rejected`: the printer was not ready, so nothing was sent.
 */
export type SunmiPrintOutcome = 'completed' | 'failed' | 'unknown' | 'rejected';

export type SunmiRawPrintResult = {
  outcome: SunmiPrintOutcome;
  stateCode: number | null;
  message: string | null;
  commandErrors: string[];
};

export type SunmiPrinterEvents = {
  onConnectionChange: (event: { connected: boolean }) => void;
};
