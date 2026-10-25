import { scrypt } from '@noble/hashes/scrypt.js';
import { bytesToHex, hexToBytes } from '@noble/hashes/utils.js';
import { getRandomBytes } from 'expo-crypto';

import type { Me } from '@/lib/types';
import { all, one, run, transaction } from './database';
import { seedDemo } from './seed';
import { branch, DEFAULT_SETTINGS, money, saveSettings, settings, type PharmacySettings } from './settings';
import { bool, int, LocalError, now, text, toMinor, uuid, Validation } from './util';

/**
 * Who works the counter: people with a username and password on this phone,
 * each with one role. The person signed in is kept in memory only, so a
 * restart asks again.
 */

export type Role = 'manager' | 'pharmacist' | 'cashier';
export const ROLES: { value: Role; label: string }[] = [
  { value: 'manager', label: 'Manager' },
  { value: 'pharmacist', label: 'Pharmacist' },
  { value: 'cashier', label: 'Cashier' },
];

export interface UserRow {
  id: string;
  name: string;
  username: string;
  role: Role;
  password_hash: string;
  active: number;
  created_at: string;
}

const CASHIER = [
  'pharmacy.sales.create',
  'pharmacy.prescriptions.view',
  'pharmacy.prescriptions.record',
  'pharmacy.stock.view',
  'pharmacy.medicines.view',
];
const PHARMACIST = [
  ...CASHIER,
  'pharmacy.sales.discount',
  'pharmacy.prescriptions.verify',
  'pharmacy.stock.adjust',
  'pharmacy.stock.hold',
  'pharmacy.medicines.manage',
  'pharmacy.purchasing.view',
  'pharmacy.purchasing.receive',
  'pharmacy.purchasing.manage',
];
const MANAGER = [
  ...PHARMACIST,
  'pharmacy.sales.view',
  'pharmacy.sales.void',
  'pharmacy.tills.manage',
  'pharmacy.reports.view',
  'pharmacy.expenses.manage',
  'pharmacy.users.manage',
  'pharmacy.settings.manage',
];

const PERMISSIONS: Record<Role, string[]> = { cashier: CASHIER, pharmacist: PHARMACIST, manager: MANAGER };

export const permissionsOf = (role: Role) => PERMISSIONS[role];

export function hasPermission(user: UserRow, permission: string) {
  return PERMISSIONS[user.role].includes(permission);
}

let currentUserId: string | null = null;

export function currentUser(): UserRow {
  const user = currentUserId ? one<UserRow>('SELECT * FROM users WHERE id = ? AND active = 1', currentUserId) : null;
  if (!user) {
    currentUserId = null;
    throw new LocalError('Sign in again.', 401, {}, 'session_required');
  }
  return user;
}

export function signedInUser(): UserRow | null {
  try {
    return currentUser();
  } catch {
    return null;
  }
}

/** Node's scryptSync defaults, so a hash made by the desktop app checks here too. */
const SCRYPT = { N: 16384, r: 8, p: 1 };

export function hashPassword(password: string): string {
  const salt = getRandomBytes(16);
  const hash = scrypt(password, salt, { ...SCRYPT, dkLen: 64 });
  return `scrypt$${bytesToHex(salt)}$${bytesToHex(hash)}`;
}

function verifyPassword(password: string, stored: string): boolean {
  const [scheme, salt, hash] = stored.split('$');
  if (scheme !== 'scrypt' || !salt || !hash) return false;
  const expected = hexToBytes(hash);
  const actual = scrypt(password, hexToBytes(salt), { ...SCRYPT, dkLen: expected.length });
  // Constant time: every byte compared, whatever differs.
  let diff = actual.length ^ expected.length;
  for (let i = 0; i < expected.length; i++) diff |= actual[i] ^ expected[i];
  return diff === 0;
}

