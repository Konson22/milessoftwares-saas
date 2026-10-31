import { copyFileSync, cpSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { app } from 'electron';

import { databasePath } from './db/database';
import { getMode, setMode } from './mode';

/**
 * Miles Pharmacy Offline was a separate app until 1.1, with its own folder
 * (%APPDATA%\Miles Pharmacy Offline). On the first opening after the update,
 * its pharmacy is copied here: the database, its backups and the cart in
 * progress. The old folder is left as it was, so nothing is lost if
 * something goes wrong; it can be deleted by hand once all is well.
 */

const LEGACY_NAME = 'Miles Pharmacy Offline';
const MARKER = 'legacy-import.json';

export const legacyDir = () => join(app.getPath('appData'), LEGACY_NAME);

/** Copies the old offline app's pharmacy, once, when there is none here. */
export function importLegacyOffline() {
  const here = app.getPath('userData');
  const marker = join(here, MARKER);
  const from = legacyDir();
  if (existsSync(marker) || from === here || !existsSync(join(from, 'pharmacy.db')) || existsSync(databasePath())) return;

  mkdirSync(here, { recursive: true });
  // The WAL holds the latest writes until a checkpoint: it comes too.
  for (const suffix of ['', '-wal', '-shm']) {
    const source = join(from, `pharmacy.db${suffix}`);
    if (existsSync(source)) copyFileSync(source, `${databasePath()}${suffix}`);
  }
  if (existsSync(join(from, 'backups'))) cpSync(join(from, 'backups'), join(here, 'backups'), { recursive: true, force: false, errorOnExist: false });
  // The cart in progress and held carts, unless this PC already has the online app's.
  const cart = join('data', 'cart.json');
  if (existsSync(join(from, cart)) && !existsSync(join(here, cart))) {
    mkdirSync(join(here, 'data'), { recursive: true });
    copyFileSync(join(from, cart), join(here, cart));
  }
  writeFileSync(marker, JSON.stringify({ from, at: new Date().toISOString() }), 'utf8');
}

/**
 * Installs from before the choice existed open where they were: a pharmacy
 * on this PC opens offline, a workspace sign-in hybrid. With both, or
 * neither, the app asks.
 */
export function defaultMode() {
  if (getMode() !== null) return;
  const local = existsSync(databasePath());
  const online = existsSync(join(app.getPath('userData'), 'data', 'session.json'));
  if (local && !online) setMode('offline');
  else if (online && !local) setMode('hybrid');
}
