import { copyFile, mkdir, readdir, rm, stat } from 'node:fs/promises';
import { basename, join } from 'node:path';
import { DatabaseSync } from 'node:sqlite';

import { app, BrowserWindow, dialog, shell } from 'electron';

import { closeDatabase, database, databasePath, openDatabase } from './db/database';
import { SCHEMA_VERSION } from './db/schema';
import { signOut } from './db/session';
import { forgetSettings, getValue, setValue } from './db/settings';
import { LocalError } from './db/util';

/**
 * The pharmacy lives on this one PC, so it is copied: once a day on its own
 * (the last 14 kept), whenever someone asks, and to a USB stick or another
 * folder. A copy is a whole database: restoring one puts it in place.
 */

export interface BackupFile {
  name: string;
  path: string;
  size: number;
  at: string;
  kind: 'auto' | 'manual' | 'before-restore';
}

const KEEP_AUTO = 14;
const AUTO_EVERY_MS = 20 * 60 * 60 * 1000;

export const backupDir = () => join(app.getPath('userData'), 'backups');

const stamp = (d = new Date()) => d.toISOString().replace(/[-:]/g, '').replace('T', '-').slice(0, 15);

/** A consistent copy of the open database (VACUUM INTO also folds in the WAL). */
function copyTo(path: string) {
  database().prepare('VACUUM INTO ?').run(path);
}

export async function backupNow(kind: BackupFile['kind'] = 'manual'): Promise<BackupFile> {
  await mkdir(backupDir(), { recursive: true });
  const path = join(backupDir(), `pharmacy-${stamp()}-${kind}.db`);
  await rm(path, { force: true });
  copyTo(path);
  const at = new Date().toISOString();
  setValue('last_backup_at', at);
  if (kind === 'auto') {
    setValue('last_auto_backup_at', at);
    await prune();
  }
  return describe(path);
}

async function describe(path: string): Promise<BackupFile> {
  const info = await stat(path);
  const name = basename(path);
  const kind = name.includes('-auto') ? 'auto' : name.includes('-before-restore') ? 'before-restore' : 'manual';
  return { name, path, size: info.size, at: info.mtime.toISOString(), kind };
}

export async function listBackups(): Promise<{ folder: string; lastBackupAt: string | null; files: BackupFile[] }> {
  let names: string[] = [];
  try {
    names = (await readdir(backupDir())).filter((n) => n.endsWith('.db'));
  } catch {
    // No backups yet.
  }
  const files = await Promise.all(names.map((n) => describe(join(backupDir(), n))));
  return { folder: backupDir(), lastBackupAt: getValue('last_backup_at'), files: files.sort((a, b) => b.at.localeCompare(a.at)) };
}

async function prune() {
  const autos = (await listBackups()).files.filter((f) => f.kind === 'auto');
  for (const old of autos.slice(KEEP_AUTO)) await rm(old.path, { force: true });
}

/** On opening, and every hour while open: a copy when the last is a day old. */
export async function autoBackupIfDue() {
  const last = getValue('last_auto_backup_at');
  if (last && Date.now() - Date.parse(last) < AUTO_EVERY_MS) return;
  try {
    await backupNow('auto');
  } catch (error) {
    console.error('Automatic backup failed', error);
  }
}

const window = () => BrowserWindow.getFocusedWindow() ?? BrowserWindow.getAllWindows()[0];

/** A copy saved where the person chooses (a USB stick, another drive). */
export async function exportBackup(): Promise<string | null> {
  const { canceled, filePath } = await dialog.showSaveDialog(window(), {
    title: 'Save a copy of the pharmacy',
    defaultPath: join(app.getPath('documents'), `pharmacy-${stamp()}.db`),
    filters: [{ name: 'Pharmacy backup', extensions: ['db'] }],
  });
  if (canceled || !filePath) return null;
  await rm(filePath, { force: true });
  copyTo(filePath);
  setValue('last_backup_at', new Date().toISOString());
  return filePath;
}

/** Checks a file is a pharmacy this app can open. */
function inspect(path: string) {
  let db: DatabaseSync | null = null;
  try {
    db = new DatabaseSync(path, { readOnly: true });
    const { user_version: version } = db.prepare('PRAGMA user_version').get() as { user_version: number };
    const users = db.prepare("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name IN ('users', 'medicines', 'sales')").get() as { n: number };
    if (users.n !== 3 || version < 1) throw new LocalError('This file is not a Miles Pharmacy backup.', 422);
    if (version > SCHEMA_VERSION) throw new LocalError('This backup was made by a newer version of the app. Update the app first.', 422);
  } catch (error) {
    if (error instanceof LocalError) throw error;
    throw new LocalError('This file is not a Miles Pharmacy backup.', 422);
  } finally {
    db?.close();
  }
}

/**
 * Puts a backup in place of the pharmacy on this PC. What is here now is
 * kept first as a "before-restore" copy, so a wrong choice can be undone.
 * Everyone is signed out; the window reloads.
 */
export async function restoreBackup(fromPath?: string): Promise<boolean> {
  let path = fromPath;
  if (!path) {
    const { canceled, filePaths } = await dialog.showOpenDialog(window(), {
      title: 'Restore the pharmacy from a backup',
      defaultPath: backupDir(),
      filters: [{ name: 'Pharmacy backup', extensions: ['db'] }],
      properties: ['openFile'],
    });
    if (canceled || !filePaths[0]) return false;
    path = filePaths[0];
  }
  inspect(path);

  // A copy of the restore source first: it may be one of the backups pruned below.
  const staged = join(app.getPath('temp'), `pharmacy-restore-${stamp()}.db`);
  await copyFile(path, staged);
  await backupNow('before-restore');

  const target = databasePath();
  closeDatabase();
  try {
    await rm(`${target}-wal`, { force: true });
    await rm(`${target}-shm`, { force: true });
    await copyFile(staged, target);
  } finally {
    forgetSettings();
    openDatabase();
    await rm(staged, { force: true });
  }
  signOut();
  return true;
}

export const openBackupFolder = async () => {
  await mkdir(backupDir(), { recursive: true });
  await shell.openPath(backupDir());
};
