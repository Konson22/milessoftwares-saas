import * as Crypto from 'expo-crypto';
import * as SQLite from 'expo-sqlite';

import { secureStorage } from '@/lib/storage';

import { decodeItems } from './decode';
import type { CollectStore, Item, Kit, StoredVersion } from './types';
import { toHex } from './uuid';

/*
 * Everything collected lives in one SQLCipher database (expo-sqlite built
 * with useSQLCipher, app.json). Its key is 32 random bytes kept in the
 * Android Keystore through expo-secure-store; it never leaves the phone and
 * is never in AsyncStorage. Rows are JSON, one per statement (a single
 * statement is atomic in SQLite), in WAL mode.
 */

const KEY_NAME = 'tatook-mel.db-key';
const DB_NAME = 'collect.db';

async function databaseKey(): Promise<string> {
  const existing = await secureStorage.get(KEY_NAME);
  if (existing) return existing;
  const key = toHex(Crypto.getRandomBytes(32));
  await secureStorage.set(KEY_NAME, key);
  return key;
}

let opening: Promise<SQLite.SQLiteDatabase> | null = null;

export function database(): Promise<SQLite.SQLiteDatabase> {
  opening ??= (async () => {
    const key = await databaseKey();
    const db = await SQLite.openDatabaseAsync(DB_NAME);
    // The key must be the first statement on the connection.
    await db.execAsync(`PRAGMA key = "x'${key}'";`);
    await db.execAsync(`
      PRAGMA journal_mode = WAL;
      CREATE TABLE IF NOT EXISTS records (
        key TEXT PRIMARY KEY NOT NULL,
        scope TEXT NOT NULL,
        kind TEXT NOT NULL,
        body TEXT NOT NULL,
        updated_at TEXT NOT NULL
      );
      CREATE INDEX IF NOT EXISTS records_scope_kind ON records (scope, kind);
      CREATE TABLE IF NOT EXISTS quarantine (
        key TEXT PRIMARY KEY NOT NULL,
        scope TEXT NOT NULL,
        body TEXT NOT NULL,
        found_at TEXT NOT NULL
      );
    `);
    return db;
  })().catch((error: unknown) => {
    opening = null;
    throw error;
  });
  return opening;
}

export class SqliteStore implements CollectStore {
  private async get<T>(key: string): Promise<T | null> {
    const db = await database();
    const row = await db.getFirstAsync<{ body: string }>('SELECT body FROM records WHERE key = ?', key);
    if (!row) return null;
    try {
      return JSON.parse(row.body) as T;
    } catch {
      return null;
    }
  }

  private async put(key: string, scope: string, kind: string, value: unknown) {
    const db = await database();
    await db.runAsync(
      'INSERT INTO records (key, scope, kind, body, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(key) DO UPDATE SET body = excluded.body, updated_at = excluded.updated_at',
      key,
      scope,
      kind,
      JSON.stringify(value),
      new Date().toISOString(),
    );
  }

  version(scope: string, id: string) {
    return this.get<StoredVersion>(`${scope}|version|${id}`);
  }
  saveVersion(scope: string, version: StoredVersion) {
    return this.put(`${scope}|version|${version.id}`, scope, 'version', version);
  }
  kit(scope: string) {
    return this.get<Kit>(`${scope}|kit`);
  }
  saveKit(scope: string, kit: Kit) {
    return this.put(`${scope}|kit`, scope, 'kit', kit);
  }
  item(scope: string, id: string) {
    return this.get<Item>(`${scope}|item|${id}`);
  }

  async items(scope: string): Promise<Item[]> {
    const db = await database();
    const rows = await db.getAllAsync<{ key: string; body: string }>("SELECT key, body FROM records WHERE scope = ? AND kind = 'item'", scope);
    const { items, bad } = decodeItems(rows);
    for (const row of bad) {
      // Kept for support, out of the way of the rest.
      await db.withTransactionAsync(async () => {
        await db.runAsync('INSERT OR REPLACE INTO quarantine (key, scope, body, found_at) VALUES (?, ?, ?, ?)', row.key, scope, row.body, new Date().toISOString());
        await db.runAsync('DELETE FROM records WHERE key = ?', row.key);
      });
    }
    return items;
  }

  saveItem(item: Item) {
    return this.put(`${item.scope}|item|${item.id}`, item.scope, 'item', item);
  }
  async removeItem(scope: string, id: string) {
    const db = await database();
    await db.runAsync('DELETE FROM records WHERE key = ?', `${scope}|item|${id}`);
  }
  meta(scope: string, key: string) {
    return this.get<string>(`${scope}|meta|${key}`);
  }
  async setMeta(scope: string, key: string, value: string | null) {
    if (value === null) {
      const db = await database();
      await db.runAsync('DELETE FROM records WHERE key = ?', `${scope}|meta|${key}`);
    } else {
      await this.put(`${scope}|meta|${key}`, scope, 'meta', value);
    }
  }
  async wipe(scope: string) {
    const db = await database();
    await db.runAsync('DELETE FROM records WHERE scope = ?', scope);
  }

  /** Rows that could not be read, kept apart for support. */
  async quarantined(scope: string): Promise<number> {
    const db = await database();
    return (await db.getFirstAsync<{ n: number }>('SELECT COUNT(*) AS n FROM quarantine WHERE scope = ?', scope))?.n ?? 0;
  }
}
