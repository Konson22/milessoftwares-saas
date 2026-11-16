/// <reference types="jest" />
/// <reference types="node" />
import { MemoryStore } from './memory-store';
import { backoff, classify, finalize, pullStatuses, purgeOld, queueCorrection, refreshKit, syncAll, syncOne, TransportError, unsent } from './sync';
import type { Changed, SubmissionState, Transport } from './sync';
import type { Definition } from './definition';
import type { Item, Kit, StoredVersion } from './types';

/*
 * The synchronization protocol against a fake server with the real
 * server's rules (idempotent ids, photo manifest, statuses), and a network
 * that can drop a request before it arrives or lose the reply after the
 * server committed it.
 */

const SCOPE = 'acme|user-1';
const definition: Definition = {
  schema_version: 1,
  settings: { title: 'Household' },
  items: [
    { type: 'integer', name: 'members', label: 'People', required: true, constraint: { min: 1, max: 50 } },
    { type: 'photo', name: 'water_point', label: 'Water point' },
  ],
  choices: {},
};
const version: StoredVersion = { id: 'v1', formId: 'f1', number: 1, sha256: 'hash-1', definition, accepting: true, acceptUntil: null };
const kit: Kit = {
  serverTime: '2026-11-17T08:00:00Z',
  settings: { photoMaxKb: 800, keepOnDeviceDays: 30 },
  assignments: [
    {
      id: 'a1',
      status: 'active',
      canStart: true,
      startsOn: null,
      endsOn: null,
      closesAt: null,
      form: { id: 'f1', name: 'Household', description: null, purpose: 'survey', status: 'published', programme: null, containsPersonalData: true },
      unit: null,
      version: { id: 'v1', number: 1, sha256: 'hash-1', schemaVersion: 1 },
    },
  ],
};

type Fault = 'ok' | 'before' | 'after' | 'offline' | { status: number; code?: string };

class FakeServer implements Transport {
  submissions = new Map<string, { body: string; status: string; missing: string[]; revision: number; author: string }>();
  received: string[] = [];
  calls: string[] = [];
  faults: Fault[] = [];
  versionDownloads = 0;
  corrections: { id: string; base: number }[] = [];

  private async fault<T>(name: string, commit: () => T): Promise<T> {
    this.calls.push(name);
    const fault = this.faults.shift();
    if (fault === 'offline' || fault === 'before') throw new TransportError(fault === 'offline' ? 'offline' : 'timeout', 'No reply');
    if (fault && typeof fault === 'object') throw new TransportError('http', 'Refused', fault.status, fault.code ?? null);
    const result = commit();
    if (fault === 'after') throw new TransportError('timeout', 'Reply lost');
    return result;
  }

  private state(id: string, replayed = false): SubmissionState {
    const s = this.submissions.get(id)!;
    return { id, status: s.status, missing: [...s.missing], revision: s.revision, replayed };
  }

