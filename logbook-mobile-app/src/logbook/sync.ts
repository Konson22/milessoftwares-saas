import { activityBody } from './activity';
import { clean, defaults } from './fields';
import type { EntryLocation, Item, Kit, KitSheet, LocalFile, LogbookStore, Review, ServerFile, ServerStatus, StoredVersion, Values } from './types';

/*
 * The phone's synchronization with logbook-app's API (routes/api.php),
 * with no React Native in it: the store and the transport are passed in,
 * so the whole protocol runs in Jest against a transport that loses
 * replies.
 *
 * An entry reaches the server in steps, each safe to repeat:
 * 1. POST entries (first time: a draft keyed by the phone's id), or
 *    PUT entries/{id} from the revision the phone holds (a correction, or
 *    a retry after the draft exists);
 * 2. files taken off in a correction are removed, new files uploaded one
 *    by one (the server deduplicates by hash);
 * 3. POST entries/{id}/submit with the revision it creates.
 *
 * Rules:
 * - A request without a reply is never counted as done: the item stays
 *   "syncing" or "failed", and the next attempt repeats the same steps.
 * - An item is "synced" only once the server reports it submitted.
 * - Offline, timeouts, 429 and 5xx: "failed", retried with backoff and
 *   jitter (30 s up to 30 min). 401: wait for sign-in. 403, 404, 409, 410,
 *   422: "action_required" with the server's reason; never retried alone.
 * - Every state change is saved before the next request.
 */

export type TransportErrorKind = 'offline' | 'timeout' | 'http';