export function me(user: UserRow = currentUser()): Me {
  const s = settings();
  return {
    user: { id: user.id, name: user.name, username: user.username, role: user.role },
    organization: { id: 'local', slug: 'local', name: s.name, currency: s.currency, decimals: s.decimals, second_currency: money().foreign },
    is_owner: user.role === 'manager',
    branches: [branch()],
    reason: null,
  };
}

export function hasUsers(): boolean {
  return one<{ n: number }>('SELECT COUNT(*) AS n FROM users')!.n > 0;
}

export function signIn(username: unknown, password: unknown): Me {
  const name = text(username);
  const secret = typeof password === 'string' ? password : '';
  const user = name ? one<UserRow>('SELECT * FROM users WHERE username = ?', name) : null;
  if (!user || !verifyPassword(secret, user.password_hash)) {
    throw new LocalError('That username and password do not match.', 422, { password: ['That username and password do not match.'] });
  }
  if (!user.active) throw new LocalError('This account is switched off. Ask a manager.', 403);
  currentUserId = user.id;
  return me(user);
}

export function signOut() {
  currentUserId = null;
}

export const MIN_PASSWORD = 6;

export function checkPassword(v: Validation, field: string, password: unknown) {
  if (typeof password !== 'string' || password.length < MIN_PASSWORD) v.add(field, `At least ${MIN_PASSWORD} characters.`);
}

export function checkUsername(v: Validation, username: string | null, exceptId: string | null) {
  if (!username) return v.add('username', 'Enter a username.');
  if (!/^[a-zA-Z0-9._-]{3,32}$/.test(username)) return v.add('username', 'Letters, digits, dots, dashes; 3 to 32 of them.');
  const taken = one<{ id: string }>('SELECT id FROM users WHERE username = ?', username);
  if (taken && taken.id !== exceptId) v.add('username', 'Someone already has this username.');
}

export interface SetupInput {
  pharmacy_name: string;
  address?: string;
  contact?: string;
  currency: string;
  decimals: number | string;
  manager_name: string;
  username: string;
  password: string;
  password_confirmation: string;
  demo?: boolean;
  [key: string]: unknown;
}

/** First run: the pharmacy, and its first manager, who is then signed in. */
export function setUp(input: SetupInput): Me {
  if (hasUsers()) throw new LocalError('This pharmacy is already set up.', 409);
  const v = new Validation();
  const pharmacyName = text(input.pharmacy_name);
  const currency = text(input.currency)?.toUpperCase() ?? null;
  const decimals = int(input.decimals);
  const managerName = text(input.manager_name);
  const username = text(input.username);
  if (!pharmacyName) v.add('pharmacy_name', 'Enter the pharmacy’s name.');
  if (!currency || !/^[A-Z]{3}$/.test(currency)) v.add('currency', 'Three letters, as SSP, KES or USD.');
  if (decimals === null || decimals < 0 || decimals > 3) v.add('decimals', 'From 0 to 3.');
  if (!managerName) v.add('manager_name', 'Enter your name.');
  checkUsername(v, username, null);
  checkPassword(v, 'password', input.password);
  if (input.password !== input.password_confirmation) v.add('password_confirmation', 'The two passwords differ.');
  v.check();

  const next: PharmacySettings = {
    ...DEFAULT_SETTINGS,
    name: pharmacyName!,
    address: text(input.address),
    contact: text(input.contact),
    currency: currency!,
    decimals: decimals!,
    second_currency: currency === 'USD' ? null : 'USD',
    default_float_minor: toMinor('0', decimals!) ?? 0,
  };

  const id = uuid();
  transaction(() => {
    saveSettings(next);
    run(
      'INSERT INTO users (id, name, username, role, password_hash, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)',
      id,
      managerName!,
      username!,
      'manager',
      hashPassword(input.password),
      now(),
    );
    if (bool(input.demo)) seedDemo(id, decimals!);
  });
  currentUserId = id;
  return me();
}

export function activeManagers(): number {
  return all<{ id: string }>("SELECT id FROM users WHERE role = 'manager' AND active = 1").length;
}
