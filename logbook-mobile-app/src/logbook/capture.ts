import * as Crypto from 'expo-crypto';
import * as DocumentPicker from 'expo-document-picker';
import { Directory, File, Paths } from 'expo-file-system';
import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';

import type { LocalFile } from './types';
import { toHex } from './uuid';

/*
 * Evidence for file fields. A photo (camera or gallery) is re-encoded on
 * the phone before it is kept: resized to fit the workspace's limit and
 * saved as a fresh JPEG, which drops the camera's EXIF (its location,
 * device and time). Documents are copied as they are. Everything is kept
 * in the app's private document directory, never the gallery, and removed
 * once the server has it.
 */

const folder = () => new Directory(Paths.document, 'logbook-files');

/** The document types the server takes (App\Logbook\Attachments::MIMES). */
const DOCUMENT_TYPES: Record<string, string> = {
  pdf: 'application/pdf',
  doc: 'application/msword',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  xls: 'application/vnd.ms-excel',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  csv: 'text/csv',
  txt: 'text/plain',
};

async function keep(sourceUri: string, field: string, name: string, mime: string, extension: string, move: boolean): Promise<LocalFile> {
  const directory = folder();
  if (!directory.exists) directory.create({ intermediates: true, idempotent: true });
  const source = new File(sourceUri);
  const bytes = await source.bytes();
  const sha256 = toHex(await Crypto.digest(Crypto.CryptoDigestAlgorithm.SHA256, bytes));
  // Its own name, even for the same picture on two entries: each entry
  // frees its files once sent, without touching another's.
  const kept = new File(directory, `${Crypto.randomUUID()}.${extension}`);
  if (move) source.move(kept);
  else source.copy(kept);
  return { uri: kept.uri, field, name, mime, size: bytes.length, sha256, serverId: null };
}

async function reencode(uri: string, width: number, maxKb: number): Promise<string> {
  // Smaller and lower quality until it fits the limit.
  const attempts: [number, number][] = [
    [1600, 0.8],
    [1280, 0.7],
    [1024, 0.6],
    [800, 0.5],
  ];
  let saved: string | null = null;
  for (const [w, compress] of attempts) {
    if (saved) new File(saved).delete();
    const context = ImageManipulator.manipulate(uri);
    if (width > w) context.resize({ width: w });
    const image = await context.renderAsync();
    saved = (await image.saveAsync({ format: SaveFormat.JPEG, compress })).uri;
    if (new File(saved).size <= maxKb * 1024) break;
  }
  if (!saved) throw new Error('The photo could not be prepared.');
  return saved;
}

// Separate replacements. A single character class for these three separators is
// scanned by Tailwind as an arbitrary property and emits CSS the parser rejects.
const stamp = () => new Date().toISOString().slice(0, 19).replaceAll('-', '').replaceAll(':', '').replaceAll('T', '');

/** A photo from the camera, or null when the person cancels. */
export async function takePhoto(field: string, maxKb: number): Promise<LocalFile | null> {
  const permission = await ImagePicker.requestCameraPermissionsAsync();
  if (!permission.granted) throw new Error('The camera is not allowed. Allow it in the phone settings.');
  const shot = await ImagePicker.launchCameraAsync({ mediaTypes: ['images'], quality: 1, exif: false, allowsEditing: false });
  if (shot.canceled || !shot.assets[0]) return null;
  const source = shot.assets[0];
  const jpeg = await reencode(source.uri, source.width, maxKb);
  try {
    // The camera's own file (with its EXIF) does not stay behind.
    new File(source.uri).delete();
  } catch {
    // The picker's cache: the system clears it.
  }
  return keep(jpeg, field, `photo-${stamp()}.jpg`, 'image/jpeg', 'jpg', true);
}

/** A photo from the gallery, re-encoded the same way. */
export async function choosePhoto(field: string, maxKb: number): Promise<LocalFile | null> {
  const picked = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], quality: 1, exif: false, allowsMultipleSelection: false });
  if (picked.canceled || !picked.assets[0]) return null;
  const source = picked.assets[0];
  const jpeg = await reencode(source.uri, source.width, maxKb);
  return keep(jpeg, field, `photo-${stamp()}.jpg`, 'image/jpeg', 'jpg', true);
}

/** A document (PDF, Word, Excel, CSV, text), within the size limit. */
export async function chooseDocument(field: string, maxKb: number): Promise<LocalFile | null> {
  const picked = await DocumentPicker.getDocumentAsync({ type: Object.values(DOCUMENT_TYPES), copyToCacheDirectory: true, multiple: false });
  if (picked.canceled || !picked.assets[0]) return null;
  const asset = picked.assets[0];
  const extension = (asset.name.split('.').pop() ?? '').toLowerCase();
  const mime = DOCUMENT_TYPES[extension];
  if (!mime) throw new Error('Choose a PDF, Word, Excel, CSV or text file.');
  if ((asset.size ?? 0) > maxKb * 1024) throw new Error(`The file is larger than ${Math.round(maxKb / 1024)} MB.`);
  const name = asset.name.replace(/[\u0000-\u001f/\\:*?"<>|]+/g, '_').slice(0, 200) || `file.${extension}`;
  return keep(asset.uri, field, name, mime, extension, false);
}

export const files = {
  async remove(uri: string) {
    const file = new File(uri);
    if (file.exists) file.delete();
  },
};
