import { Platform } from 'react-native';

import SunmiPrinter, { type SunmiPrintCommand } from '../../../modules/sunmi-printer';
import { describeSunmiState, fromNativeError, PrinterError } from './errors';
import type {
  DeviceInfo,
  DriverPrintResult,
  PrintAlign,
  PrintDocument,
  PrinterDriver,
  PrinterStatus,
  PrintLayout,
} from './types';
import { toPrintableText } from './text-layout';

const ALIGN: Record<PrintAlign, 0 | 1 | 2> = { left: 0, center: 1, right: 2 };

export function toSunmiCommands(document: PrintDocument, layout: PrintLayout): SunmiPrintCommand[] {
  const commands: SunmiPrintCommand[] = document.lines.map((line) => {
    switch (line.kind) {
      case 'text':
        return {
          type: 'text',
          text: toPrintableText(line.text),
          align: ALIGN[line.align ?? 'left'],
          bold: line.bold ?? false,
          fontSize: line.size === 'large' ? layout.largeFontSize : layout.fontSize,
        };
      case 'feed':
        return { type: 'feed', lines: line.lines };
      case 'qr':
        return { type: 'qr', text: line.data, align: ALIGN[line.align ?? 'center'], moduleSize: 6, errorLevel: 1 };
      case 'image':
        return { type: 'image', base64: line.base64, align: ALIGN[line.align ?? 'center'] };
    }
  });
  if (layout.feedLinesAfter > 0) commands.push({ type: 'feed', lines: layout.feedLinesAfter });
  return commands;
}

/** SUNMI built-in printer, through the local `sunmi-printer` Expo module (AIDL printer service). */
export class SunmiDriver implements PrinterDriver {
  readonly id = 'sunmi-builtin';
  readonly label = 'SUNMI built-in printer';

  isAvailable(): boolean {
    return Platform.OS === 'android' && SunmiPrinter != null;
  }

  getDeviceInfo(): DeviceInfo | null {
    if (!this.isAvailable()) return null;
    const info = SunmiPrinter!.getDeviceInfo();
    return { label: `${info.manufacturer} ${info.model}`.trim(), isCompatible: info.isSunmi || info.serviceInstalled };
  }

  async initialize(): Promise<void> {
    this.requireModule();
    try {
      await SunmiPrinter!.initialize();
    } catch (e) {
      throw fromNativeError(e);
    }
  }

  async getStatus(): Promise<PrinterStatus> {
    const base = {
      driver: this.label,
      device: null,
      serviceInstalled: null,
      stateCode: null,
      paper: null,
      serviceVersion: null,
      printerVersion: null,
      printerModel: null,
      printerSerial: null,
    };
    if (Platform.OS !== 'android') {
      return { available: false, connected: false, state: 'unsupported', message: 'Not an Android device.', details: base };
    }
    if (SunmiPrinter == null) {
      return {
        available: false,
        connected: false,
        state: 'unsupported',
        message: 'Printer driver not included in this build.',
        details: base,
      };
    }
    const device = this.getDeviceInfo();
    const raw = await SunmiPrinter.getStatus().catch((e) => {
      throw fromNativeError(e);
    });
    const details = { ...base, ...raw, device: device?.label ?? null };
    if (!raw.serviceInstalled) {
      return {
        available: false,
        connected: false,
        state: 'unsupported',
        message: device?.isCompatible ? 'SUNMI printer service not installed.' : 'Not a SUNMI device — no built-in printer.',
        details,
      };
    }
    if (!raw.connected) {
      return {
        available: false,
        connected: false,
        state: 'disconnected',
        message: raw.connectError ?? 'Printer service not connected.',
        details,
      };
    }
    const info = describeSunmiState(raw.stateCode);
    return { available: info.state === 'ready', connected: true, state: info.state, message: info.label, details };
  }

  async print(document: PrintDocument, layout: PrintLayout): Promise<DriverPrintResult> {
    this.requireModule();
    const commands = toSunmiCommands(document, layout);
    let raw;
    try {
      raw = await SunmiPrinter!.print(commands, Math.round(layout.timeoutSeconds * 1000));
    } catch (e) {
      const error = fromNativeError(e);
      return { outcome: 'not_printed', errorCode: error.code, technical: error.technical };
    }
    const state = describeSunmiState(raw.stateCode);
    const technical = [
      raw.message,
      raw.stateCode != null ? `state ${raw.stateCode} (${state.label})` : null,
      ...raw.commandErrors.map((e) => `command error ${e}`),
    ]
      .filter(Boolean)
      .join('; ');
    switch (raw.outcome) {
      case 'completed':
        return { outcome: 'completed', technical: technical || undefined };
      case 'rejected':
        return { outcome: 'not_printed', errorCode: state.errorCode ?? 'UNKNOWN', technical };
      case 'failed':
        return { outcome: 'failed', errorCode: state.errorCode ?? 'PRINT_FAILED', technical };
      case 'unknown':
        return { outcome: 'unknown', errorCode: 'PRINT_TIMEOUT', technical };
    }
  }

  onConnectionChange(listener: (connected: boolean) => void): () => void {
    if (!this.isAvailable()) return () => {};
    const subscription = SunmiPrinter!.addListener('onConnectionChange', (e) => listener(e.connected));
    return () => subscription.remove();
  }

  private requireModule() {
    if (Platform.OS !== 'android') throw new PrinterError('NOT_SUPPORTED_PLATFORM');
    if (SunmiPrinter == null) throw new PrinterError('NATIVE_MODULE_UNAVAILABLE');
  }
}
