import * as Crypto from 'expo-crypto';
import { Directory, File, Paths } from 'expo-file-system';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import * as Location from 'expo-location';

import type { GeoPoint } from './definition';
import type { PhotoFile } from './types';
import { toHex } from './uuid';

/*
 * Photos and locations. A photo is re-encoded on the phone before it is
 * kept: resized to the workspace's limit and saved as a fresh JPEG, which
 * drops the camera's EXIF (its location, device and time). It is kept in
 * the app's private document directory, never in the gallery.
 */

const photos = () => new Directory(Paths.document, 'collect-photos');

export async function takePhoto(maxKb: number): Promise<PhotoFile | null> {
  const permission = await ImagePicker.requestCameraPermissionsAsync();
  if (!permission.granted) throw new Error('The camera is not allowed. Allow it in the phone settings.');
  const shot = await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 1, exif: false, allowsEditing: false });
  if (shot.canceled || !shot.assets[0]) return null;
  const source = shot.assets[0];

  // Smaller and lower quality until it fits the workspace's limit.
  const attempts: [number, number][] = [
    [1600, 0.8],
    [1280, 0.7],
    [1024, 0.6],
    [800, 0.5],
  ];
  let saved: { uri: string } | null = null;
  for (const [width, compress] of attempts) {
    if (saved) new File(saved.uri).delete();
    const context = ImageManipulator.manipulate(source.uri);
    if (source.width > width) context.resize({ width });
    const image = await context.renderAsync();
    saved = await image.saveAsync({ format: SaveFormat.JPEG, compress });
    if (new File(saved.uri).size <= maxKb * 1024) break;
  }
  if (!saved) return null;

  const directory = photos();
  if (!directory.exists) directory.create({ intermediates: true, idempotent: true });
  const temp = new File(saved.uri);
  const bytes = await temp.bytes();
  const sha256 = toHex(await Crypto.digest(Crypto.CryptoDigestAlgorithm.SHA256, bytes));
  const kept = new File(directory, `${sha256}.jpg`);
  if (kept.exists) temp.delete();
  else temp.move(kept);
  // The camera's own file (with its EXIF) does not stay behind.
  try {
    new File(source.uri).delete();
  } catch {
    // The picker's cache: the system clears it.
  }
  return { uri: kept.uri, sha256, size: bytes.length, mime: 'image/jpeg', uploaded: false };
}

export const files = {
  async remove(uri: string) {
    const file = new File(uri);
    if (file.exists) file.delete();
  },
};

/** The phone's position, rounded to about 10 cm, with its accuracy in metres. */
export async function currentPlace(): Promise<GeoPoint> {
  const permission = await Location.requestForegroundPermissionsAsync();
  if (!permission.granted) throw new Error('Location is not allowed. Allow it in the phone settings.');
  const position = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
  const round = (n: number) => Math.round(n * 1e6) / 1e6;
  return {
    lat: round(position.coords.latitude),
    lng: round(position.coords.longitude),
    ...(position.coords.accuracy != null ? { accuracy: Math.round(position.coords.accuracy) } : {}),
  };
}
