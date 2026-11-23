import { backoff, classify, TransportError } from './sync';

/*
 * Routes recorded on the phone (ADR-0028), with no React Native in it: the
 * store and the transport are passed in, so the whole protocol runs in Jest.
 *
 * A route is started by the person, never by itself. While it records,
 * positions and events (start, pause, resume, end, interrupted…) are
 * numbered in order (seq) and kept in the encrypted database before
 * anything is sent. Sync sends them in batches:
 *  1. POST tracks with the route's state (repeatable: the phone's id is the key);
 *  2. POST tracks/{id}/points with the next records; the server answers the
 *     highest seq it holds, and only then are they removed from the phone;
 *  3. repeat until nothing is left. The route is "synced" only when the
 *     server holds its state and every record.
 * Errors follow the entries' rules (sync.ts): offline, timeouts, 429 and
 * 5xx retried with backoff; 401 waits for sign-in; refusals need a person.
 */

export type TrackStatus = 'recording' | 'paused' | 'ended';
export type TrackSync = 'pending' | 'syncing' | 'synced' | 'failed' | 'action_required';
export type TrackEventKind = 'start' | 'pause' | 'resume' | 'end' | 'interrupted' | 'recovered' | 'gps_off' | 'gps_on' | 'permission_lost';

export type Fix = { lat: number; lng: number; accuracy: number | null; speed: number | null; altitude: number | null; at: string };

export type TrackRecord = ({ type: 'point' } & Fix & { seq: number }) | { type: 'event'; seq: number; kind: TrackEventKind; at: string; note?: string | null };

export type LocalTrack = {
  /** Made here (UUIDv7): the server's key for the route. */
  id: string;
  scope: string;
  sheet: { id: string; name: string; code: string };
  logbook: string;
  resource: { id: string; label: string } | null;
  taskId: string | null;
  status: TrackStatus;
  startedAt: string;
  endedAt: string | null;
  /** The next record's number. */
  nextSeq: number;
  /** The highest seq the server confirmed. */
  sentSeq: number;
  /** The state the server last confirmed. */
  serverStatus: TrackStatus | null;
  sync: TrackSync;
  attempts: number;
  nextAttemptAt: string | null;
  error: { code: string | null; message: string } | null;
  /** The phone's own estimate; the server recomputes it from the points. */
  distanceM: number;
  points: number;
  lastFix: Fix | null;
  /** The newest position kept: anything not newer was delivered twice. */
  lastPointAt: string | null;
  /** Location services were off at the last check. */
  gpsOff: boolean;
  device: string | null;
  createdAt: string;
  updatedAt: string;
};

export interface TrackStore {
  track(scope: string, id: string): Promise<LocalTrack | null>;
  tracks(scope: string): Promise<LocalTrack[]>;
  saveTrack(track: LocalTrack): Promise<void>;
  /** The track and its new records together, in one transaction. */
  append(track: LocalTrack, records: TrackRecord[]): Promise<void>;
  /** Records after seq, in order. */
  records(scope: string, id: string, afterSeq: number, limit: number): Promise<TrackRecord[]>;
  /** Removes records up to seq (the server holds them). */
  dropRecords(scope: string, id: string, uptoSeq: number): Promise<void>;
  countRecords(scope: string, id: string): Promise<number>;
  removeTrack(scope: string, id: string): Promise<void>;
  /** The route recording now, on this phone, whoever is signed in. */
  active(): Promise<{ scope: string; id: string } | null>;
  setActive(value: { scope: string; id: string } | null): Promise<void>;
}

export type ServerTrack = { id: string; status: TrackStatus; distanceM: number; points: number; lastSeq: number };

export interface TrackTransport {
  upsert(body: { id: string; sheet_id: string; resource_id: string | null; task_id: string | null; status: TrackStatus; started_at: string; ended_at: string | null; device: string | null }): Promise<ServerTrack>;
  points(id: string, body: { points: object[]; events: object[] }): Promise<{ acknowledged: number; refused: number[]; track: ServerTrack }>;
}

/** The same limits as the server (logbook-app App\Logbook\Route). */
export const MAX_ACCURACY_M = 75;
export const MAX_SPEED_MPS = 70;
export const MIN_STEP_M = 5;
export const BATCH = 500;
/** Ended routes the server holds stay listed on the phone this long. */
export const KEEP_DAYS = 30;
/** Unsent records past this: tell the person to get online. */
export const STORAGE_WARNING = 50_000;

