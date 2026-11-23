import * as Crypto from 'expo-crypto';
import * as SQLite from 'expo-sqlite';

import { secureStorage } from '@/lib/storage';

import { recordKey } from './memory-store';
import type { LocalTrack, TrackRecord, TrackStore } from './tracks';
import type { Item, Kit, LogbookStore, StoredVersion } from './types';
import { toHex } from './uuid';

/*
 * Everything written on the phone lives in one SQLCipher database
 * (expo-sqlite built with useSQLCipher, app.json). Its key is 32 random
 * bytes kept in the Android Keystore through expo-secure-store; it never
 * leaves the phone and is never in AsyncStorage. Rows are JSON, one per
 * statement (a single statement is atomic in SQLite), in WAL mode. A row
 * that cannot be read is moved to `quarantine`, never deleted.
 */

const KEY_NAME = 'tatook-logbook.db-key';
const DB_NAME = 'logbook.db';

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

/** An item row is usable when it parses and has the fields the screens read. */
function decode(body: string): Item | null {
  try {
    const item = JSON.parse(body) as Item;
    return typeof item?.id === 'string' && typeof item.scope === 'string' && typeof item.status === 'string' && item.sheet && item.values ? item : null;
  } catch {
    return null;
  }
}

export class SqliteStore implements LogbookStore, TrackStore {
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
  async item(scope: string, id: string) {
    const db = await database();
    const row = await db.getFirstAsync<{ body: string }>('SELECT body FROM records WHERE key = ?', `${scope}|item|${id}`);
    return row ? decode(row.body) : null;
  }

  async items(scope: string): Promise<Item[]> {
    const db = await database();
    const rows = await db.getAllAsync<{ key: string; body: string }>("SELECT key, body FROM records WHERE scope = ? AND kind = 'item'", scope);
    const items: Item[] = [];
    for (const row of rows) {
      const item = decode(row.body);
      if (item) {
        items.push(item);
        continue;
      }
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

  // ---- Routes (ADR-0028): in the same encrypted database ----
  track(scope: string, id: string) {
    return this.get<LocalTrack>(`${scope}|track|${id}`);
  }
  async tracks(scope: string): Promise<LocalTrack[]> {
    const db = await database();
    const rows = await db.getAllAsync<{ body: string }>("SELECT body FROM records WHERE scope = ? AND kind = 'track'", scope);
    const out: LocalTrack[] = [];
    for (const row of rows) {
      try {
        const t = JSON.parse(row.body) as LocalTrack;
        if (typeof t?.id === 'string' && typeof t.status === 'string') out.push(t);
      } catch {
        // Unreadable: left where it is; its records stay too.
      }
    }
    return out;
  }
  saveTrack(track: LocalTrack) {
    return this.put(`${track.scope}|track|${track.id}`, track.scope, 'track', track);
  }
  async append(track: LocalTrack, records: TrackRecord[]) {
    const db = await database();
    const at = new Date().toISOString();
    // All or nothing: the numbering and the records never disagree.
    await db.withExclusiveTransactionAsync(async (tx) => {
      for (const r of records) {
        await tx.runAsync('INSERT OR REPLACE INTO records (key, scope, kind, body, updated_at) VALUES (?, ?, ?, ?, ?)', recordKey(track.scope, track.id, r.seq), track.scope, 'tpt', JSON.stringify(r), at);
      }
      await tx.runAsync(
        'INSERT INTO records (key, scope, kind, body, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(key) DO UPDATE SET body = excluded.body, updated_at = excluded.updated_at',
        `${track.scope}|track|${track.id}`,
        track.scope,
        'track',
        JSON.stringify(track),
        at,
      );
    });
  }
  async records(scope: string, id: string, afterSeq: number, limit: number): Promise<TrackRecord[]> {
    const db = await database();
    // Key order is seq order (recordKey pads it); the primary key serves the range.
    const rows = await db.getAllAsync<{ body: string }>('SELECT body FROM records WHERE key > ? AND key < ? ORDER BY key LIMIT ?', recordKey(scope, id, afterSeq), `${scope}|tpt|${id}|~`, limit);
    const out: TrackRecord[] = [];
    for (const row of rows) {
      try {
        out.push(JSON.parse(row.body) as TrackRecord);
      } catch {
        // Skipped: the server sees a missing seq, never a made-up one.
      }
    }
    return out;
  }
  async dropRecords(scope: string, id: string, uptoSeq: number) {
    const db = await database();
    await db.runAsync('DELETE FROM records WHERE key >= ? AND key <= ?', `${scope}|tpt|${id}|`, recordKey(scope, id, uptoSeq));
  }
  async countRecords(scope: string, id: string) {
    const db = await database();
    return (await db.getFirstAsync<{ n: number }>('SELECT COUNT(*) AS n FROM records WHERE key >= ? AND key < ?', `${scope}|tpt|${id}|`, `${scope}|tpt|${id}|~`))?.n ?? 0;
  }
  async removeTrack(scope: string, id: string) {
    const db = await database();
    await db.withTransactionAsync(async () => {
      await db.runAsync('DELETE FROM records WHERE key >= ? AND key < ?', `${scope}|tpt|${id}|`, `${scope}|tpt|${id}|~`);
      await db.runAsync('DELETE FROM records WHERE key = ?', `${scope}|track|${id}`);
    });
  }
  active() {
    return this.get<{ scope: string; id: string }>('device|track.active');
  }
  async setActive(value: { scope: string; id: string } | null) {
    if (value === null) {
      const db = await database();
      await db.runAsync("DELETE FROM records WHERE key = 'device|track.active'");
    } else {
      await this.put('device|track.active', 'device', 'meta', value);
    }
  }

  /** Rows that could not be read, kept apart for support. */
  async quarantined(scope: string): Promise<number> {
    const db = await database();
    return (await db.getFirstAsync<{ n: number }>('SELECT COUNT(*) AS n FROM quarantine WHERE scope = ?', scope))?.n ?? 0;
  }
}
