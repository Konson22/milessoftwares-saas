import type { Mode } from '../../../shared/ipc';

/**
 * How this PC runs the pharmacy (src/main/mode.ts), chosen on first opening:
 *
 * - offline: the whole pharmacy in a database on this PC, no internet and no
 *   account. Usernames and passwords live in that database.
 * - hybrid: a Miles workspace. Sign in with the platform; the counter calls
 *   the workspace and keeps working through a dropped connection
 *   (src/offline).
 *
 * The main process keeps it; changed from the sign-in screen. Each mode keeps
 * its own data. Screens read it from the auth store (useAuth mode); calls and
 * pages ask here.
 */
export type { Mode };

let current: Mode | null = null;

/** The mode the calls go to now (null before it is chosen). */
export const currentMode = () => current;

export const isLocal = () => current === 'offline';

/** The mode saved on this PC, or null when none was chosen yet. */
export async function loadMode(): Promise<Mode | null> {
  current = await window.desktop.getMode();
  return current;
}

/** Chosen (or null: ask again). */
export async function saveMode(mode: Mode | null) {
  await window.desktop.setMode(mode);
  current = mode;
}
