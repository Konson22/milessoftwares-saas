import type { CallResult } from '../shared/ipc';
import { backupNow, exportBackup, listBackups, openBackupFolder, restoreBackup } from './backup';
import * as counter from './db/counter';
import { isDatabaseOpen, openDatabase } from './db/database';
import { act, getPage } from './db/pages';
import { hasUsers, me, setUp, signedInUser, signIn, signOut } from './db/session';
import { settings } from './db/settings';
import { LocalError } from './db/util';
import { activate, activateKey, licenceState, openLicenceFile, requireLicence } from './licence';
import { getMode } from './mode';
import { checkPrinter, listPrinters, printerSettings, printTestPage, savePrinterSettings } from './printer';
import { openDataFolder, systemInfo } from './system';

/**
 * What the window may ask of the pharmacy on this PC, by name
 * (window.desktop.call), in offline mode only. Each answers a value, or an
 * error the window shows as it is.
 */
const handlers: Record<string, (...args: any[]) => unknown> = {
  /** On opening: whether the pharmacy is set up, and who is signed in (kept while the app runs). */
  state: () => {
    const user = signedInUser();
    return { setUp: hasUsers(), me: user ? me(user) : null };
  },
  setUp,
  signIn,
  signOut,
  me: () => me(),

  counter: counter.counter,
  items: counter.items,
  itemByCode: counter.findByCode,
  till: counter.till,
  openTill: counter.openTill,
  closeTill: counter.closeTill,
  moveCash: counter.moveCash,
  expenseCategories: counter.expenseCategories,
  sell: counter.sell,
  sales: counter.sales,
  sale: counter.sale,
  printed: counter.printed,
  voidSale: counter.voidSale,
  returnSale: counter.returnSale,
  prescriptions: counter.prescriptions,
  prescription: counter.prescription,
  recordPrescription: counter.recordPrescription,
  verifyPrescription: counter.verifyPrescription,
  rejectPrescription: counter.rejectPrescription,
  dispense: counter.dispense,

  page: getPage,
  act,

  'backup.list': () => (requireSettings(), listBackups()),
  'backup.now': () => (requireSettings(), backupNow('manual')),
  'backup.export': () => (requireSettings(), exportBackup()),
  'backup.restore': (path?: string) => (requireSettings(), restoreBackup(path)),
  'backup.openFolder': () => (requireSettings(), openBackupFolder()),

  'licence.state': () => licenceState(),
  'licence.activate': (code: unknown) => (requireSignedIn(), activate(code)),
  'licence.activateKey': (key: unknown) => (requireSignedIn(), activateKey(key)),
  'licence.openFile': () => (requireSignedIn(), openLicenceFile()),

  'printer.get': () => (requireSettings(), printerSettings()),
  'printer.list': () => (requireSettings(), listPrinters()),
  'printer.save': (data: unknown) => (requireSettings(), savePrinterSettings(data)),
  /** With settings: as being set up, before saving. */
  'printer.check': (data?: unknown) => (requireSettings(), checkPrinter(data)),
  'printer.test': (data?: unknown) => (requireSettings(), printTestPage(settings().name, settings().paper_size, data)),

  'system.info': () => (requireSettings(), systemInfo()),
  'system.openDataFolder': () => (requireSettings(), openDataFolder()),
};

/** Changes to the pharmacy: refused once the trial or licence has ended (reading goes on). */
const WRITES = new Set([
  'openTill',
  'closeTill',
  'moveCash',
  'sell',
  'voidSale',
  'returnSale',
  'recordPrescription',
  'verifyPrescription',
  'rejectPrescription',
  'dispense',
  'act',
]);

function requireSignedIn() {
  const user = signedInUser();
  if (!user) throw new LocalError('Sign in again.', 401, {}, 'session_required');
  return user;
}

/** Backups, the printer and the system are the managers' (Settings). */
function requireSettings() {
  if (requireSignedIn().role !== 'manager') throw new LocalError('You may not do that.', 403);
}

export async function handle(name: unknown, args: unknown): Promise<CallResult> {
  const handler = typeof name === 'string' && Object.hasOwn(handlers, name) ? handlers[name] : null;
  if (!handler) return { ok: false, error: { message: `Unknown call ${String(name)}.`, status: 404, errors: {}, code: null } };
  const mode = getMode();
  if (mode !== 'offline') {
    const message = mode === 'hybrid' ? 'This PC works with a Miles workspace, not a pharmacy of its own.' : 'Choose how this PC runs the pharmacy first.';
    return { ok: false, error: { message, status: 409, errors: {}, code: 'mode' } };
  }
  try {
    // Opened on the first call: a PC that works with a workspace never makes one.
    if (!isDatabaseOpen()) openDatabase();
    if (WRITES.has(name as string)) requireLicence();
    return { ok: true, value: await handler(...(Array.isArray(args) ? args : [])) };
  } catch (error) {
    if (error instanceof LocalError) {
      return { ok: false, error: { message: error.message, status: error.status, errors: error.errors, code: error.code } };
    }
    console.error(`Call ${name} failed`, error);
    return { ok: false, error: { message: error instanceof Error ? error.message : 'Something went wrong.', status: 500, errors: {}, code: null } };
  }
}