  async kit() {
    return this.fault('kit', () => kit);
  }
  async version(id: string) {
    return this.fault('version', () => {
      this.versionDownloads++;
      return { ...version, id };
    });
  }
  async submit(payload: Record<string, unknown>) {
    return this.fault('submit', () => {
      const id = payload.id as string;
      const { app_version: _ignored, ...content } = payload;
      const body = JSON.stringify(content);
      const existing = this.submissions.get(id);
      if (existing) {
        if (existing.body !== body) throw new TransportError('http', 'id_conflict', 409, 'id_conflict');
        return this.state(id, true);
      }
      const missing = (payload.attachments as { question: string }[]).map((a) => a.question);
      this.submissions.set(id, { body, status: missing.length ? 'receiving' : 'submitted', missing, revision: 0, author: 'user-1' });
      this.received.push(id);
      return this.state(id);
    });
  }
  async upload(id: string, question: string) {
    return this.fault(`upload:${question}`, () => {
      const s = this.submissions.get(id)!;
      s.missing = s.missing.filter((q) => q !== question);
      if (s.missing.length === 0 && s.status === 'receiving') s.status = 'submitted';
      return this.state(id);
    });
  }
  async status(id: string) {
    return this.fault('status', () => {
      const state = this.state(id);
      if (state.status === 'correction_requested') {
        return { ...state, correction: { comment: 'Recount', notes: { members: 'Count children' }, answers: { members: 3 }, formVersionId: 'v1' } };
      }
      return state;
    });
  }
  async correct(id: string, body: { base_revision: number }) {
    return this.fault('correct', () => {
      const s = this.submissions.get(id)!;
      if (body.base_revision !== s.revision) {
        if (body.base_revision === s.revision - 1) return this.state(id, true);
        throw new TransportError('http', 'revision_conflict', 409, 'revision_conflict');
      }
      this.corrections.push({ id, base: body.base_revision });
      s.revision++;
      s.status = 'submitted';
      return this.state(id);
    });
  }
  async changed(since: string | null): Promise<Changed> {
    return this.fault('changed', () => ({
      data: [...this.submissions.entries()].map(([id, s]) => ({ id, status: s.status, revision: s.revision, updatedAt: since ?? '2026-11-17T09:00:00Z' })),
      more: false,
    }));
  }
}

const removed: string[] = [];
const files = { remove: async (uri: string) => void removed.push(uri) };
const at = (iso: string) => () => new Date(iso);
const NOW = '2026-11-17T10:00:00Z';

function draft(id: string, answers: Item['answers'] = { members: 4 }, photos: Item['photos'] = {}): Item {
  return {
    id,
    scope: SCOPE,
    formId: 'f1',
    formName: 'Household',
    formVersionId: 'v1',
    unitId: null,
    answers,
    consentGiven: null,
    startedAt: '2026-11-17T09:00:00Z',
    completedAt: null,
    status: 'draft',
    attempts: 0,
    nextAttemptAt: null,
    error: null,
    photos,
    serverStatus: null,
    serverRevision: 0,
    correction: null,
    pendingCorrection: null,
    syncedAt: null,
    updatedAt: '2026-11-17T09:00:00Z',
  };
}

const photo = { uri: 'file:///data/collect/p1.jpg', sha256: 'a'.repeat(64), size: 1234, mime: 'image/jpeg' as const, uploaded: false };

async function ready(store: MemoryStore, item: Item) {
  await store.saveVersion(SCOPE, version);
  expect(await finalize(store, item, new Date(NOW))).toEqual({});
  return (await store.item(SCOPE, item.id))!;
}

beforeEach(() => {
  removed.length = 0;
});

describe('finishing a draft', () => {
  it('runs the form rules on the phone and refuses an invalid form', async () => {
    const store = new MemoryStore();
    await store.saveVersion(SCOPE, version);
    const errors = await finalize(store, draft('s1', { members: 99 }), new Date(NOW));
    expect(errors).toEqual({ members: 'max' });
    expect(await store.item(SCOPE, 's1')).toBeNull();
  });

  it('keeps a draft across a restart, scoped to its person', async () => {
    const store = new MemoryStore();
    await store.saveItem(draft('s1'));
    // Another person on the same phone sees nothing of it.
    expect(await store.items('acme|user-2')).toEqual([]);
    expect((await store.items(SCOPE)).map((i) => i.id)).toEqual(['s1']);
  });
});

