/// <reference types="jest" />
import { MemoryStore } from './memory-store';
import { TransportError } from './sync';
import {
  changeTrack,
  interruptTrack,
  noteTrack,
  purgeTracks,
  recordFixes,
  retryTracks,
  startTrack,
  step,
  syncTracks,
  TripError,
  unsentRecords,
  type Fix,
  type ServerTrack,
  type TrackStatus,
  type TrackTransport,
} from './tracks';

/*
 * Routes against a fake server that behaves like logbook-app's API:
 * upsert keyed by the phone's id (ended is final), points stored once
 * per seq, the highest seq answered. Replies can be lost after it acted.
 */

const SCOPE = 'acme|u1';
const T0 = Date.parse('2026-11-20T07:00:00Z');
const at = (s: number) => new Date(T0 + s * 1000);
/** A fix $m metres north of the start, $s seconds in. */
const fix = (m: number, s: number, accuracy = 8): Fix => ({ lat: 4.85 + m / 111_195, lng: 31.58, accuracy, speed: null, altitude: null, at: at(s).toISOString() });

class FakeServer implements TrackTransport {
  tracks = new Map<string, { status: TrackStatus }>();
  seqs = new Map<string, Set<number>>();
  calls: string[] = [];
  loseReply = new Set<string>();
  failBefore = new Map<string, TransportError>();

  private guard(name: string) {
    this.calls.push(name);
    const failure = this.failBefore.get(name);
    if (failure) {
      this.failBefore.delete(name);
      throw failure;
    }
  }
  private reply<T>(name: string, value: T): T {
    if (this.loseReply.delete(name)) throw new TransportError('timeout', 'No reply');
    return value;
  }
  private row(id: string): ServerTrack {
    const seqs = this.seqs.get(id) ?? new Set();
    return { id, status: this.tracks.get(id)!.status, distanceM: 0, points: seqs.size, lastSeq: Math.max(0, ...seqs) };
  }

  async upsert(body: Parameters<TrackTransport['upsert']>[0]) {
    this.guard('upsert');
    const t = this.tracks.get(body.id) ?? { status: 'recording' as TrackStatus };
    if (t.status !== 'ended') t.status = body.status;
    this.tracks.set(body.id, t);
    return this.reply('upsert', this.row(body.id));
  }
  async points(id: string, body: { points: { seq: number }[]; events: { seq: number }[] } | { points: object[]; events: object[] }) {
    this.guard('points');
    const seqs = this.seqs.get(id) ?? new Set<number>();
    const batch = [...(body.points as { seq: number }[]), ...(body.events as { seq: number }[])].map((r) => r.seq);
    batch.forEach((s) => seqs.add(s));
    this.seqs.set(id, seqs);
    return this.reply('points', { acknowledged: Math.max(...batch), refused: [], track: this.row(id) });
  }
}

const start = (store: MemoryStore, id = 't1') =>
  startTrack(store, { id, scope: SCOPE, sheet: { id: 's1', name: 'Vehicle movement', code: 'VML' }, logbook: 'Transport', resource: { id: 'r1', label: 'KDB-123' } }, at(0));

describe('estimating distance', () => {
  it('counts steps, not jitter, inaccurate fixes or jumps', () => {
    expect(step(null, fix(0, 0)).use).toBe(true);
    expect(step(fix(0, 0), fix(100, 10)).add).toBeCloseTo(100, 0);
    expect(step(fix(0, 0), fix(2, 10))).toEqual({ use: true, add: 0 });
    expect(step(fix(0, 0), fix(100, 10, 400)).use).toBe(false);
    expect(step(fix(0, 0), fix(5000, 10)).use).toBe(false);
    expect(step(fix(0, 10), fix(100, 10)).use).toBe(false);
  });
});

describe('a trip on the phone', () => {
  it('starts only when asked, one at a time, and records nothing while paused', async () => {
    const store = new MemoryStore();
    expect(await store.active()).toBeNull();
    await start(store);
    await expect(start(store, 't2')).rejects.toBeInstanceOf(TripError);

    await recordFixes(store, SCOPE, 't1', [fix(0, 0), fix(100, 10), fix(200, 20)]);
    await changeTrack(store, SCOPE, 't1', 'pause', at(30));
    await recordFixes(store, SCOPE, 't1', [fix(5000, 300)]); // late delivery while paused: ignored
    await changeTrack(store, SCOPE, 't1', 'resume', at(600));
    await recordFixes(store, SCOPE, 't1', [fix(5000, 610), fix(5100, 620)]);
    const ended = await changeTrack(store, SCOPE, 't1', 'end', at(700));

    expect(ended.status).toBe('ended');
    expect(ended.distanceM).toBeGreaterThan(290); // 200 before the pause, 100 after; nothing across it
    expect(ended.distanceM).toBeLessThan(310);
    expect(await store.active()).toBeNull();
    const kinds = (await store.records(SCOPE, 't1', 0, 100)).map((r) => (r.type === 'event' ? r.kind : 'point'));
    expect(kinds).toEqual(['start', 'point', 'point', 'point', 'pause', 'resume', 'point', 'point', 'end']);
  });

  it('keeps a position delivered twice only once', async () => {
    const store = new MemoryStore();
    await start(store);
    await recordFixes(store, SCOPE, 't1', [fix(0, 0), fix(100, 10)]);
    const t = await recordFixes(store, SCOPE, 't1', [fix(100, 10), fix(200, 20)]);
    expect(t?.points).toBe(3);
  });

  it('marks an interruption and waits for the person to resume', async () => {
    const store = new MemoryStore();
    await start(store);
    const t = await interruptTrack(store, SCOPE, 't1', at(60), 'app stopped');
    expect(t?.status).toBe('paused');
    expect(await store.active()).toEqual({ scope: SCOPE, id: 't1' });
    const back = await changeTrack(store, SCOPE, 't1', 'recovered', at(120));
    expect(back.status).toBe('recording');
    await noteTrack(store, SCOPE, 't1', 'gps_off', at(130));
    await noteTrack(store, SCOPE, 't1', 'gps_off', at(140)); // once
    await noteTrack(store, SCOPE, 't1', 'gps_on', at(150));
    const kinds = (await store.records(SCOPE, 't1', 0, 100)).map((r) => (r.type === 'event' ? r.kind : 'point'));
    expect(kinds).toEqual(['start', 'interrupted', 'recovered', 'gps_off', 'gps_on']);
  });

  it('loses nothing when saving fails: the numbering stays with the records', async () => {
    const store = new MemoryStore();
    await start(store);
    store.failNextAppend = true;
    await expect(recordFixes(store, SCOPE, 't1', [fix(0, 0)])).rejects.toThrow('disk is full');
    const t = await recordFixes(store, SCOPE, 't1', [fix(0, 0)]);
    expect(t?.nextSeq).toBe(3);
    expect((await store.records(SCOPE, 't1', 0, 10)).map((r) => r.seq)).toEqual([1, 2]);
  });
});

