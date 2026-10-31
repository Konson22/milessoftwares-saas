import { readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { app } from 'electron';

import type { Mode } from '../shared/ipc';

/**
 * How this PC runs the pharmacy, chosen on first opening:
 *
 * - offline: the whole pharmacy in pharmacy.db on this PC (src/main/db), no
 *   internet and no account. One PC, one branch.
 * - hybrid: a Miles workspace. Sign in with the platform; the counter calls
 *   the workspace and keeps selling through a dropped connection (the outbox).
 *
 * Kept by the main process in userData/mode.json, out of reach of the
 * window's own store (userData/data): what the window may call depends on it.
 */

const file = () => join(app.getPath('userData'), 'mode.json');

let current: Mode | null | undefined;

export function getMode(): Mode | null {
  if (current !== undefined) return current;
  let saved: Mode | null = null;
  try {
    const mode = JSON.parse(readFileSync(file(), 'utf8')).mode;
    if (mode === 'offline' || mode === 'hybrid') saved = mode;
  } catch {
    // Not chosen yet.
  }
  current = saved;
  return saved;
}

/** Chosen, or null: ask again on the next opening. */
export function setMode(mode: Mode | null) {
  if (mode === null) {
    rmSync(file(), { force: true });
  } else {
    const tmp = `${file()}.tmp`;
    writeFileSync(tmp, JSON.stringify({ mode, chosen_at: new Date().toISOString() }), 'utf8');
    renameSync(tmp, file());
  }
  current = mode;
}
