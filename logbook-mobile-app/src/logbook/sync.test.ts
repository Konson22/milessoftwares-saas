/// <reference types="jest" />
import { MemoryStore } from './memory-store';
import { backoff, buckets, classify, finalize, newItem, pullChanges, syncAll, syncOne, TransportError, type ServerEntry, type Transport } from './sync';
import type { Item, KitSheet, LocalFile, StoredVersion } from './types';

/*
 * The protocol against a fake server that behaves like logbook-app's API:
 * idempotent create by the phone's id, revision-guarded update and submit,
 * hash-deduplicated uploads. Replies can be lost after the server acted.
 */

const SCOPE = 'acme|u1';
const version: StoredVersion = {
  id: 'v1',
  sheetId: 's1',
  version: 1,
  fields: [
    { name: 'reading', label: 'Reading', type: 'integer', required: true },
    { name: 'photo', label: 'Photo', type: 'file', required: false },
  ],
};
const sheet: KitSheet = {
  id: 's1',
  name: 'Meter readings',
  code: 'MTR',
  description: null,
  instructions: null,
  reviewRequired: true,
  logbook: { id: 'l1', name: 'Plant', icon: null },
  version: { id: 'v1', version: 1 },
};
const file = (sha: string): LocalFile => ({ uri: `file:///${sha}.jpg`, field: 'photo', name: `${sha}.jpg`, mime: 'image/jpeg', size: 10, sha256: sha, serverId: null });

class FakeServer implements Transport {
  entries = new Map<string, ServerEntry>();
  files = new Map<string, { entry: string; sha: string; removed: boolean }>();
  calls: string[] = [];
  /** Calls by name that act, then lose their reply. */
  loseReply = new Set<string>();
  /** Calls by name that fail before acting. */
  failWith = new Map<string, TransportError>();
  seq = 0;

  private async run<T>(name: string, act: () => T): Promise<T> {
    this.calls.push(name);
    const fail = this.failWith.get(name);
    if (fail) {
      this.failWith.delete(name);
      throw fail;
    }
    const out = act();
    if (this.loseReply.delete(name)) throw new TransportError('timeout', 'Timed out');
    return out;
  }
  private view(e: ServerEntry): ServerEntry {
    return { ...e, files: [...this.files.entries()].filter(([, f]) => f.entry === e.id && !f.removed).map(([id]) => ({ id, field: 'photo', name: 'p', size: 1, mime: 'image/jpeg', sha256: null })) };
  }

  kit = async () => ({ serverTime: '', can: { write: true, review: false }, limits: { fileMaxKb: 10240, fileTypes: [], filesPerField: 10 }, sheets: [sheet], members: [] });
  version = async () => version;
  create = (body: Parameters<Transport['create']>[0]) =>
    this.run('create', () => {
      const existing = [...this.entries.values()].find((e) => e.key === body.id);
      if (existing) return this.view(existing);
      const e: ServerEntry = {
        id: `e${++this.seq}`,
        key: body.id,
        reference: null,
        status: 'draft',
        revision: 0,
        reviewRequired: true,
        sheet: { id: 's1', name: 'Meter readings', code: 'MTR' },
        logbook: 'Plant',
        versionId: body.version_id,
        occurredAt: body.occurred_at,
        submittedAt: null,
        updatedAt: null,
        values: body.values,
        files: [],
        lastReview: null,
      };
      this.entries.set(e.id, e);
      return this.view(e);
    });
  update = (id: string, body: Parameters<Transport['update']>[1]) =>
    this.run('update', () => {
      const e = this.entries.get(id)!;
      const editable = e.status === 'draft' || e.status === 'returned';
      if (e.revision === body.base_revision + 1 && !editable) return this.view(e);
      if (e.revision !== body.base_revision) throw new TransportError('http', 'stale', 409, 'stale');
      if (!editable) throw new TransportError('http', 'not editable', 409, 'not_editable');
      e.values = body.values;
      return this.view(e);
    });
  upload = (id: string, f: LocalFile) =>
    this.run('upload', () => {
      const same = [...this.files.entries()].find(([, x]) => x.entry === id && x.sha === f.sha256 && !x.removed);
      if (same) return { id: same[0] };
      const fid = `f${++this.seq}`;
      this.files.set(fid, { entry: id, sha: f.sha256, removed: false });
      return { id: fid };
    });
  removeFile = (id: string) =>
    this.run('removeFile', () => {
      const f = this.files.get(id);
      if (f) f.removed = true;
    });
  submit = (id: string, revision: number) =>
    this.run('submit', () => {
      const e = this.entries.get(id)!;
      if (e.revision >= revision) return this.view(e);
      if (e.revision !== revision - 1) throw new TransportError('http', 'stale', 409, 'stale');
      e.revision = revision;
      e.status = 'submitted';
      e.reference ??= `MTR-2026-00000${this.seq}`;
      e.submittedAt = '2026-11-19T08:00:00Z';
      return this.view(e);
    });
  changed = async () => ({ serverTime: '2026-11-19T09:00:00Z', complete: true, entries: [...this.entries.values()].map((e) => this.view(e)) });
}

const noFiles = { remove: jest.fn(async () => undefined) };
const now = () => new Date('2026-11-19T08:00:00Z');