/** Records written one at a time: the location task and the screen never interleave. */
let queue: Promise<unknown> = Promise.resolve();
function serial<T>(work: () => Promise<T>): Promise<T> {
  const next = queue.then(work, work);
  queue = next.catch(() => undefined);
  return next;
}

export function metres(aLat: number, aLng: number, bLat: number, bLng: number): number {
  const rad = Math.PI / 180;
  const dLat = (bLat - aLat) * rad;
  const dLng = (bLng - aLng) * rad;
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(aLat * rad) * Math.cos(bLat * rad) * Math.sin(dLng / 2) ** 2;
  return 2 * 6_371_000 * Math.asin(Math.min(1, Math.sqrt(h)));
}

/** Whether a fix moves the estimate, and by how much (0: kept but not counted). */
export function step(prev: Fix | null, fix: Fix): { use: boolean; add: number } {
  if (fix.accuracy !== null && fix.accuracy > MAX_ACCURACY_M) return { use: false, add: 0 };
  if (!prev) return { use: true, add: 0 };
  const seconds = (new Date(fix.at).getTime() - new Date(prev.at).getTime()) / 1000;
  if (seconds <= 0) return { use: false, add: 0 };
  const d = metres(prev.lat, prev.lng, fix.lat, fix.lng);
  if (d / seconds > MAX_SPEED_MPS) return { use: false, add: 0 };
  return { use: true, add: d < MIN_STEP_M ? 0 : d };
}

export type StartInput = {
  id: string;
  scope: string;
  sheet: { id: string; name: string; code: string };
  logbook: string;
  resource: { id: string; label: string } | null;
  taskId?: string | null;
  device?: string | null;
};

export class TripError extends Error {}

/** Starts a route, when none is recording on this phone. */
export function startTrack(store: TrackStore, input: StartInput, now: Date): Promise<LocalTrack> {
  return serial(async () => {
    const current = await store.active();
    if (current) {
      const other = await store.track(current.scope, current.id);
      if (other && other.status !== 'ended') throw new TripError('A route is already being recorded on this phone. End it first.');
    }
    const at = now.toISOString();
    const track: LocalTrack = {
      id: input.id,
      scope: input.scope,
      sheet: input.sheet,
      logbook: input.logbook,
      resource: input.resource,
      taskId: input.taskId ?? null,
      status: 'recording',
      startedAt: at,
      endedAt: null,
      nextSeq: 2,
      sentSeq: 0,
      serverStatus: null,
      sync: 'pending',
      attempts: 0,
      nextAttemptAt: null,
      error: null,
      distanceM: 0,
      points: 0,
      lastFix: null,
      lastPointAt: null,
      gpsOff: false,
      device: input.device ?? null,
      createdAt: at,
      updatedAt: at,
    };
    await store.append(track, [{ type: 'event', seq: 1, kind: 'start', at }]);
    await store.setActive({ scope: track.scope, id: track.id });
    return track;
  });
}

const NEXT: Record<'pause' | 'resume' | 'end' | 'recovered', { from: TrackStatus[]; to: TrackStatus }> = {
  pause: { from: ['recording'], to: 'paused' },
  resume: { from: ['paused'], to: 'recording' },
  recovered: { from: ['paused', 'recording'], to: 'recording' },
  end: { from: ['recording', 'paused'], to: 'ended' },
};

/** Pause, resume, end, or resume after an interruption. */
export function changeTrack(store: TrackStore, scope: string, id: string, kind: keyof typeof NEXT, now: Date): Promise<LocalTrack> {
  return serial(async () => {
    const track = await store.track(scope, id);
    if (!track) throw new TripError('This route is no longer on the phone.');
    const rule = NEXT[kind];
    if (!rule.from.includes(track.status)) return track;
    const at = now.toISOString();
    const next: LocalTrack = {
      ...track,
      status: rule.to,
      endedAt: rule.to === 'ended' ? at : track.endedAt,
      nextSeq: track.nextSeq + 1,
      sync: 'pending',
      nextAttemptAt: null,
      // No distance across a pause or an interruption.
      lastFix: null,
      updatedAt: at,
    };
    await store.append(next, [{ type: 'event', seq: track.nextSeq, kind, at }]);
    if (rule.to === 'ended') await store.setActive(null);
    return next;
  });
}

/**
 * The phone stopped the recording (the app was killed, or the location
 * service died): noted as an interruption and paused, so the person
 * chooses to resume or end. Never resumed by itself.
 */
