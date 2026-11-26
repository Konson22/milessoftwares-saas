import { PermissionsAndroid, Platform } from 'react-native';

import SunmiPrinter, { type BluetoothDevice } from '../../../modules/sunmi-printer';
import { fromNativeError, PrinterError } from './errors';
import { settleMs, toBase64, toEscPos } from './escpos';
import { getPrinterSettings } from './settings';
import type { DeviceInfo, DriverPrintResult, PrintDocument, PrinterDriver, PrinterStatus, PrintLayout } from './types';

/** Android 12+ asks for "nearby devices" before an app may talk to a paired printer. */
export async function askBluetoothPermission(): Promise<boolean> {
  if (Platform.OS !== 'android') return false;
  if (Number(Platform.Version) < 31) return true;
  const result = await PermissionsAndroid.request(PermissionsAndroid.PERMISSIONS.BLUETOOTH_CONNECT);
  return result === PermissionsAndroid.RESULTS.GRANTED;
}

/** The phone's paired Bluetooth devices, printers first. */
export async function pairedBluetoothDevices(): Promise<BluetoothDevice[]> {
  if (SunmiPrinter == null) throw new PrinterError('NATIVE_MODULE_UNAVAILABLE');
  if (!(await askBluetoothPermission())) throw new PrinterError('BLUETOOTH_PERMISSION');
  try {
    return await SunmiPrinter.bluetoothPrinters();
  } catch (e) {
    throw fromNativeError(e);
  }
}

/**
 * A Bluetooth ESC/POS receipt printer paired with the phone: an external one, or a POS
 * terminal's built-in printer that shows up as a paired device ("InnerPrinter"). The printer
 * chosen in the settings; the bytes are handed over, and the printer does not report back.
 */
export class BluetoothDriver implements PrinterDriver {
  readonly id = 'bluetooth-escpos';
  readonly label = 'Bluetooth printer';

  isAvailable(): boolean {
    return Platform.OS === 'android' && SunmiPrinter != null && typeof SunmiPrinter.bluetoothState === 'function';
  }

  getDeviceInfo(): DeviceInfo | null {
    const { bluetoothName, bluetoothAddress } = getPrinterSettings();
    if (!this.isAvailable() || !bluetoothAddress) return null;
    return { label: bluetoothName ?? bluetoothAddress, isCompatible: true };
  }

  async initialize(): Promise<void> {}

  async getStatus(): Promise<PrinterStatus> {
    const { bluetoothAddress, bluetoothName } = getPrinterSettings();
    const details = {
      driver: this.label,
      device: bluetoothName ? `${bluetoothName} (${bluetoothAddress})` : bluetoothAddress,
      serviceInstalled: null,
      stateCode: null,
      paper: null,
      serviceVersion: null,
      printerVersion: null,
      printerModel: null,
      printerSerial: null,
    };
    const status = (state: PrinterStatus['state'], message: string, connected = false): PrinterStatus => ({
      available: state === 'ready',
      connected,
      state,
      message,
      details,
    });
    if (!this.isAvailable()) return status('unsupported', 'Printer driver not included in this build.');
    const bt = SunmiPrinter!.bluetoothState();
    if (!bt.available) return status('unsupported', 'This phone has no Bluetooth.');
    if (!bt.enabled) return status('disconnected', 'Bluetooth is off.');
    if (!bt.permitted) return status('disconnected', 'Allow nearby devices (Bluetooth) for this app.');
    if (!bluetoothAddress) return status('disconnected', 'No printer chosen.');
    const paired = await SunmiPrinter!.bluetoothPrinters().catch(() => []);
    if (!paired.some((d) => d.address.toUpperCase() === bluetoothAddress.toUpperCase())) {
      return status('disconnected', 'The printer is no longer paired with this phone.');
    }
    // Paired and in reach of a connection: the printer itself reports nothing more.
    return status('ready', bt.connectedAddress === bluetoothAddress ? 'Connected' : 'Paired', true);
  }

  async print(document: PrintDocument, layout: PrintLayout): Promise<DriverPrintResult> {
    if (!this.isAvailable()) return { outcome: 'not_printed', errorCode: 'NATIVE_MODULE_UNAVAILABLE' };
    const { bluetoothAddress } = getPrinterSettings();
    if (!bluetoothAddress) return { outcome: 'not_printed', errorCode: 'BLUETOOTH_NO_PRINTER' };
    if (!(await askBluetoothPermission())) return { outcome: 'not_printed', errorCode: 'BLUETOOTH_PERMISSION' };
    const bytes = toEscPos(document, layout);
    try {
      await SunmiPrinter!.bluetoothPrint(bluetoothAddress, toBase64(bytes), settleMs(bytes));
      return { outcome: 'completed', technical: `${bytes.length} bytes sent` };
    } catch (e) {
      const error = fromNativeError(e);
      // A write that broke off may have printed part of the job: check the paper before again.
      return { outcome: error.code === 'BLUETOOTH_WRITE_FAILED' ? 'unknown' : 'not_printed', errorCode: error.code, technical: error.technical };
    }
  }
}
