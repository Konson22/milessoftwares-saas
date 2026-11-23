import * as Crypto from 'expo-crypto';
import { create } from 'zustand';

import { files } from '@/logbook/capture';
import { SqliteStore } from '@/logbook/sqlite-store';
import { anythingDue, finalize, newItem, pullChanges, purgeOld, refreshKit, retryNow, syncAll, type SyncOutcome } from '@/logbook/sync';
import { api } from '@/logbook/transport';
import type { Item, Kit, KitSheet, LocalFile, Values } from '@/logbook/types';
import { uuidv7 } from '@/logbook/uuid';

/*
 * The screens' view of the phone's entries. All state lives in the
 * encrypted store; this keeps a copy of the current person's items for
 * rendering and runs one sync at a time.
 */

export const store = new SqliteStore();

interface LogbookState {
  scope: string | null;
  kit: Kit | null;
  items: Item[];
  /** Template version ids on the phone (usable offline). */
  downloaded: string[];
  syncing: boolean;
  downloading: boolean;
  lastSync: SyncOutcome | null;
  lastSyncAt: string | null;
  lastKitAt: string | null;
  kitError: string | null;

  open(scope: string | null): Promise<void>;
  reload(): Promise<void>;
  /** Sheets, then sending, then news from the server: on opening, coming back to the app, pulling down. */
  refresh(): Promise<void>;
  downloadSheets(): Promise<boolean>;
  sync(options?: { pull?: boolean }): Promise<SyncOutcome | null>;
  /** The timer's sync: only when something is due, so an idle phone makes no requests. */
  syncDue(): Promise<void>;
  retryFailed(): Promise<number>;
  /** A new draft; from a reminder, for its expected entry (one draft per expected entry). */
  start(sheet: KitSheet, options?: { occurrenceId?: string }): Promise<Item>;
  /** Keeps what was typed. A returned entry being corrected becomes a draft until it is sent again. */
  saveDraft(item: Item, changes: { values: Values; occurredAt: string; files: LocalFile[]; removedFiles: string[]; location?: Item['location']; trackId?: Item['trackId'] }): Promise<Item>;
  finish(item: Item): Promise<Record<string, string>>;
  discardDraft(item: Item): Promise<void>;
}

let running: Promise<SyncOutcome | null> | null = null;

async function downloadedIn(scope: string, kit: Kit | null, items: Item[]): Promise<string[]> {
  const ids = new Set<string>([...(kit?.sheets ?? []).map((s) => s.version.id ?? ''), ...items.map((i) => i.versionId)]);
  const have: string[] = [];
  for (const id of ids) if (id && (await store.version(scope, id))) have.push(id);
  return have;
}

export const useLogbook = create<LogbookState>((set, get) => ({
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
    const [kit, lastSyncAt, lastKitAt] = await Promise.all([store.kit(scope), store.meta(scope, 'sync.at'), store.meta(scope, 'kit.at')]);
    if (get().scope !== scope) return;
    set({ kit, lastSyncAt, lastKitAt });
    await get().reload();
    void get().refresh();
  },

  async reload() {
    const { scope, kit } = get();
    if (!scope) return;
    const items = await store.items(scope);
    const downloaded = await downloadedIn(scope, kit, items);
    if (get().scope === scope) set({ items, downloaded });
  },

  async refresh() {
    await get().downloadSheets();
    await get().sync({ pull: true });
  },

  async downloadSheets() {
    const { scope } = get();
    if (!scope) return false;
    set({ downloading: true });
    try {
      const kit = await refreshKit(store, api, scope);
      const at = new Date().toISOString();
      await store.setMeta(scope, 'kit.at', at);
      if (get().scope === scope) set({ kit, lastKitAt: at, kitError: null });
      await purgeOld(store, scope, new Date());
      await get().reload();
      return true;
    } catch (error) {
      // Offline: the sheets already on the phone keep working.
      if (get().scope === scope) set({ kitError: error instanceof Error ? error.message : 'Could not download the sheets.' });
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
        const outcome = await syncAll(store, api, files, scope);
        let pulled = false;
        if (options?.pull && !outcome.signIn) {
          pulled = await pullChanges(store, api, scope).then(
            () => true,
            () => false,
          );
          // Entries returned on sheets whose version the phone lacks.
          if (pulled) await refreshKit(store, api, scope).catch(() => undefined);
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

  async start(sheet, options = {}) {
    const { scope, items } = get();
    if (!scope || !sheet.version.id) throw new Error('This log sheet is not published yet.');
    // The same reminder tapped twice opens the draft already begun for it.
    const begun = options.occurrenceId ? items.find((i) => i.occurrenceId === options.occurrenceId && i.status === 'draft') : undefined;
    if (begun) return begun;
    const version = await store.version(scope, sheet.version.id);
    if (!version) throw new Error('This log sheet is not on the phone yet. Connect, then open Sync and update the sheets.');
    const item = newItem(uuidv7(Date.now(), (n) => Crypto.getRandomBytes(n)), scope, sheet, version, new Date(), options.occurrenceId ?? null);
    await store.saveItem(item);
    await get().reload();
    return item;
  },

  async saveDraft(item, changes) {
    const next: Item = {
      ...item,
      ...changes,
      // Being corrected: the server's news no longer overwrites it.
      status: item.status === 'action_required' ? 'draft' : item.status,
      updatedAt: new Date().toISOString(),
    };
    await store.saveItem(next);
    await get().reload();
    return next;
  },

  async finish(item) {
    const errors = await finalize(store, item, new Date());
    await get().reload();
    if (Object.keys(errors).length === 0) void get().sync();
    return errors;
  },

  async discardDraft(item) {
    // Only what never reached the server; the server keeps everything else.
    if (item.status !== 'draft' || item.serverId !== null) return;
    await store.removeItem(item.scope, item.id);
    for (const file of item.files) await files.remove(file.uri).catch(() => undefined);
    await get().reload();
  },
}));