export class TransportError extends Error {
  constructor(
    public readonly kind: TransportErrorKind,
    message: string,
    public readonly status = 0,
    public readonly code: string | null = null,
    public readonly errors: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

/** An entry as the server answers it (EntryController::entry). */
export type ServerEntry = {
  id: string;
  key: string | null;
  reference: string | null;
  status: ServerStatus;
  revision: number;
  reviewRequired: boolean;
  sheet: { id: string; name: string; code: string };
  logbook: string;
  versionId: string;
  occurredAt: string | null;
  submittedAt: string | null;
  updatedAt: string | null;
  values: Values;
  files: (ServerFile & { sha256: string | null })[];
  lastReview: Review | null;
  /** ADR-0030; absent from servers before it. */
  shiftId?: string | null;
  activityStartedAt?: string | null;
  activityEndedAt?: string | null;
};

export interface Transport {
  kit(): Promise<Kit>;
  version(id: string): Promise<StoredVersion>;
  create(body: { id: string; sheet_id: string; version_id: string; occurred_at: string; values: Values; submit: boolean; occurrence_id?: string | null } & Place & Record<string, unknown>): Promise<ServerEntry>;
  update(id: string, body: { base_revision: number; occurred_at: string; values: Values } & Place & Record<string, unknown>): Promise<ServerEntry>;
  upload(id: string, file: LocalFile): Promise<{ id: string }>;
  removeFile(id: string): Promise<void>;
  submit(id: string, revision: number): Promise<ServerEntry>;
  changed(since: string | null): Promise<{ serverTime: string; complete: boolean; entries: ServerEntry[] }>;
}

/** GPS (ADR-0028): sent only for entries that have it, so other sheets are untouched. */
export type Place = { location?: EntryLocation | null; track_id?: string | null };

export function place(item: Pick<Item, 'location' | 'trackId'>): Place {
  return item.location !== undefined || item.trackId ? { location: item.location ?? null, track_id: item.trackId ?? null } : {};
}

export interface Files {
  remove(uri: string): Promise<void>;
}

export const BACKOFF_MIN_MS = 30_000;
export const BACKOFF_MAX_MS = 30 * 60_000;
/** Entries the server reports are kept on the phone this long after their last change. */
export const KEEP_DAYS = 90;

/** 30 s, 60 s, 2 min… up to 30 min, ±20 %. */
export function backoff(attempts: number, random: () => number = Math.random): number {
  const base = Math.min(BACKOFF_MAX_MS, BACKOFF_MIN_MS * 2 ** Math.max(0, attempts - 1));
  return Math.round(base * (0.8 + random() * 0.4));
}

/** What a failed request means for the item. */
export function classify(error: unknown): 'retry' | 'signIn' | 'action' {
  if (!(error instanceof TransportError) || error.kind !== 'http') return 'retry';
  if (error.status === 401) return 'signIn';
  if (error.status === 429 || error.status >= 500) return 'retry';
  return [400, 403, 404, 409, 410, 413, 422].includes(error.status) ? 'action' : 'retry';
}

/** File counts by field: new files plus the server's that stay. */
export function fileCounts(item: Pick<Item, 'files' | 'serverFiles' | 'removedFiles'>): Record<string, number> {
  const counts: Record<string, number> = {};
  for (const f of item.files) counts[f.field] = (counts[f.field] ?? 0) + 1;
  for (const f of item.serverFiles) if (!item.removedFiles.includes(f.id)) counts[f.field] = (counts[f.field] ?? 0) + 1;
  return counts;
}

/** Whether a person can change the item on the phone now. */
export const editable = (item: Item) => item.status === 'draft' || (item.status === 'action_required' && item.serverStatus !== 'submitted' && item.serverStatus !== 'reviewed' && item.serverStatus !== 'finalized');

/** A new draft on a sheet's current version, with the template's defaults. */
export function newItem(id: string, scope: string, sheet: KitSheet, version: StoredVersion, now: Date, occurrenceId: string | null = null): Item {
  const at = now.toISOString();
  return {
    id,
    scope,
    sheet: { id: sheet.id, name: sheet.name, code: sheet.code },
    logbook: sheet.logbook.name,
    versionId: version.id,
    occurredAt: at,
    values: defaults(version.fields),
    files: [],
    serverFiles: [],
    removedFiles: [],
    status: 'draft',
    attempts: 0,
    nextAttemptAt: null,
    error: null,
    serverId: null,
    revision: 0,
    serverStatus: null,
    reference: null,
    reviewRequired: sheet.reviewRequired,
    lastReview: null,
    submittedAt: null,
    syncedAt: null,
    createdAt: at,
    updatedAt: at,
    occurrenceId,
  };
}

/**
 * Finishes an entry: checked in full on the version it was written on,
 * then queued to send. Returns the errors when it cannot be finished.
 */
export async function finalize(store: LogbookStore, item: Item, now: Date): Promise<Record<string, string>> {
  const version = await store.version(item.scope, item.versionId);
  if (!version) throw new Error('The template of this entry is missing on this phone. Connect, then update the sheets.');
  const result = clean(version.fields, item.values, fileCounts(item), true);
  if (Object.keys(result.errors).length > 0) return result.errors;
  await store.saveItem({ ...item, values: result.values, status: 'ready', attempts: 0, nextAttemptAt: null, error: null, updatedAt: now.toISOString() });
  return {};
}

/** Items to send now: ready, syncing (interrupted) and failed whose wait is over. */
export function due(items: Item[], now: Date): Item[] {
  return items.filter(
    (item) =>
      item.status === 'ready' || item.status === 'syncing' || (item.status === 'failed' && item.error?.code !== 'signed_out' && (item.nextAttemptAt === null || new Date(item.nextAttemptAt) <= now)),
  );
}

export const anythingDue = (items: Item[], now: Date) => due(items, now).length > 0;

export type SyncOutcome = { synced: number; failed: number; action: number; signIn: boolean };

/**
 * Sends everything due, one entry at a time. Stops early when the server
 * wants a new sign-in or the phone is offline (nothing else would work).
 */
export async function syncAll(store: LogbookStore, transport: Transport, files: Files, scope: string, now: () => Date = () => new Date(), random?: () => number): Promise<SyncOutcome> {
  const outcome: SyncOutcome = { synced: 0, failed: 0, action: 0, signIn: false };
  const items = due(await store.items(scope), now()).sort((a, b) => a.createdAt.localeCompare(b.createdAt));
  for (const item of items) {
    const after = await syncOne(store, transport, files, item, now, random);
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

/** One entry through the steps. Always returns the item as saved. */
export async function syncOne(store: LogbookStore, transport: Transport, files: Files, start: Item, now: () => Date = () => new Date(), random?: () => number): Promise<Item> {
  let item: Item = { ...start, status: 'syncing', updatedAt: now().toISOString() };
  await store.saveItem(item);
  const save = async (next: Item) => {
    item = { ...next, updatedAt: now().toISOString() };
    await store.saveItem(item);
  };

  try {
    // 1. The entry on the server, with the phone's values.
    let entry: ServerEntry;
    if (item.serverId === null) {
      entry = await transport.create({ id: item.id, sheet_id: item.sheet.id, version_id: item.versionId, occurred_at: item.occurredAt, values: plain(item.values), submit: false, occurrence_id: item.scheduleLink === 'extra' ? null : (item.occurrenceId ?? null), ...place(item), ...activityBody(item) });
    } else {
      entry = await transport.update(item.serverId, { base_revision: item.revision, occurred_at: item.occurredAt, values: plain(item.values), ...place(item), ...activityBody(item) });
    }
    await save({ ...item, serverId: entry.id });

    // Already submitted by an earlier attempt whose reply was lost.
    if (entry.revision > item.revision) return await done(store, files, item, entry, now);

    // 2. Files: removals, then uploads one by one.
    for (const id of [...item.removedFiles]) {
      await transport.removeFile(id);
      await save({ ...item, removedFiles: item.removedFiles.filter((x) => x !== id), serverFiles: item.serverFiles.filter((f) => f.id !== id) });
    }
    for (const file of item.files) {
      if (file.serverId) continue;
      const uploaded = await transport.upload(entry.id, file);
      await save({ ...item, files: item.files.map((f) => (f.uri === file.uri ? { ...f, serverId: uploaded.id } : f)) });
    }

    // 3. Submitted: the revision it creates.
    entry = await transport.submit(entry.id, item.revision + 1);
    return await done(store, files, item, entry, now);
  } catch (error) {
    const kind = classify(error);
    const e = error instanceof TransportError ? error : null;
    if (kind === 'signIn') return stop(store, item, 'signIn', 'signed_out', 'Sign in again to send this.', now, random);
    if (kind === 'action') {
      const fields = e ? fieldErrors(e.errors) : {};
      return stop(store, item, 'action', e?.code ?? `http_${e?.status}`, e?.message ?? 'The server refused this entry.', now, random, fields);
    }
    return stop(store, item, 'retry', e?.kind === 'http' ? `http_${e.status}` : (e?.kind ?? 'offline'), e?.message ?? 'No connection.', now, random);
  }
}

/** The server's validation errors by field ("values.hours" → "hours"). */
function fieldErrors(errors: Record<string, string[]>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, messages] of Object.entries(errors)) out[key.replace(/^values\./, '')] = messages[0] ?? '';
  return out;
}

/** JSON-safe values (no undefined). */
function plain(values: Values): Values {
  return Object.fromEntries(Object.entries(values).filter(([, v]) => v !== undefined));
}

async function done(store: LogbookStore, files: Files, item: Item, entry: ServerEntry, now: () => Date): Promise<Item> {
  const next = { ...fromServer(item, entry), status: 'synced' as const, attempts: 0, nextAttemptAt: null, error: null, syncedAt: now().toISOString(), updatedAt: now().toISOString() };
  await store.saveItem(next);
  // The files are on the server now: free the phone.
  for (const file of item.files) await files.remove(file.uri).catch(() => undefined);
  return next;
}

/** The item with what the server says about its entry. */
function fromServer(item: Item, entry: ServerEntry): Item {
  return {
    ...item,
    serverId: entry.id,
    reference: entry.reference,
    serverStatus: entry.status,
    revision: entry.revision,
    reviewRequired: entry.reviewRequired,
    lastReview: entry.lastReview,
    submittedAt: entry.submittedAt,
    values: entry.values ?? item.values,
    files: [],
    removedFiles: [],
    serverFiles: entry.files.map(({ id, field, name, size, mime }) => ({ id, field, name, size, mime })),
    // ADR-0030: only when the server says; the phone's own otherwise.
    ...(entry.shiftId !== undefined ? { shiftId: entry.shiftId } : {}),
    ...(entry.activityStartedAt !== undefined ? { activityStartedAt: entry.activityStartedAt } : {}),
    ...(entry.activityEndedAt !== undefined ? { activityEndedAt: entry.activityEndedAt } : {}),
  };
}

async function stop(
  store: LogbookStore,
  item: Item,
  kind: 'retry' | 'signIn' | 'action',
  code: string,
  message: string,
  now: () => Date,
  random?: () => number,
  fields?: Record<string, string>,
): Promise<Item> {
  const attempts = item.attempts + (kind === 'retry' ? 1 : 0);
  const next: Item = {
    ...item,
    status: kind === 'action' ? 'action_required' : 'failed',
    attempts,
    nextAttemptAt: kind === 'retry' ? new Date(now().getTime() + backoff(attempts, random)).toISOString() : null,
    error: { code, message, ...(fields && Object.keys(fields).length > 0 ? { fields } : {}) },
    updatedAt: now().toISOString(),
  };
  await store.saveItem(next);
  return next;
}

/**
 * Brings the phone up to date with the server: references, reviews, and
 * entries returned for correction, which become "action required" with
 * the reviewer's comment and the server's values. Entries the member wrote
 * elsewhere (on the web) are kept too, so the phone's list is complete.
 * Work the phone has not sent yet is never overwritten.
 */
export async function pullChanges(store: LogbookStore, transport: Transport, scope: string, now: () => Date = () => new Date()): Promise<number> {
  const since = await store.meta(scope, 'changed.since');
  const changed = await transport.changed(since);
  const items = await store.items(scope);
  const byServer = new Map(items.filter((i) => i.serverId).map((i) => [i.serverId as string, i]));
  let updated = 0;

  for (const entry of changed.entries) {
    const local = (entry.key ? await store.item(scope, entry.key) : null) ?? byServer.get(entry.id) ?? null;
    // Drafts are the author's own work in progress where they started it.
    if (!local && entry.status === 'draft') continue;
    // Unsent changes on the phone win until they are sent.
    if (local && local.status !== 'synced' && !(local.status === 'action_required' && local.error?.code === 'returned')) continue;

    const base: Item = local ?? {
      id: entry.key ?? entry.id,
      scope,
      sheet: entry.sheet,
      logbook: entry.logbook,
      versionId: entry.versionId,
      occurredAt: entry.occurredAt ?? now().toISOString(),
      values: {},
      files: [],
      serverFiles: [],
      removedFiles: [],
      status: 'synced',
      attempts: 0,
      nextAttemptAt: null,
      error: null,
      serverId: entry.id,
      revision: 0,
      serverStatus: null,
      reference: null,
      reviewRequired: null,
      lastReview: null,
      submittedAt: null,
      syncedAt: now().toISOString(),
      createdAt: entry.occurredAt ?? now().toISOString(),
      updatedAt: now().toISOString(),
    };
    let next: Item = { ...fromServer(base, entry), occurredAt: entry.occurredAt ?? base.occurredAt, updatedAt: now().toISOString() };
    if (entry.status === 'returned') {
      next = {
        ...next,
        status: 'action_required',
        error: { code: 'returned', message: entry.lastReview?.comment ?? 'A reviewer returned this entry for correction.' },
      };
    } else {
      next = { ...next, status: 'synced', error: null };
    }
    await store.saveItem(next);
    updated++;
  }

  if (changed.complete) await store.setMeta(scope, 'changed.since', changed.serverTime);
  return updated;
}

/**
 * Downloads the kit and the template versions the phone lacks, including
 * the versions of entries returned for correction. Published versions
 * never change, so one already stored is never fetched again.
 */
export async function refreshKit(store: LogbookStore, transport: Transport, scope: string): Promise<Kit> {
  const kit = await transport.kit();
  const wanted = new Set(kit.sheets.map((s) => s.version.id).filter((id): id is string => !!id));
  for (const item of await store.items(scope)) if (item.status !== 'synced') wanted.add(item.versionId);
  for (const id of wanted) {
    if (await store.version(scope, id)) continue;
    try {
      await store.saveVersion(scope, await transport.version(id));
    } catch (error) {
      // A version out of reach now (a sheet closed): its entries say so when sent.
      if (!(error instanceof TransportError && error.kind === 'http' && error.status === 404)) throw error;
    }
  }
  await store.saveKit(scope, kit);
  return kit;
}

/**
 * "Retry failed": items waiting out their backoff become due now. Their
 * attempt count is kept; refusals (action required) are not touched.
 */
export async function retryNow(store: LogbookStore, scope: string, now: Date): Promise<number> {
  let count = 0;
  for (const item of await store.items(scope)) {
    if (item.status === 'failed' && item.error?.code !== 'signed_out') {
      await store.saveItem({ ...item, nextAttemptAt: null, updatedAt: now.toISOString() });
      count++;
    }
  }
  return count;
}

/** Synced entries not changed for KEEP_DAYS leave the phone (the server keeps them). */
export async function purgeOld(store: LogbookStore, scope: string, now: Date): Promise<number> {
  let removed = 0;
  for (const item of await store.items(scope)) {
    if (item.status === 'synced' && now.getTime() - new Date(item.updatedAt).getTime() > KEEP_DAYS * 86_400_000) {
      await store.removeItem(scope, item.id);
      removed++;
    }
  }
  return removed;
}

/** What signing out would leave unsent. */
export const unsent = (items: Item[]) => items.filter((item) => item.status !== 'synced');

/** Groups for the screens. */
export function buckets(items: Item[]) {
  return {
    drafts: items.filter((i) => i.status === 'draft'),
    waiting: items.filter((i) => i.status === 'ready' || i.status === 'syncing' || i.status === 'failed'),
    returned: items.filter((i) => i.status === 'action_required' && i.error?.code === 'returned'),
    refused: items.filter((i) => i.status === 'action_required' && i.error?.code !== 'returned'),
    inReview: items.filter((i) => i.status === 'synced' && i.serverStatus === 'submitted'),
    done: items.filter((i) => i.status === 'synced' && (i.serverStatus === 'reviewed' || i.serverStatus === 'finalized')),
  };
}

export type { LocalFile };
