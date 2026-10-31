import { Directory, File, Paths } from 'expo-file-system';
import { openDatabaseSync, type SQLiteDatabase } from 'expo-sqlite';

import { closeDatabase, DATABASE_NAME, database, databaseDirectory, openDatabase, run } from './db/database';
import { signOut } from './db/session';
import { forgetSettings } from './db/settings';
import { localDate } from './db/util';

/**
 * Copies of the offline restaurant's database: one a day on its own (the
 * last 14 kept), and any made by hand. A copy can be shared off the phone (a
 * computer, a chat with yourself), and any copy, or one picked from the
 * phone's files, can be put back: that is how the restaurant moves to
 * another phone.
 */

export interface Backup {
  name: string;
  uri: string;
  size: number;
  at: string;
  auto: boolean;
}

const KEEP_AUTO = 14;
const SQLITE_HEADER = 'SQLite format 3\0';

const folder = () => {
  const dir = new Directory(Paths.document, 'backups');
  if (!dir.exists) dir.create({ intermediates: true });
  return dir;
};

/** A file:// URI as the plain path SQLite takes. */
const pathOf = (uri: string) => decodeURIComponent(uri.replace(/^file:\/\//, ''));
const asUri = (path: string) => (path.startsWith('file://') ? path : `file://${path}`);
const databaseFile = (suffix = '') => new File(asUri(databaseDirectory()), `${DATABASE_NAME}${suffix}`);

function stamp(d = new Date()) {
  const time = [d.getHours(), d.getMinutes(), d.getSeconds()].map((n) => String(n).padStart(2, '0')).join('');
  return `${localDate(d)}-${time}`;
}

export function listBackups(): Backup[] {
  return folder()
    .list()
    .filter((f): f is File => f instanceof File && f.name.endsWith('.db'))
    .map((f) => ({
      name: f.name,
      uri: f.uri,
      size: f.size,
      at: new Date(f.modificationTime ?? 0).toISOString(),
      auto: f.name.includes('-auto'),
    }))
    .sort((a, b) => b.at.localeCompare(a.at));
}

/** A consistent copy of the database as it is now. */
export function backupNow(kind: 'manual' | 'auto' | 'before-restore' = 'manual'): Backup {
  const name = `restaurant-${stamp()}${kind === 'manual' ? '' : `-${kind}`}.db`;
  const file = new File(folder(), name);
  if (file.exists) file.delete();
  run('VACUUM INTO ?', pathOf(file.uri));
  if (kind === 'auto') {
    for (const old of listBackups().filter((b) => b.auto).slice(KEEP_AUTO)) new File(old.uri).delete();
  }
  return listBackups().find((b) => b.name === name)!;
}

/** Once a day, when the app opens: a copy, if today has none yet. */
export function dailyBackup() {
  try {
    database();
    const today = localDate(new Date());
    if (!listBackups().some((b) => b.auto && b.name.startsWith(`restaurant-${today}`))) backupNow('auto');
  } catch (error) {
    console.warn('Daily backup failed', error);
  }
}

/** Whether a file is a SQLite database at all. */
function isDatabase(file: File): boolean {
  try {
    const head = file.bytesSync().slice(0, 16);
    return String.fromCharCode(...head) === SQLITE_HEADER;
  } catch {
    return false;
  }
}

/**
 * Puts a copy back in place of the database. The one there now is kept as a
 * backup first, and everyone signs in again.
 */
export async function restoreBackup(uri: string): Promise<void> {
  const source = new File(uri);
  if (!source.exists || !isDatabase(source) || !(await isRestaurant(source))) throw new Error('That file is not a Miles Restaurant backup. Nothing was changed.');
  const before = backupNow('before-restore');
  signOut();
  try {
    await replaceWith(source);
  } catch (error) {
    // The copy would not open: the restaurant as it was goes back.
    await replaceWith(new File(before.uri));
    throw error;
  }
}

/** The database file swapped for a copy, then opened (an older copy's tables brought up to date). */
async function replaceWith(source: File) {
  closeDatabase();
  for (const suffix of ['-wal', '-shm', '']) {
    const f = databaseFile(suffix);
    if (f.exists) f.delete();
  }
  await source.copy(databaseFile());
  forgetSettings();
  openDatabase();
}

const RESTAURANT_TABLES = ['menu_items', 'dining_tables', 'kitchen_tickets', 'till_shifts'];

/**
 * Whether a SQLite file holds a restaurant (not, say, a pharmacy's backup),
 * looked at in a scratch copy so the file itself is left as it is.
 */
async function isRestaurant(file: File): Promise<boolean> {
  const scratch = new File(Paths.cache, 'restore-check.db');
  let db: SQLiteDatabase | null = null;
  try {
    if (scratch.exists) scratch.delete();
    await file.copy(scratch);
    db = openDatabaseSync(scratch.name, undefined, pathOf(Paths.cache.uri));
    const found = db.getAllSync<{ name: string }>(
      `SELECT name FROM sqlite_master WHERE type = 'table' AND name IN (${RESTAURANT_TABLES.map(() => '?').join(', ')})`,
      RESTAURANT_TABLES,
    );
    return found.length === RESTAURANT_TABLES.length;
  } catch {
    return false;
  } finally {
    db?.closeSync();
    if (scratch.exists) scratch.delete();
  }
}

export function deleteBackup(uri: string) {
  const f = new File(uri);
  if (f.exists) f.delete();
}
