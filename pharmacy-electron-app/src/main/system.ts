import { statSync } from 'node:fs';
import { arch, release, type } from 'node:os';

import { app, shell } from 'electron';

import { databasePath } from './db/database';
import { SCHEMA_VERSION } from './db/schema';
import { getMode } from './mode';

/** The app and this PC, for Settings, System (and for support on the phone). */
export function systemInfo() {
  let databaseSize: number | null = null;
  try {
    databaseSize = statSync(databasePath()).size;
  } catch {
    // Not made yet.
  }
  return {
    version: app.getVersion(),
    mode: getMode(),
    os: `${type()} ${release()} (${arch()})`,
    electron: process.versions.electron,
    dataFolder: app.getPath('userData'),
    databasePath: databasePath(),
    databaseSize,
    schemaVersion: SCHEMA_VERSION,
  };
}

export const openDataFolder = () => shell.openPath(app.getPath('userData'));
