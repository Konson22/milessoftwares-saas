import { requireOptionalNativeModule } from 'expo';

export interface PairedDevice {
  address: string;
  name: string;
  /** Says it is a printer (Bluetooth class "imaging"); many cheap ones do not. */
  printer: boolean;
}

interface BluetoothPrinterModule {
  isEnabled(): boolean;
  pairedDevices(): Promise<PairedDevice[]>;
  print(address: string, data: Uint8Array): Promise<void>;
}

/**
 * Bluetooth Classic receipt printing (Android, in a development or store
 * build). Null in Expo Go, on iOS and on the web: printing then goes through
 * the system print dialog.
 */
export default requireOptionalNativeModule<BluetoothPrinterModule>('BluetoothPrinter');
