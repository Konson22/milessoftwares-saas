import type { LocalTrack, TrackRecord, TrackStore } from './tracks';
import type { Item, Kit, LogbookStore, StoredVersion } from './types';

/** The store in memory, for tests: copies in and out, as the database would. */
export class MemoryStore implements LogbookStore, TrackStore {
  rows = new Map<string, string>();

  private get<T>(key: string): T | null {
    const raw = this.rows.get(key);
    return raw === undefined ? null : (JSON.parse(raw) as T);
  }
  private put(key: string, value: unknown) {
    this.rows.set(key, JSON.stringify(value));
  }

  async version(scope: string, id: string) {
    return this.get<StoredVersion>(`${scope}|version|${id}`);
  }
  async saveVersion(scope: string, version: StoredVersion) {
    this.put(`${scope}|version|${version.id}`, version);
  }
  async kit(scope: string) {
    return this.get<Kit>(`${scope}|kit`);
  }
  async saveKit(scope: string, kit: Kit) {
    this.put(`${scope}|kit`, kit);
  }
  async item(scope: string, id: string) {
    return this.get<Item>(`${scope}|item|${id}`);
  }
  async items(scope: string) {
    return [...this.rows.entries()].filter(([key]) => key.startsWith(`${scope}|item|`)).map(([, raw]) => JSON.parse(raw) as Item);
  }
  async saveItem(item: Item) {
    this.put(`${item.scope}|item|${item.id}`, item);
  }
  async removeItem(scope: string, id: string) {
    this.rows.delete(`${scope}|item|${id}`);
  }
  async meta(scope: string, key: string) {
    return this.get<string>(`${scope}|meta|${key}`);
  }
  async setMeta(scope: string, key: string, value: string | null) {
    if (value === null) this.rows.delete(`${scope}|meta|${key}`);
    else this.put(`${scope}|meta|${key}`, value);
  }

  // ---- Routes (ADR-0028) ----
  async track(scope: string, id: string) {
    return this.get<LocalTrack>(`${scope}|track|${id}`);
  }
  async tracks(scope: string) {
    return [...this.rows.entries()].filter(([key]) => key.startsWith(`${scope}|track|`)).map(([, raw]) => JSON.parse(raw) as LocalTrack);
  }
  async saveTrack(track: LocalTrack) {
    this.put(`${track.scope}|track|${track.id}`, track);
  }
  /** Set by a test to make the next append fail, as a full disk would. */
  failNextAppend = false;
  async append(track: LocalTrack, records: TrackRecord[]) {
    if (this.failNextAppend) {
      this.failNextAppend = false;
      throw new Error('database or disk is full');
    }
    for (const r of records) this.put(recordKey(track.scope, track.id, r.seq), r);
    this.put(`${track.scope}|track|${track.id}`, track);
  }
  async records(scope: string, id: string, afterSeq: number, limit: number) {
    return [...this.rows.entries()]
      .filter(([key]) => key.startsWith(`${scope}|tpt|${id}|`) && key > recordKey(scope, id, afterSeq))
      .sort(([a], [b]) => a.localeCompare(b))
      .slice(0, limit)
      .map(([, raw]) => JSON.parse(raw) as TrackRecord);
  }
  async dropRecords(scope: string, id: string, uptoSeq: number) {
    for (const key of [...this.rows.keys()]) if (key.startsWith(`${scope}|tpt|${id}|`) && key <= recordKey(scope, id, uptoSeq)) this.rows.delete(key);
  }
  async countRecords(scope: string, id: string) {
    return [...this.rows.keys()].filter((key) => key.startsWith(`${scope}|tpt|${id}|`)).length;
  }
  async removeTrack(scope: string, id: string) {
    await this.dropRecords(scope, id, Number.MAX_SAFE_INTEGER);
    this.rows.delete(`${scope}|track|${id}`);
  }
  async active() {
    return this.get<{ scope: string; id: string }>('device|track.active');
  }
  async setActive(value: { scope: string; id: string } | null) {
    if (value === null) this.rows.delete('device|track.active');
    else this.put('device|track.active', value);
  }
}

/** Sortable record keys: the seq padded, so key order is seq order. */
export const recordKey = (scope: string, id: string, seq: number) => `${scope}|tpt|${id}|${String(Math.min(seq, 999_999_999)).padStart(9, '0')}`;
