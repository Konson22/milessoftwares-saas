import * as Crypto from 'expo-crypto';
import { create } from 'zustand';
import Constants from 'expo-constants';

import { files } from '@/collect/capture';
import type { Answers } from '@/collect/definition';
import { SqliteStore } from '@/collect/sqlite-store';
import { anythingDue, finalize, pullStatuses, purgeOld, queueCorrection, refreshKit, retryNow, syncAll, type SyncOutcome } from '@/collect/sync';
import { api } from '@/collect/transport';
import type { Assignment, Item, Kit, PhotoFile } from '@/collect/types';
import { uuidv7 } from '@/collect/uuid';

/*
 * The screens' view of the phone's collected work. All state lives in the
 * encrypted store; this keeps a copy of the current person's items for
 * rendering and runs one sync at a time.
 */

export const store = new SqliteStore();
const appVersion = Constants.expoConfig?.version ?? '0.0.0';

interface CollectState {
  scope: string | null;
  kit: Kit | null;
  items: Item[];
  /** Form version ids whose definition is on the phone (usable offline). */
  downloaded: string[];
  syncing: boolean;
  downloading: boolean;
  lastSync: SyncOutcome | null;
  lastSyncAt: string | null;
  /** When the forms were last downloaded from the server. */
  lastKitAt: string | null;
  kitError: string | null;

  open(scope: string | null): Promise<void>;
  reload(): Promise<void>;
  /** Forms, then sending, then review news: on opening, coming back to the app, pulling down. */
  refresh(): Promise<void>;
  /** Downloads the assigned forms and any new versions, without sending. */
  downloadForms(): Promise<boolean>;
  /** Sends what is due; with pull, also asks the server for review news. */
  sync(options?: { pull?: boolean }): Promise<SyncOutcome | null>;
  /** The timer's sync: only when something is due, so an idle phone makes no requests. */
  syncDue(): Promise<void>;
  retryFailed(): Promise<number>;
  start(assignment: Assignment): Promise<Item>;
  saveDraft(item: Item, answers: Answers, photos: Record<string, PhotoFile>, consent: boolean | null): Promise<Item>;
  finish(item: Item): Promise<Record<string, string>>;
  correct(item: Item, answers: Answers, reason: string): Promise<Record<string, string>>;
  discardDraft(item: Item): Promise<void>;
}

let running: Promise<SyncOutcome | null> | null = null;

async function downloadedIn(scope: string, kit: Kit | null): Promise<string[]> {
  const ids: string[] = [];
  for (const a of kit?.assignments ?? []) {
    if (!a.version) continue;
    const have = await store.version(scope, a.version.id);
    if (have?.sha256 === a.version.sha256) ids.push(a.version.id);
  }
  return ids;
}

export const useCollect = create<CollectState>((set, get) => ({
  scope: null,
  kit: null,
  items: [],
  downloaded: [],
  syncing: false,
  downloading: false,
  lastSync: null,
  lastSyncAt: null,
  lastKitAt: null,
  kitError: null,

  async open(scope) {
    set({ scope, kit: null, items: [], downloaded: [], lastSync: null, lastSyncAt: null, lastKitAt: null, kitError: null });
    if (!scope) return;
    const kit = await store.kit(scope);
    const [lastSyncAt, lastKitAt, downloaded] = await Promise.all([store.meta(scope, 'sync.at'), store.meta(scope, 'kit.at'), downloadedIn(scope, kit)]);
    if (get().scope !== scope) return;
    set({ kit, lastSyncAt, lastKitAt, downloaded });
    await get().reload();
    void get().refresh();
  },

  async reload() {
    const { scope } = get();
    if (!scope) return;
    const items = await store.items(scope);
    if (get().scope === scope) set({ items });
  },

  async refresh() {
    await get().downloadForms();
    await get().sync({ pull: true });
  },

  async downloadForms() {
    const { scope } = get();
    if (!scope) return false;
    set({ downloading: true });
    try {
      const kit = await refreshKit(store, api, scope);
      const at = new Date().toISOString();
      await store.setMeta(scope, 'kit.at', at);
      const downloaded = await downloadedIn(scope, kit);
      if (get().scope === scope) set({ kit, downloaded, lastKitAt: at, kitError: null });
      await purgeOld(store, scope, kit.settings.keepOnDeviceDays, new Date());
      return true;
    } catch (error) {
      // Offline: the forms already on the phone keep working.
      if (get().scope === scope) set({ kitError: error instanceof Error ? error.message : 'Could not download the forms.' });
      return false;
    } finally {
      set({ downloading: false });
    }
  },

  sync(options) {
    running ??= (async () => {
      const { scope } = get();
      if (!scope) return null;
      set({ syncing: true });
      try {
        const outcome = await syncAll(store, api, files, scope, appVersion);
        let pulled = false;
        if (options?.pull && !outcome.signIn) {
          pulled = await pullStatuses(store, api, scope).then(
            () => true,
            () => false,
          );
        }
        const at = new Date().toISOString();
        // "Last synchronized" means the server answered, not that we tried.
        if (!outcome.signIn && outcome.failed === 0 && (pulled || outcome.synced > 0)) {
          await store.setMeta(scope, 'sync.at', at);
          if (get().scope === scope) set({ lastSyncAt: at });
        }
        if (get().scope === scope) set({ lastSync: outcome });
        return outcome;
      } finally {
        set({ syncing: false });
        await get().reload();
      }
    })().finally(() => {
      running = null;
    });
    return running;
  },

  async syncDue() {
    if (anythingDue(get().items, new Date())) await get().sync();
  },

  async retryFailed() {
    const { scope } = get();
    if (!scope) return 0;
    const count = await retryNow(store, scope, new Date());
    await get().reload();
    void get().sync();
    return count;
  },

  async start(assignment) {
    const { scope } = get();
    if (!scope || !assignment.version) throw new Error('This form is not on the phone yet. Connect, then try again.');
    if (!(await store.version(scope, assignment.version.id))) throw new Error('This form is not downloaded yet. Connect, then open Sync and download the forms.');
    const now = new Date().toISOString();
    const item: Item = {
      id: uuidv7(Date.now(), (n) => Crypto.getRandomBytes(n)),
      scope,
      formId: assignment.form.id,
      formName: assignment.form.name,
      formVersionId: assignment.version.id,
      unitId: assignment.unit?.id ?? null,
      answers: {},
      consentGiven: null,
      startedAt: now,
      completedAt: null,
      status: 'draft',
      attempts: 0,
      nextAttemptAt: null,
      error: null,
      photos: {},
      serverStatus: null,
      serverRevision: 0,
      correction: null,
      pendingCorrection: null,
      syncedAt: null,
      updatedAt: now,
    };
    await store.saveItem(item);
    await get().reload();
    return item;
  },

  async saveDraft(item, answers, photos, consent) {
    const next: Item = { ...item, answers, photos, consentGiven: consent, updatedAt: new Date().toISOString() };
    await store.saveItem(next);
    return next;
  },

  async finish(item) {
    const errors = await finalize(store, item, new Date());
    await get().reload();
    if (Object.keys(errors).length === 0) void get().sync();
    return errors;
  },

  async correct(item, answers, reason) {
    const errors = await queueCorrection(store, item, answers, reason, new Date());
    await get().reload();
    if (Object.keys(errors).length === 0) void get().sync();
    return errors;
  },

  async discardDraft(item) {
    if (item.status !== 'draft') return;
    await store.removeItem(item.scope, item.id);
    for (const photo of Object.values(item.photos)) await files.remove(photo.uri).catch(() => undefined);
    await get().reload();
  },
}));