export function interruptTrack(store: TrackStore, scope: string, id: string, now: Date, note: string | null = null): Promise<LocalTrack | null> {
  return serial(async () => {
    const track = await store.track(scope, id);
    if (!track || track.status !== 'recording') return track;
    const at = now.toISOString();
    const next: LocalTrack = { ...track, status: 'paused', nextSeq: track.nextSeq + 1, sync: 'pending', nextAttemptAt: null, lastFix: null, updatedAt: at };
    await store.append(next, [{ type: 'event', seq: track.nextSeq, kind: 'interrupted', at, note }]);
    return next;
  });
}

/** Location services turned off or on, or permission taken away, while recording. */
export function noteTrack(store: TrackStore, scope: string, id: string, kind: 'gps_off' | 'gps_on' | 'permission_lost', now: Date): Promise<LocalTrack | null> {
  return serial(async () => {
    const track = await store.track(scope, id);
    if (!track || track.status === 'ended') return track;
    const off = kind !== 'gps_on';
    if (off === track.gpsOff) return track;
    const at = now.toISOString();
    const next: LocalTrack = { ...track, gpsOff: off, nextSeq: track.nextSeq + 1, sync: 'pending', nextAttemptAt: null, lastFix: off ? null : track.lastFix, updatedAt: at };
    await store.append(next, [{ type: 'event', seq: track.nextSeq, kind, at }]);
    return next;
  });
}

/**
 * Positions from the location service, kept as received (the server
 * applies the same filters); only the phone's estimate leaves out the
 * inaccurate and the implausible. Ignored unless the route is recording.
 */
export function recordFixes(store: TrackStore, scope: string, id: string, fixes: Fix[]): Promise<LocalTrack | null> {
  return serial(async () => {
    const track = await store.track(scope, id);
    if (!track || track.status !== 'recording' || fixes.length === 0) return track;
    const ordered = [...fixes].sort((a, b) => a.at.localeCompare(b.at));
    const records: TrackRecord[] = [];
    let seq = track.nextSeq;
    let distance = track.distanceM;
    let last = track.lastFix;
    let newest = track.lastPointAt;
    for (const fix of ordered) {
      if (newest !== null && fix.at <= newest) continue; // delivered twice
      newest = fix.at;
      records.push({ type: 'point', seq: seq++, ...fix });
      const s = step(last, fix);
      if (s.use) {
        distance += s.add;
        // Jitter while standing still does not move the reference point.
        if (!last || s.add > 0) last = fix;
      }
    }
    if (records.length === 0) return track;
    const next: LocalTrack = {
      ...track,
      nextSeq: seq,
      distanceM: Math.round(distance),
      points: track.points + records.length,
      lastFix: last ?? track.lastFix,
      lastPointAt: newest,
      gpsOff: false,
      sync: track.sync === 'syncing' ? 'syncing' : 'pending',
      updatedAt: new Date().toISOString(),
    };
    await store.append(next, records);
    return next;
  });
}

function wire(records: TrackRecord[]) {
  const points: object[] = [];
  const events: object[] = [];
  for (const r of records) {
    if (r.type === 'point') points.push({ seq: r.seq, lat: r.lat, lng: r.lng, accuracy_m: r.accuracy, speed_mps: r.speed, altitude_m: r.altitude, captured_at: r.at });
    else events.push({ seq: r.seq, kind: r.kind, at: r.at, note: r.note ?? null });
  }
  return { points, events };
}

/** A route due to be sent: anything unconfirmed, past its backoff, not waiting for a person. */
export function trackDue(track: LocalTrack, now: Date): boolean {
  if (track.sync === 'synced' || track.sync === 'action_required') return false;
  if (track.error?.code === 'signed_out') return false;
  return track.nextAttemptAt === null || new Date(track.nextAttemptAt) <= now;
}

const settled = (t: LocalTrack) => t.serverStatus === t.status && t.sentSeq >= t.nextSeq - 1;