async function ready(store: MemoryStore, files: LocalFile[] = []): Promise<Item> {
  await store.saveVersion(SCOPE, version);
  const item = { ...newItem('0193-phone-id', SCOPE, sheet, version, now()), values: { reading: '42' }, files };
  expect(await finalize(store, item, now())).toEqual({});
  return (await store.item(SCOPE, item.id))!;
}

describe('sending an entry', () => {
  it('creates, uploads and submits, then shows it awaiting review', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, [file('aa'), file('bb')]);
    const out = await syncAll(store, server, noFiles, SCOPE, now);
    expect(out.synced).toBe(1);
    expect(server.calls).toEqual(['create', 'upload', 'upload', 'submit']);
    const item = (await store.items(SCOPE))[0];
    expect(item.status).toBe('synced');
    expect(item.serverStatus).toBe('submitted');
    expect(item.reference).toMatch(/^MTR-/);
    expect(item.values).toEqual({ reading: 42 });
    expect(buckets([item]).inReview).toHaveLength(1);
  });

  it('never duplicates an entry or a file when replies are lost', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, [file('aa')]);

    server.loseReply.add('create');
    let item = await syncOne(store, server, noFiles, (await store.items(SCOPE))[0], now, () => 0.5);
    expect(item.status).toBe('failed');
    expect(item.serverId).toBeNull();

    server.loseReply.add('upload');
    item = await syncOne(store, server, noFiles, item, now);
    expect(item.status).toBe('failed');

    server.loseReply.add('submit');
    item = await syncOne(store, server, noFiles, item, now);
    expect(item.status).toBe('failed');

    item = await syncOne(store, server, noFiles, item, now);
    expect(item.status).toBe('synced');
    expect(server.entries.size).toBe(1);
    expect(server.files.size).toBe(1);
    expect(item.revision).toBe(1);
  });

  it('waits for sign-in on 401 and asks a person on 409/422', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store);
    server.failWith.set('create', new TransportError('http', 'Unauthenticated', 401));
    let out = await syncAll(store, server, noFiles, SCOPE, now);
    expect(out.signIn).toBe(true);
    expect((await store.items(SCOPE))[0].error?.code).toBe('signed_out');

    const item = { ...(await store.items(SCOPE))[0], status: 'ready' as const, error: null };
    await store.saveItem(item);
    server.failWith.set('create', new TransportError('http', 'Invalid', 422, null, { 'values.reading': ['At most 10.'] }));
    out = await syncAll(store, server, noFiles, SCOPE, now);
    expect(out.action).toBe(1);
    const refused = (await store.items(SCOPE))[0];
    expect(refused.status).toBe('action_required');
    expect(refused.error?.fields).toEqual({ reading: 'At most 10.' });
  });

  it('backs off from 30 seconds to 30 minutes and classifies errors', () => {
    expect(backoff(1, () => 0.5)).toBe(30_000);
    expect(backoff(3, () => 0.5)).toBe(120_000);
    expect(backoff(20, () => 0.5)).toBe(30 * 60_000);
    expect(classify(new TransportError('offline', 'x'))).toBe('retry');
    expect(classify(new TransportError('http', 'x', 503))).toBe('retry');
    expect(classify(new TransportError('http', 'x', 401))).toBe('signIn');
    expect(classify(new TransportError('http', 'x', 410))).toBe('action');
  });

  it('refuses to queue an incomplete entry', async () => {
    const store = new MemoryStore();
    await store.saveVersion(SCOPE, version);
    const item = newItem('id2', SCOPE, sheet, version, now());
    expect(await finalize(store, item, now())).toEqual({ reading: 'This field is required.' });
  });
});

describe('corrections', () => {
  it('learns of a return, then sends the correction from the revision it saw', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, [file('aa')]);
    await syncAll(store, server, noFiles, SCOPE, now);

    const entry = [...server.entries.values()][0];
    entry.status = 'returned';
    entry.lastReview = { revision: 1, decision: 'returned', comment: 'Blurred photo', reviewer: 'Rev', decidedAt: null };
    await pullChanges(store, server, SCOPE, now);
    let item = (await store.items(SCOPE))[0];
    expect(item.status).toBe('action_required');
    expect(item.error).toEqual({ code: 'returned', message: 'Blurred photo' });
    expect(buckets([item]).returned).toHaveLength(1);

    // The author swaps the photo and finishes again.
    const old = item.serverFiles[0].id;
    item = { ...item, status: 'draft', removedFiles: [old], files: [file('cc')], values: { reading: 43 } };
    expect(await finalize(store, item, now())).toEqual({});
    await syncAll(store, server, noFiles, SCOPE, now);

    item = (await store.items(SCOPE))[0];
    expect(item.status).toBe('synced');
    expect(item.revision).toBe(2);
    expect(server.files.get(old)?.removed).toBe(true);
    expect(server.calls.slice(-4)).toEqual(['update', 'removeFile', 'upload', 'submit']);
  });

  it('does not overwrite unsent work with the server\'s news', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store);
    await syncAll(store, server, noFiles, SCOPE, now);
    const local = { ...(await store.items(SCOPE))[0], status: 'draft' as const, values: { reading: 99 } };
    await store.saveItem(local);
    await pullChanges(store, server, SCOPE, now);
    expect((await store.items(SCOPE))[0].values).toEqual({ reading: 99 });
  });
});