describe('sending a trip', () => {
  it('sends in batches and is synced only once the server holds everything', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await start(store);
    await recordFixes(store, SCOPE, 't1', Array.from({ length: 1200 }, (_, i) => fix(i * 50, i * 5)));
    await changeTrack(store, SCOPE, 't1', 'end', at(7000));

    const outcome = await syncTracks(store, server, SCOPE, () => at(7100));
    const t = (await store.track(SCOPE, 't1'))!;
    expect(outcome.synced).toBe(1);
    expect(t.sync).toBe('synced');
    expect(server.calls.filter((c) => c === 'points')).toHaveLength(3); // 1202 records, 500 a batch
    expect(server.seqs.get('t1')!.size).toBe(1202);
    expect(server.tracks.get('t1')!.status).toBe('ended');
    expect(await store.countRecords(SCOPE, 't1')).toBe(0); // confirmed, so removed from the phone
  });

  it('keeps unconfirmed points when a reply is lost, and sends them again', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await start(store);
    await recordFixes(store, SCOPE, 't1', [fix(0, 0), fix(100, 10)]);
    server.loseReply.add('points');

    await syncTracks(store, server, SCOPE, () => at(20), () => 0.5);
    let t = (await store.track(SCOPE, 't1'))!;
    expect(t.sync).toBe('failed');
    expect(t.sentSeq).toBe(0);
    expect(await store.countRecords(SCOPE, 't1')).toBe(3);

    await retryTracks(store, SCOPE, at(30));
    await syncTracks(store, server, SCOPE, () => at(40));
    t = (await store.track(SCOPE, 't1'))!;
    expect(t.sync).toBe('synced');
    expect(server.seqs.get('t1')!.size).toBe(3); // the repeat was stored once
  });

  it('keeps recording offline and sends it all later', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await start(store);
    server.failBefore.set('upsert', new TransportError('offline', 'Network request failed'));
    await recordFixes(store, SCOPE, 't1', [fix(0, 0)]);
    const offline = await syncTracks(store, server, SCOPE, () => at(10), () => 0.5);
    expect(offline.failed).toBe(1);
    await recordFixes(store, SCOPE, 't1', [fix(100, 10), fix(200, 20)]);
    expect(unsentRecords(await store.tracks(SCOPE))).toBe(4);

    // Still waiting out its backoff: not sent yet.
    await syncTracks(store, server, SCOPE, () => at(15));
    expect(server.calls).toEqual(['upsert']);
    await syncTracks(store, server, SCOPE, () => at(600));
    expect((await store.track(SCOPE, 't1'))!.sync).toBe('synced');
    expect(server.tracks.get('t1')!.status).toBe('recording');
  });

  it('stops for a refusal, and waits for sign-in on 401', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await start(store);
    server.failBefore.set('upsert', new TransportError('http', 'Choose the vehicle', 409, 'resource_required'));
    await syncTracks(store, server, SCOPE, () => at(10));
    let t = (await store.track(SCOPE, 't1'))!;
    expect(t.sync).toBe('action_required');
    expect(t.error?.code).toBe('resource_required');

    await start(store, 't2').catch(() => undefined); // t1 still open: refused
    await changeTrack(store, SCOPE, 't1', 'end', at(20)); // a change makes it due again
    server.failBefore.set('upsert', new TransportError('http', 'Unauthenticated', 401));
    const outcome = await syncTracks(store, server, SCOPE, () => at(30));
    t = (await store.track(SCOPE, 't1'))!;
    expect(outcome.signIn).toBe(true);
    expect(t.error?.code).toBe('signed_out');
  });

  it('keeps routes apart by account and workspace', async () => {
    const store = new MemoryStore();
    await start(store);
    expect(await store.tracks('globex|u1')).toEqual([]);
    expect(await store.tracks('acme|u2')).toEqual([]);
    expect(await store.track('acme|u2', 't1')).toBeNull();
  });

  it('removes ended, synced routes from the phone after a while', async () => {
    const store = new MemoryStore();
    const server = new FakeServer();
    await start(store);
    await changeTrack(store, SCOPE, 't1', 'end', at(60));
    await syncTracks(store, server, SCOPE, () => at(70));
    expect(await purgeTracks(store, SCOPE, at(86_400))).toBe(0);
    expect(await purgeTracks(store, SCOPE, new Date(Date.now() + 31 * 86_400_000))).toBe(1);
    expect(await store.tracks(SCOPE)).toEqual([]);
  });
});