/** One route through the steps. Always returns the route as saved. */
export async function syncTrack(store: TrackStore, transport: TrackTransport, scope: string, id: string, now: () => Date = () => new Date(), random?: () => number): Promise<LocalTrack | null> {
  const save = (patch: Partial<LocalTrack>) =>
    serial(async () => {
      // Read again: positions may have arrived while a request was out.
      const current = await store.track(scope, id);
      if (!current) return null;
      const next = { ...current, ...patch, updatedAt: now().toISOString() };
      await store.saveTrack(next);
      return next;
    });

  let track = await save({ sync: 'syncing' });
  if (!track) return null;
  try {
    for (let round = 0; round < 1000 && track && !settled(track); round++) {
      if (track.serverStatus !== track.status) {
        const state = await transport.upsert({
          id: track.id,
          sheet_id: track.sheet.id,
          resource_id: track.resource?.id ?? null,
          task_id: track.taskId,
          status: track.status,
          started_at: track.startedAt,
          ended_at: track.endedAt,
          device: track.device,
        });
        track = await save({ serverStatus: state.status });
        if (!track) return null;
        // Ended on the server first (another phone with this id cannot exist; kept safe): follow it.
        if (state.status === 'ended' && track.status !== 'ended') track = await save({ status: 'ended', endedAt: track.endedAt ?? now().toISOString() });
        if (!track) return null;
      }
      const records = await store.records(scope, id, track.sentSeq, BATCH);
      if (records.length > 0) {
        const answer = await transport.points(id, wire(records));
        const confirmed = Math.max(track.sentSeq, Math.min(answer.acknowledged, records[records.length - 1].seq));
        await store.dropRecords(scope, id, confirmed);
        track = await save({ sentSeq: confirmed });
        if (!track) return null;
        if (confirmed < records[records.length - 1].seq) throw new TransportError('http', 'The server did not confirm every position.', 500);
      } else if (track.sentSeq < track.nextSeq - 1) {
        // Records already gone from the phone (confirmed before a crash): nothing left to send.
        track = await save({ sentSeq: track.nextSeq - 1 });
        if (!track) return null;
      }
    }
    if (!track) return null;
    return await save(settled(track) ? { sync: 'synced', attempts: 0, nextAttemptAt: null, error: null } : { sync: 'pending' });
  } catch (error) {
    const kind = classify(error);
    const message = error instanceof Error ? error.message : 'The route could not be sent.';
    const code = error instanceof TransportError ? (error.kind === 'http' ? error.code : error.kind) : null;
    const current = await store.track(scope, id);
    if (!current) return null;
    if (kind === 'signIn') return save({ sync: 'failed', error: { code: 'signed_out', message: 'Sign in again to send this route.' } });
    if (kind === 'action') return save({ sync: 'action_required', error: { code, message } });
    const attempts = current.attempts + 1;
    return save({ sync: 'failed', attempts, nextAttemptAt: new Date(now().getTime() + backoff(attempts, random)).toISOString(), error: { code, message } });
  }
}

export type TrackOutcome = { synced: number; failed: number; action: number; signIn: boolean };

export async function syncTracks(store: TrackStore, transport: TrackTransport, scope: string, now: () => Date = () => new Date(), random?: () => number): Promise<TrackOutcome> {
  const outcome: TrackOutcome = { synced: 0, failed: 0, action: 0, signIn: false };
  const due = (await store.tracks(scope)).filter((t) => trackDue(t, now())).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
  for (const t of due) {
    const after = await syncTrack(store, transport, scope, t.id, now, random);
    if (!after) continue;
    if (after.sync === 'synced') outcome.synced++;
    else if (after.sync === 'action_required') outcome.action++;
    else if (after.sync === 'failed') {
      outcome.failed++;
      if (after.error?.code === 'signed_out') {
        outcome.signIn = true;
        break;
      }
      if (after.error?.code === 'offline') break;
    }
  }
  return outcome;
}

/** Retry now, for routes waiting out a backoff. */
export async function retryTracks(store: TrackStore, scope: string, now: Date): Promise<number> {
  let n = 0;
  for (const t of await store.tracks(scope)) {
    if (t.sync === 'failed' && t.error?.code !== 'signed_out') {
      await store.saveTrack({ ...t, nextAttemptAt: null, updatedAt: now.toISOString() });
      n++;
    }
  }
  return n;
}

/** Ended routes the server holds leave the phone after KEEP_DAYS (the server keeps them). */
export async function purgeTracks(store: TrackStore, scope: string, now: Date): Promise<number> {
  let n = 0;
  for (const t of await store.tracks(scope)) {
    if (t.status === 'ended' && t.sync === 'synced' && now.getTime() - new Date(t.updatedAt).getTime() > KEEP_DAYS * 86_400_000) {
      await store.removeTrack(scope, t.id);
      n++;
    }
  }
  return n;
}

/** Records on the phone not yet confirmed, across routes. */
export const unsentRecords = (tracks: LocalTrack[]) => tracks.reduce((n, t) => n + Math.max(0, t.nextSeq - 1 - t.sentSeq), 0);
