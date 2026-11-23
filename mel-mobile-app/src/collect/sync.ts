import type { Answers } from './definition';
import { evaluate } from './rules';
import type { CollectStore, Item, Kit, StoredVersion } from './types';

/*
 * The field app's synchronization (ADR-0012 §9, ADR-0019 §6.3), with no
 * React Native in it: the store and the transport are passed in, so the
 * whole protocol runs in Jest against a transport that loses replies.
 *
 * Rules:
 * - A request without a reply is never counted as failed or as done: the
 *   item stays "syncing" (or "failed"), and the next attempt sends the same
 *   id. The server's replay answer settles it.
 * - An item is "synced" only once the server reports it past "receiving".
 * - Offline, timeouts, 429 and 5xx: "failed", retried with backoff and
 *   jitter (30 s up to 30 min). 401: wait for sign-in. 403, 404, 409, 422,
 *   426: "action_required", with the server's reason; never retried alone.
 * - Every state change is saved before the next request.
 */

export type TransportErrorKind = 'offline' | 'timeout' | 'http';

export class TransportError extends Error {
  constructor(
    public readonly kind: TransportErrorKind,
    message: string,
    public readonly status = 0,
    public readonly code: string | null = null,
    public readonly body: Record<string, unknown> | null = null,
  ) {
    super(message);
  }
}

export type SubmissionState = {
  id: string;
  status: string;
  missing: string[];
  revision: number;
  replayed?: boolean;
  correction?: { comment: string | null; notes: Record<string, string>; answers: Answers; formVersionId: string };
};

export type Changed = { data: { id: string; status: string; revision: number; updatedAt: string }[]; more: boolean };

export interface Transport {
  kit(): Promise<Kit>;
  version(id: string): Promise<StoredVersion>;
  submit(payload: Record<string, unknown>): Promise<SubmissionState>;
  upload(id: string, question: string, file: Item['photos'][string]): Promise<SubmissionState>;
  status(id: string): Promise<SubmissionState>;
  correct(id: string, body: { base_revision: number; answers: Answers; reason: string }): Promise<SubmissionState>;
  changed(since: string | null, after: string | null): Promise<Changed>;
}

export interface Files {
  remove(uri: string): Promise<void>;
}

export const BACKOFF_MIN_MS = 30_000;
export const BACKOFF_MAX_MS = 30 * 60_000;

/** 30 s, 60 s, 2 min… up to 30 min, ±20 %. */
export function backoff(attempts: number, random: () => number = Math.random): number {
  const base = Math.min(BACKOFF_MAX_MS, BACKOFF_MIN_MS * 2 ** Math.max(0, attempts - 1));
  return Math.round(base * (0.8 + random() * 0.4));
}

/** What a failed request means for the item. */
export function classify(error: unknown): 'retry' | 'signIn' | 'action' {
  if (!(error instanceof TransportError)) return 'retry';
  if (error.kind !== 'http') return 'retry';
  if (error.status === 401) return 'signIn';
  if (error.status === 429 || error.status >= 500) return 'retry';
  return [403, 404, 409, 410, 422, 426].includes(error.status) ? 'action' : 'retry';
}

/** The server's body for a submission. */
export function payload(item: Item, appVersion: string): Record<string, unknown> {
  return {
    id: item.id,
    form_version_id: item.formVersionId,
    programme_unit_id: item.unitId,
    answers: item.answers,
    consent_given: item.consentGiven,
    started_at: item.startedAt,
    completed_at: item.completedAt,
    app_version: appVersion,
    attachments: Object.entries(item.photos)
      .filter(([question]) => item.answers[question] !== undefined)
      .map(([question, file]) => ({ question, sha256: file.sha256, size: file.size, mime: file.mime })),
  };
}

/**
 * Finishes a draft: checked with the rules on the version it was filled
 * in on, then queued. Returns the errors when it cannot be finished.
 */
export async function finalize(store: CollectStore, item: Item, now: Date): Promise<Record<string, string>> {
  const version = await store.version(item.scope, item.formVersionId);
  if (!version) throw new Error('The form version is missing on this phone.');
  const completedAt = now.toISOString();
  const result = evaluate(version.definition, item.answers, localDay(now));
  if (Object.keys(result.errors).length > 0) return result.errors;
  await store.saveItem({ ...item, answers: result.answers, completedAt, status: 'ready', attempts: 0, nextAttemptAt: null, error: null, updatedAt: completedAt });
  return {};
}

