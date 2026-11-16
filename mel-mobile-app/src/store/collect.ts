import * as Crypto from 'expo-crypto';
import { create } from 'zustand';
import Constants from 'expo-constants';

import { files } from '@/collect/capture';
import type { Answers } from '@/collect/definition';
import { SqliteStore } from '@/collect/sqlite-store';
import { finalize, pullStatuses, purgeOld, queueCorrection, refreshKit, syncAll, type SyncOutcome } from '@/collect/sync';
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
  syncing: boolean;
  lastSync: SyncOutcome | null;
  lastSyncAt: string | null;
  kitError: string | null;

  open(scope: string | null): Promise<void>;
  reload(): Promise<void>;
  refresh(): Promise<void>;
  sync(): Promise<SyncOutcome | null>;
  start(assignment: Assignment): Promise<Item>;
  saveDraft(item: Item, answers: Answers, photos: Record<string, PhotoFile>, consent: boolean | null): Promise<Item>;
  finish(item: Item): Promise<Record<string, string>>;
  correct(item: Item, answers: Answers, reason: string): Promise<Record<string, string>>;
  discardDraft(item: Item): Promise<void>;
}

let running: Promise<SyncOutcome | null> | null = null;

export const useCollect = create<CollectState>((set, get) => ({
  scope: null,
  kit: null,
  items: [],
  syncing: false,
  lastSync: null,
  lastSyncAt: null,
  kitError: null,

  async open(scope) {
    set({ scope, kit: null, items: [], lastSync: null, kitError: null });
    if (!scope) return;
    const kit = await store.kit(scope);
    set({ kit });
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
    const { scope } = get();
    if (!scope) return;
    try {
      const kit = await refreshKit(store, api, scope);
      if (get().scope === scope) set({ kit, kitError: null });
      await purgeOld(store, scope, kit.settings.keepOnDeviceDays, new Date());
    } catch (error) {
      // Offline: the forms already on the phone keep working.
      set({ kitError: error instanceof Error ? error.message : 'Could not refresh the forms.' });
    }
    await get().sync();
  },

  sync() {
    running ??= (async () => {
      const { scope } = get();
      if (!scope) return null;
      set({ syncing: true });
      try {
        const outcome = await syncAll(store, api, files, scope, appVersion);
        if (!outcome.signIn) await pullStatuses(store, api, scope).catch(() => 0);
        set({ lastSync: outcome, lastSyncAt: new Date().toISOString() });
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

  async start(assignment) {
    const { scope } = get();
    if (!scope || !assignment.version) throw new Error('This form is not on the phone yet. Connect, then try again.');
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
