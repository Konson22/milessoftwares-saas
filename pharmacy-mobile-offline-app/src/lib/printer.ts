import { PermissionsAndroid, Platform } from 'react-native';

import BluetoothPrinter, { type PairedDevice } from '../../modules/bluetooth-printer';

/** Direct Bluetooth printing is there (an Android build with the printer module). */
export const bluetoothPrinting = BluetoothPrinter !== null;

/** Android 12 and newer ask before the app may see paired devices or connect. */
async function allowBluetooth(): Promise<void> {
  if (Platform.OS !== 'android' || Number(Platform.Version) < 31) return;
  const permission = PermissionsAndroid.PERMISSIONS.BLUETOOTH_CONNECT;
  if (await PermissionsAndroid.check(permission)) return;
  const result = await PermissionsAndroid.request(permission, {
    title: 'Print receipts over Bluetooth',
    message: 'Miles Pharmacy POS connects to the receipt printer paired with this device.',
    buttonPositive: 'Allow',
  });
  if (result !== PermissionsAndroid.RESULTS.GRANTED) {
    throw new Error('Allow the app to use nearby devices (Bluetooth) to print. You can change this in the app settings.');
  }
}

export async function pairedDevices(): Promise<PairedDevice[]> {
  if (!BluetoothPrinter) return [];
  await allowBluetooth();
  return BluetoothPrinter.pairedDevices();
}

export async function sendToPrinter(address: string, data: Uint8Array): Promise<void> {
  if (!BluetoothPrinter) throw new Error('This build of the app cannot print over Bluetooth.');
  await allowBluetooth();
  await BluetoothPrinter.print(address, data);
}

export type { PairedDevice };