export function localDay(now: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Items to send now: ready, syncing (interrupted) and failed whose wait is over. */
export function due(items: Item[], now: Date): Item[] {
  return items.filter(
    (item) =>
      item.status === 'ready' ||
      item.status === 'syncing' ||
      (item.status === 'failed' && (item.nextAttemptAt === null || new Date(item.nextAttemptAt) <= now)),
  );
}

export type SyncOutcome = { synced: number; failed: number; action: number; signIn: boolean };

/**
 * Sends everything due, one item at a time. Stops early when the server
 * wants a new sign-in or the phone is offline (nothing else would work).
 */
export async function syncAll(store: CollectStore, transport: Transport, files: Files, scope: string, appVersion: string, now: () => Date = () => new Date(), random?: () => number): Promise<SyncOutcome> {
  const outcome: SyncOutcome = { synced: 0, failed: 0, action: 0, signIn: false };
  for (const item of due(await store.items(scope), now())) {
    const after = await syncOne(store, transport, files, item, appVersion, now, random);
    if (after.status === 'synced') outcome.synced++;
    else if (after.status === 'action_required') outcome.action++;
    else if (after.status === 'failed') {
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

/** One item through the protocol. Always returns the item as saved. */
export async function syncOne(store: CollectStore, transport: Transport, files: Files, start: Item, appVersion: string, now: () => Date = () => new Date(), random?: () => number): Promise<Item> {
  let item: Item = { ...start, status: 'syncing', updatedAt: now().toISOString() };
  await store.saveItem(item);

  try {
    let state: SubmissionState;
    if (item.pendingCorrection) {
      const { answers, reason, baseRevision } = item.pendingCorrection;
      state = await transport.correct(item.id, { base_revision: baseRevision, answers, reason });
      item = { ...item, answers, pendingCorrection: null, correction: null };
    } else {
      state = await transport.submit(payload(item, appVersion));
    }
    item = await record(store, item, state, now);

    // Photos the server still waits for: from its own list, so an
    // interrupted upload resumes where it stopped.
    for (const question of state.missing) {
      const file = item.photos[question];
      if (!file) {
        return await stop(store, item, 'action', 'missing_photo', `The photo for "${question}" is no longer on this phone.`, now, random);
      }
      state = await transport.upload(item.id, question, file);
      item = await record(store, { ...item, photos: { ...item.photos, [question]: { ...file, uploaded: true } } }, state, now);
    }

    if (state.status === 'receiving') {
      // Ask where it stands: synced only on the server's word.
      state = await transport.status(item.id);
      item = await record(store, item, state, now);
      if (state.status === 'receiving') {
        return await stop(store, item, 'retry', 'incomplete', 'Some photos have not arrived yet.', now, random);
      }
    }

    item = { ...item, status: 'synced', attempts: 0, nextAttemptAt: null, error: null, syncedAt: now().toISOString() };
    await store.saveItem(item);
    // The photos are on the server now: free the phone.
    for (const file of Object.values(item.photos)) {
      await files.remove(file.uri).catch(() => undefined);
    }
    return item;
  } catch (error) {
    const kind = classify(error);
    const e = error instanceof TransportError ? error : null;
    if (kind === 'signIn') return stop(store, item, 'signIn', 'signed_out', 'Sign in again to send this.', now, random);
    if (kind === 'action') return stop(store, item, 'action', e?.code ?? `http_${e?.status}`, e?.message ?? 'The server refused this.', now, random);
    return stop(store, item, 'retry', e?.kind === 'http' ? `http_${e.status}` : (e?.kind ?? 'offline'), e?.message ?? 'No connection.', now, random);
  }
}

async function record(store: CollectStore, item: Item, state: SubmissionState, now: () => Date): Promise<Item> {
  const next = { ...item, serverStatus: state.status, serverRevision: state.revision, updatedAt: now().toISOString() };
  await store.saveItem(next);
  return next;
}

async function stop(store: CollectStore, item: Item, kind: 'retry' | 'signIn' | 'action', code: string, message: string, now: () => Date, random?: () => number): Promise<Item> {
  const attempts = item.attempts + (kind === 'retry' ? 1 : 0);
  const next: Item = {
    ...item,
    status: kind === 'action' ? 'action_required' : 'failed',
    attempts,
    nextAttemptAt: kind === 'retry' ? new Date(now().getTime() + backoff(attempts, random)).toISOString() : null,
    error: { code, message },
    updatedAt: now().toISOString(),
  };
  await store.saveItem(next);
  return next;
}

/**
 * Brings the phone's history up to date with the server's review: a
 * correction asked for becomes "action required", with the reviewer's notes.
 */
export async function pullStatuses(store: CollectStore, transport: Transport, scope: string, now: () => Date = () => new Date()): Promise<number> {
  let since = await store.meta(scope, 'changed.since');
  let after = await store.meta(scope, 'changed.after');
  let updated = 0;
  for (let page = 0; page < 20; page++) {
    const changed = await transport.changed(since, after);
    for (const row of changed.data) {
      const item = await store.item(scope, row.id);
      if (item && item.status === 'synced') {
        let next: Item = { ...item, serverStatus: row.status, serverRevision: row.revision, updatedAt: now().toISOString() };
        if (row.status === 'correction_requested') {
          const state = await transport.status(row.id);
          if (state.correction) {
            next = {
              ...next,
              status: 'action_required',
              answers: state.correction.answers,
              correction: { comment: state.correction.comment, notes: state.correction.notes ?? {}, baseRevision: state.revision },
              error: { code: 'correction_requested', message: state.correction.comment ?? 'A reviewer asked for a correction.' },
            };
          }
        }
        await store.saveItem(next);
        updated++;
      }
      since = row.updatedAt;
      after = row.id;
    }
    await store.setMeta(scope, 'changed.since', since);
    await store.setMeta(scope, 'changed.after', after);
    if (!changed.more) break;
  }
  return updated;
}

/**
 * Downloads the kit and the form versions the phone lacks. A version
 * already stored with the same hash is never fetched again.
 */
export async function refreshKit(store: CollectStore, transport: Transport, scope: string): Promise<Kit> {
  const kit = await transport.kit();
  for (const assignment of kit.assignments) {
    const wanted = assignment.version;
    if (!wanted) continue;
    const have = await store.version(scope, wanted.id);
    if (have?.sha256 === wanted.sha256) continue;
    await store.saveVersion(scope, await transport.version(wanted.id));
  }
  await store.saveKit(scope, kit);
  return kit;
}

/**
 * The collector's correction, kept here until it is sent. The reviewer's
 * base revision is what the server checks against.
 */
export async function queueCorrection(store: CollectStore, item: Item, answers: Answers, reason: string, now: Date): Promise<Record<string, string>> {
  const version = await store.version(item.scope, item.formVersionId);
  if (!version || !item.correction) throw new Error('No correction was asked for this submission.');
  const result = evaluate(version.definition, answers, (item.completedAt ?? now.toISOString()).slice(0, 10));
  if (Object.keys(result.errors).length > 0) return result.errors;
  await store.saveItem({
    ...item,
    pendingCorrection: { answers: result.answers, reason, baseRevision: item.correction.baseRevision },
    status: 'ready',
    attempts: 0,
    nextAttemptAt: null,
    error: null,
    updatedAt: now.toISOString(),
  });
  return {};
}

/**
 * "Retry failed": items waiting out their backoff become due now. Their
 * attempt count is kept, so the next failure waits as long as it would
 * have; refusals (action required) are not touched, a person must act.
 */
export async function retryNow(store: CollectStore, scope: string, now: Date): Promise<number> {
  let count = 0;
  for (const item of await store.items(scope)) {
    if (item.status === 'failed' && item.error?.code !== 'signed_out') {
      await store.saveItem({ ...item, nextAttemptAt: null, updatedAt: now.toISOString() });
      count++;
    }
  }
  return count;
}

/** Whether anything is due to be sent now (the timer syncs only then). */
export function anythingDue(items: Item[], now: Date): boolean {
  return due(items, now).length > 0;
}

/** Synced items past the workspace's keep-on-device days are removed. */
export async function purgeOld(store: CollectStore, scope: string, keepDays: number, now: Date): Promise<number> {
  let removed = 0;
  for (const item of await store.items(scope)) {
    if (item.status === 'synced' && item.syncedAt && now.getTime() - new Date(item.syncedAt).getTime() > keepDays * 86_400_000) {
      await store.removeItem(scope, item.id);
      removed++;
    }
  }
  return removed;
}

/** What signing out (or wiping) would leave unsent. */
export function unsent(items: Item[]): Item[] {
  return items.filter((item) => item.status !== 'synced');
}