describe('sending', () => {
  it('sends a submission once even when the reply is lost', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, draft('s1'));

    server.faults = ['after']; // the server committed, the phone never heard
    const first = await syncAll(store, server, files, SCOPE, '1.0.0', at(NOW), () => 0.5);
    expect(first.failed).toBe(1);
    const failed = (await store.item(SCOPE, 's1'))!;
    expect(failed.status).toBe('failed');
    expect(failed.nextAttemptAt).toBe('2026-11-17T10:00:30.000Z');

    // Not yet due.
    expect((await syncAll(store, server, files, SCOPE, '1.0.0', at('2026-11-17T10:00:10Z'))).synced).toBe(0);
    // Due: the same id again, the server replays.
    const second = await syncAll(store, server, files, SCOPE, '1.0.1', at('2026-11-17T10:01:00Z'));
    expect(second.synced).toBe(1);
    expect(server.received).toEqual(['s1']);
    expect((await store.item(SCOPE, 's1'))!.status).toBe('synced');
  });

  it('resumes an interrupted photo upload from the server\'s list after a restart', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, draft('s1', { members: 2, water_point: photo.sha256 }, { water_point: photo }));

    // First attempt: the submission is committed but its reply is lost.
    server.faults = ['after'];
    await syncAll(store, server, files, SCOPE, '1.0.0', at(NOW), () => 0.5);
    expect(server.submissions.get('s1')!.status).toBe('receiving');

    // 'Restart': a new run on the same store. The submit replays; the photo upload is dropped.
    server.faults = ['ok', 'before'];
    await syncAll(store, server, files, SCOPE, '1.0.0', at('2026-11-17T10:05:00Z'), () => 0.5);
    expect((await store.item(SCOPE, 's1'))!.status).toBe('failed');
    expect(server.submissions.get('s1')!.missing).toEqual(['water_point']);

    // Back online: replay, then only the missing photo, then synced.
    await syncAll(store, server, files, SCOPE, '1.0.0', at('2026-11-17T11:00:00Z'));
    const item = (await store.item(SCOPE, 's1'))!;
    expect(item.status).toBe('synced');
    expect(server.submissions.get('s1')!.status).toBe('submitted');
    // Tried twice: the first was dropped before it arrived; never sent again after it was stored.
    expect(server.calls.filter((c) => c === 'upload:water_point')).toHaveLength(2);
    expect(server.calls.slice(-2)).toEqual(['submit', 'upload:water_point']);
    expect(removed).toEqual([photo.uri]);
  });

  it('treats an item left "syncing" by a crash as due, and settles it by replay', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    const item = await ready(store, draft('s1'));
    await server.submit({ ...JSON.parse(JSON.stringify({ id: 's1', form_version_id: 'v1', programme_unit_id: null, answers: item.answers, consent_given: null, started_at: item.startedAt, completed_at: item.completedAt, attachments: [] })) });
    await store.saveItem({ ...item, status: 'syncing' }); // the app died mid-request

    await syncAll(store, server, files, SCOPE, '1.0.0', at(NOW));
    expect((await store.item(SCOPE, 's1'))!.status).toBe('synced');
    expect(server.received).toEqual(['s1']);
  });

  it('puts refusals under "action required" and never retries them alone', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, draft('s1'));
    await ready(store, draft('s2'));
    server.faults = [{ status: 403, code: 'assignment_revoked' }, { status: 409, code: 'version_closed' }];

    const outcome = await syncAll(store, server, files, SCOPE, '1.0.0', at(NOW));
    expect(outcome.action).toBe(2);
    const items = await store.items(SCOPE);
    expect(items.map((i) => [i.status, i.error?.code])).toEqual([
      ['action_required', 'assignment_revoked'],
      ['action_required', 'version_closed'],
    ]);
    // Kept, not retried, nothing deleted.
    expect((await syncAll(store, server, files, SCOPE, '1.0.0', at('2026-11-18T10:00:00Z'))).synced).toBe(0);
    expect(items[0].answers).toEqual({ members: 4 });
  });

  it('waits for sign-in on 401, and stops when offline', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, draft('s1'));
    await ready(store, draft('s2'));

    server.faults = [{ status: 401 }];
    const outcome = await syncAll(store, server, files, SCOPE, '1.0.0', at(NOW));
    expect(outcome.signIn).toBe(true);
    expect(server.calls).toEqual(['submit']); // stopped after the first
    expect((await store.item(SCOPE, 's1'))!.nextAttemptAt).toBeNull();

    server.calls = [];
    server.faults = ['offline'];
    await syncAll(store, server, files, SCOPE, '1.0.0', at(NOW));
    expect(server.calls).toEqual(['submit']);
  });

  it('sends a week-old offline form after reconnecting', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, draft('s1'));
    for (let i = 0; i < 12; i++) {
      server.faults = ['offline'];
      const item = (await store.item(SCOPE, 's1'))!;
      await syncOne(store, server, files, item, '1.0.0', at(NOW), () => 0.5);
    }
    const waiting = (await store.item(SCOPE, 's1'))!;
    expect(waiting.attempts).toBe(12);
    // Capped at 30 minutes (±20 %).
    expect(new Date(waiting.nextAttemptAt!).getTime() - new Date(NOW).getTime()).toBe(30 * 60_000);

    await syncAll(store, server, files, SCOPE, '1.0.0', at('2026-11-24T10:00:00Z'));
    expect((await store.item(SCOPE, 's1'))!.status).toBe('synced');
  });
});

