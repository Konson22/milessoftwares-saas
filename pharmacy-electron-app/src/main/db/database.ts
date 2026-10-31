import { join } from 'node:path';
import { DatabaseSync, type SQLInputValue, type StatementSync } from 'node:sqlite';

import { app } from 'electron';

import { migrate } from './schema';

/**
 * The pharmacy's one database: a SQLite file in the app's user-data folder
 * (pharmacy.db). Everything the app knows lives here; there is no server.
 * Only the main process opens it; the window asks through IPC.
 */

let db: DatabaseSync | null = null;
const statements = new Map<string, StatementSync>();

export const databasePath = () => join(app.getPath('userData'), 'pharmacy.db');

export function openDatabase(path = databasePath()) {
  const opened = new DatabaseSync(path);
  try {
    // WAL: a power cut mid-write never leaves the file half-written.
    opened.exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    migrate(opened);
  } catch (error) {
    // Never half open (a database from a newer app, say): every call asks again.
    opened.close();
    throw error;
  }
  db = opened;
}

export function closeDatabase() {
  statements.clear();
  if (db?.isOpen) db.close();
  db = null;
}

export const isDatabaseOpen = () => db !== null;

export function database(): DatabaseSync {
  if (!db) throw new Error('The database is not open.');
  return db;
}

function statement(sql: string): StatementSync {
  let found = statements.get(sql);
  if (!found) {
    found = database().prepare(sql);
    statements.set(sql, found);
  }
  return found;
}

export type Param = SQLInputValue;

export const all = <T>(sql: string, ...params: Param[]) => statement(sql).all(...params) as T[];
export const one = <T>(sql: string, ...params: Param[]) => (statement(sql).get(...params) as T | undefined) ?? null;
export const run = (sql: string, ...params: Param[]) => statement(sql).run(...params);

let depth = 0;

/** All or nothing: a sale takes every line's stock, or none of it. Nested calls join the outer one. */
export function transaction<T>(task: () => T): T {
  if (depth > 0) return task();
  database().exec('BEGIN IMMEDIATE');
  depth++;
  try {
    const result = task();
    database().exec('COMMIT');
    return result;
  } catch (error) {
    database().exec('ROLLBACK');
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
