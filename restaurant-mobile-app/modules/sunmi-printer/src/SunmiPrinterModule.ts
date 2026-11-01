import { NativeModule, requireOptionalNativeModule } from 'expo';

import type {
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
}

/** `null` on iOS, web, and builds that don't include the module (e.g. Expo Go). */
export default requireOptionalNativeModule<SunmiPrinterNativeModule>('SunmiPrinter');
