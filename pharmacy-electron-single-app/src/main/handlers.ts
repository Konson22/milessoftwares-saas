import type { CallResult } from '../shared/ipc';
import { backupNow, exportBackup, listBackups, openBackupFolder, restoreBackup } from './backup';
import * as counter from './db/counter';
import { act, getPage } from './db/pages';
import { hasUsers, me, setUp, signedInUser, signIn, signOut } from './db/session';
import { LocalError } from './db/util';

/**
 * What the window may ask of the pharmacy, by name (window.desktop.call).
 * Each answers a value, or an error the window shows as it is.
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
};

/** Backups are the managers' (Settings). */
function requireSettings() {
  const user = signedInUser();
  if (!user) throw new LocalError('Sign in again.', 401, {}, 'session_required');
  if (user.role !== 'manager') throw new LocalError('You may not do that.', 403);
}

export async function handle(name: unknown, args: unknown): Promise<CallResult> {
  const handler = typeof name === 'string' && Object.hasOwn(handlers, name) ? handlers[name] : null;
  if (!handler) return { ok: false, error: { message: `Unknown call ${String(name)}.`, status: 404, errors: {}, code: null } };
  try {
    return { ok: true, value: await handler(...(Array.isArray(args) ? args : [])) };
  } catch (error) {
    if (error instanceof LocalError) {
      return { ok: false, error: { message: error.message, status: error.status, errors: error.errors, code: error.code } };
    }
    console.error(`Call ${name} failed`, error);
    return { ok: false, error: { message: error instanceof Error ? error.message : 'Something went wrong.', status: 500, errors: {}, code: null } };
  }
}