describe('after sending', () => {
  it('picks up a correction request and sends the correction once', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, draft('s1'));
    await syncAll(store, server, files, SCOPE, '1.0.0', at(NOW));

    server.submissions.get('s1')!.status = 'correction_requested';
    expect(await pullStatuses(store, server, SCOPE, at(NOW))).toBe(1);
    const asked = (await store.item(SCOPE, 's1'))!;
    expect(asked.status).toBe('action_required');
    expect(asked.correction).toEqual({ comment: 'Recount', notes: { members: 'Count children' }, baseRevision: 0 });

    expect(await queueCorrection(store, asked, { members: 60 }, 'Recounted', new Date(NOW))).toEqual({ members: 'max' });
    expect(await queueCorrection(store, asked, { members: 5 }, 'Recounted', new Date(NOW))).toEqual({});

    server.faults = ['after']; // committed, reply lost
    await syncAll(store, server, files, SCOPE, '1.0.0', at(NOW), () => 0.5);
    expect((await store.item(SCOPE, 's1'))!.pendingCorrection).not.toBeNull();
    await syncAll(store, server, files, SCOPE, '1.0.0', at('2026-11-17T11:00:00Z'));

    const done = (await store.item(SCOPE, 's1'))!;
    expect(done.status).toBe('synced');
    expect(done.serverRevision).toBe(1);
    expect(done.answers).toEqual({ members: 5 });
    expect(server.corrections).toEqual([{ id: 's1', base: 0 }]);
  });

  it('downloads only the form versions it lacks', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await refreshKit(store, server, SCOPE);
    await refreshKit(store, server, SCOPE);
    expect(server.versionDownloads).toBe(1);
    expect((await store.version(SCOPE, 'v1'))!.number).toBe(1);
  });

  it('removes synced items past the keep date, never unsent ones', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await ready(store, draft('s1'));
    await syncAll(store, server, files, SCOPE, '1.0.0', at(NOW));
    await store.saveItem(draft('s2'));

    expect(await purgeOld(store, SCOPE, 30, new Date('2026-12-20T00:00:00Z'))).toBe(1);
    expect(unsent(await store.items(SCOPE)).map((i) => i.id)).toEqual(['s2']);
  });
});

describe('classification and backoff', () => {
  it('classifies errors as the ADR says', () => {
    expect(classify(new TransportError('offline', 'x'))).toBe('retry');
    expect(classify(new TransportError('http', 'x', 503))).toBe('retry');
    expect(classify(new TransportError('http', 'x', 429))).toBe('retry');
    expect(classify(new TransportError('http', 'x', 401))).toBe('signIn');
    for (const status of [403, 404, 409, 422, 426]) expect(classify(new TransportError('http', 'x', status))).toBe('action');
  });

  it('backs off from 30 s to 30 min with jitter', () => {
    expect(backoff(1, () => 0.5)).toBe(30_000);
    expect(backoff(2, () => 0.5)).toBe(60_000);
    expect(backoff(20, () => 0.5)).toBe(1_800_000);
    expect(backoff(1, () => 0)).toBe(24_000);
    expect(backoff(1, () => 1)).toBe(36_000);
  });
});
