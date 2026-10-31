import { scrypt } from '@noble/hashes/scrypt.js';
import { bytesToHex, hexToBytes } from '@noble/hashes/utils.js';
import { getRandomBytes } from 'expo-crypto';

import type { Me } from '@/lib/types';

import { all, one, run, transaction } from './database';
import { seedSample } from './seed';
import { branch, DEFAULT_SETTINGS, saveSettings, settings } from './settings';
import { bool, int, LocalError, now, text, uuid, Validation } from './util';

/**
 * Who works the restaurant on this phone: people with a username and
 * password, each with one of the restaurant's roles (restaurant-app
 * platform/catalog.json). The person signed in is kept in memory only, so
 * reopening the app asks again.
 */

export type Role = 'manager' | 'cashier' | 'waiter' | 'kitchen';

export const ROLES: { value: Role; label: string; text: string }[] = [
  { value: 'manager', label: 'Manager', text: 'Everything: the menu, tables, stock, voids, every shift, staff and settings.' },
  { value: 'cashier', label: 'Cashier', text: 'Takes orders and payment into their own shift.' },
  { value: 'waiter', label: 'Waiter', text: 'Seats tables, takes orders and sends them to the kitchen.' },
  { value: 'kitchen', label: 'Kitchen', text: 'Works the kitchen screen: tickets ready and served.' },
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

const PERMISSIONS: Record<Role, string[]> = {
  manager: [
    'restaurant.menu.view',
    'restaurant.menu.manage',
    'restaurant.tables.manage',
    'restaurant.orders.take',
    'restaurant.orders.void',
    'restaurant.kitchen.view',
    'restaurant.bills.view',
    'restaurant.bills.take',
    'restaurant.bills.void',
    'restaurant.tills.manage',
    'restaurant.stock.view',
    'restaurant.stock.adjust',
    // This phone's own: its people and the restaurant's details.
    'restaurant.users.manage',
    'restaurant.settings.manage',
  ],
  cashier: ['restaurant.menu.view', 'restaurant.orders.take', 'restaurant.bills.view', 'restaurant.bills.take'],
  waiter: ['restaurant.menu.view', 'restaurant.orders.take'],
  kitchen: ['restaurant.menu.view', 'restaurant.kitchen.view'],
};

export const permissionsOf = (role: Role) => PERMISSIONS[role] ?? [];

export const can = (user: UserRow, permission: string) => permissionsOf(user.role).includes(permission);

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

/** Node's scryptSync defaults. */
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
    organization: { id: 'local', slug: 'local', name: s.name, currency: s.currency, decimals: s.decimals },
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

export interface SetUpInput {
  restaurant_name: string;
  address?: string;
  phone?: string;
  currency: string;
  decimals: number | string;
  manager_name: string;
  username: string;
  password: string;
  password_confirmation: string;
  sample?: boolean;
}

/** First run: the restaurant, and its first manager, who is then signed in. */
export function setUp(input: SetUpInput): Me {
  if (hasUsers()) throw new LocalError('This restaurant is already set up.', 409);
  const v = new Validation();
  const restaurantName = text(input.restaurant_name);
  const currency = text(input.currency)?.toUpperCase() ?? null;
  const decimals = int(input.decimals);
  const managerName = text(input.manager_name);
  const username = text(input.username);
  if (!restaurantName) v.add('restaurant_name', 'Enter the restaurant’s name.');
  if (!currency || !/^[A-Z]{3}$/.test(currency)) v.add('currency', 'Three letters, as SSP, KES or USD.');
  if (decimals === null || decimals < 0 || decimals > 3) v.add('decimals', 'From 0 to 3.');
  if (!managerName) v.add('manager_name', 'Enter your name.');
  checkUsername(v, username, null);
  checkPassword(v, 'password', input.password);
  if (input.password !== input.password_confirmation) v.add('password_confirmation', 'The two passwords differ.');
  v.check();

  const id = uuid();
  transaction(() => {
    saveSettings({
      ...DEFAULT_SETTINGS,
      name: restaurantName!,
      address: text(input.address),
      phone: text(input.phone),
      currency: currency!,
      decimals: decimals!,
    });
    run(
      'INSERT INTO users (id, name, username, role, password_hash, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)',
      id,
      managerName!,
      username!,
      'manager',
      hashPassword(input.password),
      now(),
    );
    if (bool(input.sample)) seedSample(id);
  });
  currentUserId = id;
  return me();
}

export function activeManagers(): number {
  return all<{ id: string }>("SELECT id FROM users WHERE role = 'manager' AND active = 1").length;
}
