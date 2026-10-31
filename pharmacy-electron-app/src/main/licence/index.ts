import { randomBytes, randomUUID } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { app, BrowserWindow, dialog, safeStorage } from 'electron';

import { AUTH_URL, SITE_URL } from '../config';
import { getValue, setValue, settings } from '../db/settings';
import { LocalError } from '../db/util';
import { send } from '../http';
import { checkLicence, CodeError, decodeLicence, formatCode, type CodeProblem, type Licence } from './codes';
import { deviceCode } from './device';
import { mergeTrial, seen, trialStatus, type TrialRecord } from './trial';

/**
 * Whether this PC may sell: a trial still running, or a licence for this PC
 * and this pharmacy. Otherwise the pharmacy can still be read (reports,
 * backups, exports) but not changed. Data is never deleted or locked away.
 */

export type LicenceStatus =
  | { status: 'trial'; daysLeft: number; endsAt: string; problem: CodeProblem | null }
  | { status: 'licensed'; licence: Licence }
  | { status: 'expired'; endedAt: string; what: 'trial' | 'licence' }
  | { status: 'invalid'; problem: CodeProblem }
  | { status: 'clock'; lastSeenAt: string };

/** activateUrl: the site's activation page with this PC's codes filled in, for a phone (the QR code). */
export type LicenceState = LicenceStatus & { deviceCode: string; pharmacyId: string; activateUrl: string };

/** This pharmacy (its database): made once, carried by its backups. */
interface Installation {
  id: string;
  branch: string;
  created_at: string;
}

function installation(): Installation {
  const saved = getValue('installation');
  if (saved) return JSON.parse(saved) as Installation;
  const made = { id: randomUUID(), branch: formatCode(randomBytes(8), 8), created_at: new Date().toISOString() };
  setValue('installation', JSON.stringify(made));
  return made;
}

// The trial's second copy, encrypted by the OS: out of the database, so a new database does not start a new trial.
const trialFile = () => join(app.getPath('userData'), 'licence.dat');

function readTrialFile(): TrialRecord | null {
  try {
    const raw = readFileSync(trialFile());
    return JSON.parse(safeStorage.isEncryptionAvailable() ? safeStorage.decryptString(raw) : raw.toString('utf8')) as TrialRecord;
  } catch {
    return null;
  }
}

function writeTrialFile(record: TrialRecord) {
  const text = JSON.stringify(record);
  try {
    writeFileSync(trialFile(), safeStorage.isEncryptionAvailable() ? safeStorage.encryptString(text) : text);
  } catch (error) {
    console.error('The trial could not be written', error);
  }
}

function readTrialRow(): TrialRecord | null {
  try {
    return JSON.parse(getValue('trial') ?? 'null') as TrialRecord | null;
  } catch {
    return null;
  }
}

let fileRecord: TrialRecord | null | undefined;

/** Both copies, merged, then written back to both when they moved (at most once a minute). */
function trial(now: Date): TrialRecord {
  if (fileRecord === undefined) fileRecord = readTrialFile();
  const row = readTrialRow();
  const merged = mergeTrial(row, fileRecord) ?? { started_at: now.toISOString(), last_seen_at: now.toISOString() };
  // Not seen now if the clock went back: the mark stays where it was.
  const next = trialStatus(merged, now).kind === 'clock' ? merged : seen(merged, now);
  const stale = (r: TrialRecord | null | undefined) =>
    !r || r.started_at !== next.started_at || Date.parse(next.last_seen_at) - Date.parse(r.last_seen_at) > 60_000;
  if (stale(row)) setValue('trial', JSON.stringify(next));
  if (stale(fileRecord)) {
    writeTrialFile(next);
    fileRecord = next;
  }
  return next;
}

function storedLicence(): Licence | null {
  const code = getValue('licence');
  if (!code) return null;
  try {
    return decodeLicence(code);
  } catch {
    return null;
  }
}

function activateUrl(device: string, pharmacy: string): string {
  const query = new URLSearchParams({ device, pharmacy, name: settings().name });
  return `${SITE_URL}/activate?${query}`;
}

export function licenceState(now = new Date()): LicenceState {
  const codes = { deviceCode: deviceCode(), pharmacyId: installation().branch };
  const here = { ...codes, activateUrl: activateUrl(codes.deviceCode, codes.pharmacyId) };
  const record = trial(now);
  const status = trialStatus(record, now);
  if (status.kind === 'clock') return { status: 'clock', lastSeenAt: status.lastSeenAt, ...here };

  const licence = storedLicence();
  const problem = licence ? checkLicence(licence, { device: here.deviceCode, branch: here.pharmacyId, now }) : null;
  if (licence && !problem) return { status: 'licensed', licence, ...here };
  if (status.kind === 'running') return { status: 'trial', daysLeft: status.daysLeft, endsAt: status.endsAt, problem, ...here };
  if (licence && problem === 'expired') return { status: 'expired', endedAt: licence.expires!, what: 'licence', ...here };
  if (licence && problem) return { status: 'invalid', problem, ...here };
  return { status: 'expired', endedAt: status.endsAt, what: 'trial', ...here };
}

