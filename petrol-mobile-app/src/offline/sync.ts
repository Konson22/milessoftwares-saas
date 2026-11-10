import { useEffect } from 'react';
import { create } from 'zustand';

import { request } from '@/lib/api/client';
import { getPage } from '@/lib/pages';
import { useAuth } from '@/store/auth';

import { scope, storeKit, type Kit } from './cache';
import { queuedFor, useOutbox } from './outbox';

const SEND_EVERY_MS = 20_000;
const PROBE_EVERY_MS = 15_000;
const REFRESH_EVERY_MS = 5 * 60_000;

/** Pages kept on the phone for selling without a connection. */
const KEEP = ['', 'sales/create', 'sales'];

interface SyncState {
  /** Bumped when what was sent changes what pages show: they load again. */
  version: number;
  running: boolean;
  lastSyncAt: string | null;
}

export const useSync = create<SyncState>(() => ({ version: 0, running: false, lastSyncAt: null }));

let running: Promise<void> | null = null;

/**
 * Brings the phone and the station level: sends what waits first (so the
 * station's pages include it), then keeps the sale form, today's sales and
 * the kit on the phone for when the connection goes.
 */
export function syncNow(): Promise<void> {
  running ??= (async () => {
    const owner = scope();
    if (!owner || useAuth.getState().status !== 'ready') return;
    useSync.setState({ running: true });
    try {
      const result = await useOutbox.getState().flush(owner);
      if (!result.stopped) {
        await keepForOffline();
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

/** The kit and the pages a seller needs (getPage keeps every page it loads). */
async function keepForOffline(): Promise<void> {
  try {
    await storeKit(await request<Kit>('GET', 'v1/outbox/kit'));
  } catch {
    // No permission for the kit, or unreachable: what was kept stays.
  }
  for (const path of KEEP) {
    await getPage(path).catch(() => {});
  }
}

/**
 * Keeps the phone in step while someone works: syncs on opening, when the
 * connection comes back, every 20 seconds while anything waits to be sent,
 * and refreshes what is kept every five minutes. Without a connection it
 * asks the station every 15 seconds whether it answers again.
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
