import { NativeModule, requireOptionalNativeModule } from 'expo';

import type {
  BluetoothDevice,
  BluetoothState,
  SunmiDeviceInfo,
  SunmiPrintCommand,
  SunmiPrinterEvents,
  SunmiRawPrintResult,
  SunmiRawStatus,
} from './SunmiPrinter.types';

declare class SunmiPrinterNativeModule extends NativeModule<SunmiPrinterEvents> {
  getDeviceInfo(): SunmiDeviceInfo;
  initialize(): Promise<void>;
  getStatus(): Promise<SunmiRawStatus>;
  print(commands: SunmiPrintCommand[], timeoutMs: number): Promise<SunmiRawPrintResult>;
  bluetoothState(): BluetoothState;
  bluetoothPrinters(): Promise<BluetoothDevice[]>;
  /** Raw ESC/POS bytes (base64) to a paired printer; waits `settleMs` after writing. */
  bluetoothPrint(address: string, base64: string, settleMs: number): Promise<void>;
  bluetoothDisconnect(): Promise<void>;
}

/** `null` on iOS, web, and builds that don't include the module (e.g. Expo Go). */
export default requireOptionalNativeModule<SunmiPrinterNativeModule>('SunmiPrinter');