const PROBLEMS: Record<CodeProblem, string> = {
  format: 'This is not an activation code. Paste the whole code, as it was sent.',
  signature: 'This code was not issued by Miles Softwares, or it was changed.',
  device: 'This code is for another PC. Send this PC’s device code to get one for it.',
  branch: 'This code is for another pharmacy. Send this pharmacy’s ID to get one for it.',
  expired: 'This code has expired.',
};

/** A code pasted: kept when it opens this PC. */
export function activate(code: unknown): LicenceState {
  const pasted = typeof code === 'string' ? code.trim() : '';
  if (!pasted) throw new LocalError('Paste the activation code.', 422, { code: ['Paste the activation code.'] });
  const here = { device: deviceCode(), branch: installation().branch, now: new Date() };
  let problem: CodeProblem | null;
  try {
    problem = checkLicence(decodeLicence(pasted), here);
  } catch (error) {
    problem = error instanceof CodeError ? error.problem : 'format';
  }
  if (problem) throw new LocalError(PROBLEMS[problem], 422, { code: [PROBLEMS[problem]] });
  setValue('licence', pasted.replace(/\s+/g, ''));
  return licenceState();
}

/**
 * A licence key (MILES-XXXX-XXXX-XXXX) bought or given: sent to Miles
 * Softwares with this PC's codes, once, for the activation code kept here.
 */
export async function activateKey(key: unknown): Promise<LicenceState> {
  const typed = typeof key === 'string' ? key.trim() : '';
  if (!typed) throw new LocalError('Enter the licence key.', 422, { key: ['Enter the licence key.'] });

  const response = await send(`${AUTH_URL}/api/v1/licences/activate`, 'POST', {}, {
    key: typed,
    device: deviceCode(),
    pharmacy: installation().branch,
    name: settings().name,
    app_version: app.getVersion(),
  });
  if (response.offline) {
    const message = 'Miles Softwares could not be reached. Connect this PC to the internet (a phone’s hotspot is enough) and try again, or activate by phone below.';
    throw new LocalError(message, 503, { key: [message] }, 'offline');
  }
  if (response.status === 422 || response.status === 429) {
    const message =
      response.status === 429 ? 'Too many tries. Wait a minute, then try again.' : String(response.json?.errors?.key?.[0] ?? response.json?.message ?? 'This key did not activate.');
    throw new LocalError(message, 422, { key: [message] }, response.json?.reason ?? null);
  }
  if (response.status !== 200 || typeof response.json?.code !== 'string') {
    throw new LocalError(`Activation did not work just now (${response.status}). Try again in a moment, or contact Miles Softwares.`, 502);
  }
  return activate(response.json.code);
}

/** A licence file (from the activation page or its email), carried here on a USB stick. */
export async function openLicenceFile(): Promise<LicenceState | null> {
  const window = BrowserWindow.getFocusedWindow() ?? BrowserWindow.getAllWindows()[0];
  const options = { title: 'Open a licence file', filters: [{ name: 'Licence file', extensions: ['lic', 'txt'] }], properties: ['openFile' as const] };
  const picked = window ? await dialog.showOpenDialog(window, options) : await dialog.showOpenDialog(options);
  if (picked.canceled || picked.filePaths.length === 0) return null;
  let content: string;
  try {
    content = readFileSync(picked.filePaths[0], 'utf8');
  } catch {
    throw new LocalError('That file could not be read.', 422);
  }
  if (content.length > 10_000) throw new LocalError('That is not a licence file.', 422, { code: ['That is not a licence file.'] });
  return activate(content);
}

/** Before any change to the pharmacy. */
export function requireLicence() {
  const state = licenceState();
  if (state.status === 'trial' || state.status === 'licensed') return;
  const message =
    state.status === 'clock'
      ? 'This PC’s clock is behind the last time the app was used. Correct the date and time, then try again.'
      : state.status === 'invalid'
        ? 'The activation code is for another PC or pharmacy. Enter a code for this one under Settings, Activation.'
        : `The ${state.what === 'trial' ? 'free trial' : 'licence'} has ended. Enter an activation code under Settings, Activation. Everything stays readable.`;
  throw new LocalError(message, 403, {}, 'licence_required');
}
