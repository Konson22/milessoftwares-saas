import { create } from 'zustand';

import type { Product } from '../../../shared/ipc';
import { api } from '@/lib/api';
import { disk } from '@/lib/storage';
import type { Item } from '@/lib/types';

/**
 * The branch's medicines (or the shop's products) on this PC, so the counter can find and sell them
 * with no connection. The pharmacy answers a search with at most 50, so the
 * catalog is gathered from the in-stock list and a search per letter and
 * digit, and every item any search returns is added. Stock is as of the last
 * sync; sales waiting in the outbox are taken off it where it is shown.
 */

interface CatalogState {
  /** The branch, for the product (catalogScope). */
  scope: string | null;
  items: Record<string, Item>;
  syncedAt: string | null;
  syncing: boolean;
  /** Loads the branch's saved catalog. */
  load(scope: string): Promise<void>;
  /** Fetches the catalog afresh; resolves false when the pharmacy could not be reached. */
  sync(): Promise<boolean>;
  /** Fresher details from a search or a lookup. */
  merge(items: Item[]): void;
}

const SWEEP = 'abcdefghijklmnopqrstuvwxyz0123456789'.split('');
const keyOf = (scope: string) => `catalog.${scope}`;

/** A branch's catalog for one product: the pharmacy's keeps the key it had before there was a shop. */
export const catalogScope = (branchId: string, product: Product) => (product === 'pharmacy' ? branchId : `${product}:${branchId}`);

let saveTimer: ReturnType<typeof setTimeout> | null = null;

function save(state: Pick<CatalogState, 'scope' | 'items' | 'syncedAt'>) {
  if (!state.scope) return;
  if (saveTimer) clearTimeout(saveTimer);
  const { scope, items, syncedAt } = state;
  saveTimer = setTimeout(() => void disk.set(keyOf(scope), { items, syncedAt }), 500);
}

export const useCatalog = create<CatalogState>((set, get) => ({
  scope: null,
  items: {},
  syncedAt: null,
  syncing: false,

  async load(scope) {
    if (get().scope === scope) return;
    set({ scope, items: {}, syncedAt: null });
    const saved = await disk.get<{ items: Record<string, Item>; syncedAt: string | null }>(keyOf(scope));
    if (saved && get().scope === scope) set({ items: saved.items, syncedAt: saved.syncedAt });
  },

  async sync() {
    const scope = get().scope;
    if (!scope || get().syncing) return false;
    set({ syncing: true });
    try {
      const found: Record<string, Item> = {};
      for (const item of await api.items('')) found[item.id] = item;
      for (const term of SWEEP) {
        for (const item of await api.items(term)) found[item.id] = item;
      }
      if (get().scope !== scope) return false;
      set({ items: found, syncedAt: new Date().toISOString() });
      save(get());
      return true;
    } catch {
      return false;
    } finally {
      set({ syncing: false });
    }
  },

  merge(items) {
    if (items.length === 0) return;
    set((s) => ({ items: { ...s.items, ...Object.fromEntries(items.map((i) => [i.id, i])) } }));
    save(get());
  },
}));

/** An item with what is left to sell here once the outbox's sales are counted. */
export function withPending(item: Item, pending: Record<string, number>): Item {
  const units = pending[item.id];
  return units ? { ...item, available: Math.max(0, item.available - units) } : item;
}

/** By name, generic name or barcode, as the pharmacy searches; in stock first. */
export function searchCatalog(items: Record<string, Item>, search: string, limit = 60): Item[] {
  const q = search.trim().toLowerCase();
  const all = Object.values(items);
  const found = q ? all.filter((i) => [i.name, i.generic_name, i.barcode].some((v) => v?.toLowerCase().includes(q))) : all;
  return found
    .sort((a, b) => Number(b.available > 0) - Number(a.available > 0) || a.name.localeCompare(b.name))
    .slice(0, limit);
}

export function findByCode(items: Record<string, Item>, code: string): Item | null {
  const c = code.trim();
  return Object.values(items).find((i) => i.barcode === c) ?? null;
}
