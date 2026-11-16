import type { CollectStore, Item, Kit, StoredVersion } from './types';

/**
 * The store in memory, serializing every record as the SQLite store does
 * (JSON), so tests see exactly what survives a restart: build a new engine
 * on the same MemoryStore and nothing held in objects carries over.
 */
export class MemoryStore implements CollectStore {
  private rows = new Map<string, string>();

  private get<T>(key: string): T | null {
    const raw = this.rows.get(key);
    return raw === undefined ? null : (JSON.parse(raw) as T);
  }

  private set(key: string, value: unknown) {
    if (value === null) this.rows.delete(key);
    else this.rows.set(key, JSON.stringify(value));
  }

  async version(scope: string, id: string) {
    return this.get<StoredVersion>(`${scope}|version|${id}`);
  }
  async saveVersion(scope: string, version: StoredVersion) {
    this.set(`${scope}|version|${version.id}`, version);
  }
  async kit(scope: string) {
    return this.get<Kit>(`${scope}|kit`);
  }
  async saveKit(scope: string, kit: Kit) {
    this.set(`${scope}|kit`, kit);
  }
  async item(scope: string, id: string) {
    return this.get<Item>(`${scope}|item|${id}`);
  }
  async items(scope: string) {
    const out: Item[] = [];
    for (const [key, raw] of this.rows) {
      if (key.startsWith(`${scope}|item|`)) out.push(JSON.parse(raw) as Item);
    }
    return out.sort((a, b) => a.startedAt.localeCompare(b.startedAt));
  }
  async saveItem(item: Item) {
    this.set(`${item.scope}|item|${item.id}`, item);
  }
  async removeItem(scope: string, id: string) {
    this.rows.delete(`${scope}|item|${id}`);
  }
  async meta(scope: string, key: string) {
    return this.get<string>(`${scope}|meta|${key}`);
  }
  async setMeta(scope: string, key: string, value: string | null) {
    this.set(`${scope}|meta|${key}`, value);
  }
  async wipe(scope: string) {
    for (const key of [...this.rows.keys()]) {
      if (key.startsWith(`${scope}|`)) this.rows.delete(key);
    }
  }
}
