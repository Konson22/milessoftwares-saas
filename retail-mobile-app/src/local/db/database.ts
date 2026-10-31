import { defaultDatabaseDirectory, openDatabaseSync, type SQLiteBindValue, type SQLiteDatabase } from 'expo-sqlite';

import { migrate } from './schema';

/**
 * The shop's one database: a SQLite file on this phone (shop.db).
 * Everything the app knows lives here; there is no server.
 */

export const DATABASE_NAME = 'shop.db';

let db: SQLiteDatabase | null = null;

export const databaseDirectory = () => defaultDatabaseDirectory;

export function openDatabase() {
  if (db) return;
  db = openDatabaseSync(DATABASE_NAME);
  // WAL: a power cut mid-write never leaves the file half-written.
  db.execSync('PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
  migrate(db);
}

export function closeDatabase() {
  db?.closeSync();
  db = null;
}

export function database(): SQLiteDatabase {
  if (!db) openDatabase();
  return db!;
}

export type Param = string | number | bigint | null | undefined | Uint8Array | boolean;

/** expo-sqlite binds no undefined, and no bigint. */
const bind = (params: Param[]): SQLiteBindValue[] =>
  params.map((p) => (p === undefined ? null : typeof p === 'bigint' ? Number(p) : p));

export const all = <T>(sql: string, ...params: Param[]) => database().getAllSync<T>(sql, bind(params));
export const one = <T>(sql: string, ...params: Param[]) => database().getFirstSync<T>(sql, bind(params)) ?? null;
export const run = (sql: string, ...params: Param[]) => database().runSync(sql, bind(params));

let depth = 0;

/** All or nothing: a sale takes every line's stock, or none of it. Nested calls join the outer one. */
export function transaction<T>(task: () => T): T {
  if (depth > 0) return task();
  database().execSync('BEGIN IMMEDIATE');
  depth++;
  try {
    const result = task();
    database().execSync('COMMIT');
    return result;
  } catch (error) {
    database().execSync('ROLLBACK');
    throw error;
  } finally {
    depth--;
  }
}

/** The next number of a kind (S-000001, SH-00001…), never reused. */
export function nextNumber(kind: string, prefix: string, digits: number): string {
  run('INSERT INTO sequences (name, value) VALUES (?, 1) ON CONFLICT(name) DO UPDATE SET value = value + 1', kind);
  const { value } = one<{ value: number }>('SELECT value FROM sequences WHERE name = ?', kind)!;
  return `${prefix}-${String(value).padStart(digits, '0')}`;
}
