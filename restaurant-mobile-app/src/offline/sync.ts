import { useEffect } from 'react';
import { create } from 'zustand';

import { request } from '@/lib/api/client';
import { getPage } from '@/lib/pages';
import { useAuth } from '@/store/auth';

import { forgetPage, scope, storeKit, type Kit } from './cache';
import { queuedFor, useOutbox } from './outbox';

const SEND_EVERY_MS = 20_000;
const PROBE_EVERY_MS = 15_000;
const REFRESH_EVERY_MS = 5 * 60_000;
/** Open orders whose pages the phone keeps, at most. */
const KEEP_ORDERS = 40;

interface SyncState {
  /** Bumped when what was sent changes what pages show: they load again. */
  version: number;
  running: boolean;
  lastSyncAt: string | null;
  /** Ids made on the phone that the restaurant knows by another (an order joined, a bill numbered). */
  renamed: Record<string, string>;
}

export const useSync = create<SyncState>(() => ({ version: 0, running: false, lastSyncAt: null, renamed: {} }));

let running: Promise<void> | null = null;

/**
 * Brings the phone and the restaurant level: sends what waits first (so the
 * restaurant's floor includes it), then keeps the floor, its open orders and
 * the menu on the phone for when the connection goes.
 */
export function syncNow(): Promise<void> {
  running ??= (async () => {
    const owner = scope();
    if (!owner || useAuth.getState().status !== 'ready') return;
    useSync.setState({ running: true });
    try {
      const result = await useOutbox.getState().flush(owner);
      if (Object.keys(result.renamed).length > 0) {
        useSync.setState((s) => ({ renamed: { ...s.renamed, ...result.renamed } }));
        for (const from of Object.keys(result.renamed)) {
          await forgetPage(`orders/${from}`);
          await forgetPage(`bills/${from}`);
        }
      }
      if (!result.stopped) {
        await keepForOffline(result.touched);
        useSync.setState({ lastSyncAt: new Date().toISOString() });
      }
      if (result.sent > 0 || result.refused > 0) useSync.setState((s) => ({ version: s.version + 1 }));
    } finally {
      useSync.setState({ running: false });
    }
  })().finally(() => {
    // After the assignment, even when the run returned straight away.
    running = null;
  });
  return running;
}

/** The kit, the floor and its open orders, kept on the phone (getPage keeps every page it loads). */
async function keepForOffline(touched: string[]): Promise<void> {
  try {
    await storeKit(await request<Kit>('GET', 'v1/outbox/kit'));
    const floor = await getPage<{ tables: { order: { id: string } | null }[]; takeaways: { id: string }[] }>('floor');
    const open = [...floor.props.tables.map((t) => t.order?.id).filter((id): id is string => !!id), ...floor.props.takeaways.map((t) => t.id)];
    for (const id of [...new Set([...open, ...touched])].slice(0, KEEP_ORDERS)) {
      await getPage(`orders/${id}`).catch(() => {});
    }
  } catch {
    // Unreachable, or no permission for the floor: what was kept stays.
  }
}

/**
 * Keeps the phone in step while someone works: syncs on opening, when the
 * connection comes back, every 20 seconds while anything waits to be sent,
 * and refreshes what is kept every five minutes. Without a connection it
 * asks the restaurant every 15 seconds whether it answers again.
 */
export function useSyncEngine() {
  const ready = useAuth((s) => s.status === 'ready');
  const reachable = useAuth((s) => s.reachable);
  const owner = useAuth((s) => (s.workspace && s.branchId && s.me ? `${s.workspace}|${s.branchId}|${s.me.user.id}` : null));
  const waiting = useOutbox((s) => queuedFor(s.entries, owner).waiting.length);

  useEffect(() => {
    if (!ready || !owner) return;
    void syncNow();
    const timer = setInterval(() => void syncNow(), REFRESH_EVERY_MS);
    return () => clearInterval(timer);
  }, [ready, owner]);

  // Something new waits, or the connection is back: send it.
  useEffect(() => {
    if (!ready || !reachable || waiting === 0) return;
    void syncNow();
    const timer = setInterval(() => void syncNow(), SEND_EVERY_MS);
    return () => clearInterval(timer);
  }, [ready, reachable, waiting]);

  // No connection: ask now and then (a call that answers sets it back).
  useEffect(() => {
    if (!ready || reachable) return;
    const timer = setInterval(() => void request('GET', 'v1/me').catch(() => {}), PROBE_EVERY_MS);
    return () => clearInterval(timer);
  }, [ready, reachable]);
}
