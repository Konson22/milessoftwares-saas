import AsyncStorage from '@react-native-async-storage/async-storage';

import type { AppPage } from '@/lib/pages';
import { useAuth } from '@/store/auth';

/**
 * The station's pages as this phone last saw them, kept on the phone:
 * shown when the station cannot be reached. One copy per workspace, branch
 * and person: what a page shows depends on all three.
 */

const PREFIX = 'miles-petrol.page:';
const KIT_KEY = 'miles-petrol.kit:';

/** What the phone keeps to sell offline (GET api/v1/outbox/kit). */
export interface Kit {
  branch_id: string;
  currency: string;
  currencies: { code: string; name: string; symbol: string; decimals: number }[];
  methods: { id: string; name: string; kind: string }[];
  customers: { id: string; name: string }[];
}

/** Whose pages: the workspace, branch and person signed in; null when signed out. */
export function scope(): string | null {
  const { workspace, branchId, me } = useAuth.getState();
  if (!workspace || !branchId || !me) return null;
  return `${workspace}|${branchId}|${me.user.id}`;
}

const clean = (path: string) => path.replace(/^\//, '');
const key = (s: string, path: string) => `${PREFIX}${s}:${clean(path)}`;

/** Read once from the phone's storage, then from memory. */
const memory = new Map<string, AppPage | null>();

export async function cachedPage<P = Record<string, any>>(path: string): Promise<AppPage<P> | null> {
  const s = scope();
  if (!s) return null;
  const k = key(s, path);
  if (!memory.has(k)) {
    try {
      const raw = await AsyncStorage.getItem(k);
      memory.set(k, raw ? (JSON.parse(raw) as AppPage) : null);
    } catch {
      memory.set(k, null);
    }
  }
  return (memory.get(k) as AppPage<P> | null) ?? null;
}

export async function storePage(path: string, page: AppPage): Promise<void> {
  const s = scope();
  if (!s) return;
  const k = key(s, path);
  memory.set(k, page);
  try {
    await AsyncStorage.setItem(k, JSON.stringify(page));
  } catch {
    // A full phone: the page is still kept in memory.
  }
}

export async function forgetPage(path: string): Promise<void> {
  const s = scope();
  if (!s) return;
  const k = key(s, path);
  memory.delete(k);
  await AsyncStorage.removeItem(k).catch(() => {});
}

/** Changes a kept page; nothing when the phone has none. */
export async function changePage<P>(path: string, change: (page: AppPage<P>) => AppPage<P> | null): Promise<void> {
  const page = await cachedPage<P>(path);
  if (!page) return;
  const next = change(page);
  if (next) await storePage(path, next as AppPage);
  else await forgetPage(path);
}

export async function cachedKit(): Promise<Kit | null> {
  const s = scope();
  if (!s) return null;
  try {
    const raw = await AsyncStorage.getItem(KIT_KEY + s);
    return raw ? (JSON.parse(raw) as Kit) : null;
  } catch {
    return null;
  }
}

export async function storeKit(kit: Kit): Promise<void> {
  const s = scope();
  if (!s) return;
  await AsyncStorage.setItem(KIT_KEY + s, JSON.stringify(kit)).catch(() => {});
}

/** Signing out: this person's pages leave the phone (what waits to be sent stays). */
export async function forgetPages(): Promise<void> {
  memory.clear();
  try {
    const keys = await AsyncStorage.getAllKeys();
    await AsyncStorage.multiRemove(keys.filter((k) => k.startsWith(PREFIX) || k.startsWith(KIT_KEY)));
  } catch {
    // Nothing to do: they are replaced as pages load again.
  }
}
