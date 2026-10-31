import { useEffect } from 'react';
import { create } from 'zustand';

import { api } from '@/lib/api';
import { isLocal } from '@/lib/mode';
import { useAuth } from '@/store/auth';
import { holdOwner } from '@/store/cart';

import { catalogScope, useCatalog } from './catalog';
import { onBackOnline, useConnectivity } from './connectivity';
import { queuedFor, useOutbox } from './outbox';

const FLUSH_EVERY_MS = 20_000;
const PROBE_EVERY_MS = 15_000;
const CATALOG_EVERY_MS = 10 * 60_000;

interface SyncState {
  running: boolean;
  lastSyncAt: string | null;
  lastResult: { sent: number; refused: number } | null;
}

export const useSync = create<SyncState>(() => ({ running: false, lastSyncAt: null, lastResult: null }));

let running: Promise<void> | null = null;

/**
 * Brings this PC and the pharmacy level: sends the outbox first (so the
 * pharmacy's stock and shift include what was sold offline), then fetches
 * the shift, the counter's rules and, when it is due, the catalog.
 */
export function syncNow(options: { catalog?: boolean } = {}): Promise<void> {
  running ??= (async () => {
    const { status, me, branchId, product } = useAuth.getState();
    if (status !== 'ready' || !branchId || isLocal()) return;
    useSync.setState({ running: true });
    try {
      const owner = holdOwner(me?.user.id, branchId, product);
      const result = await useOutbox.getState().flush(owner);
      useSync.setState({ lastResult: result });
      if (!useConnectivity.getState().online) return;

      await Promise.allSettled([useAuth.getState().refreshCounter(), useAuth.getState().refreshTill()]);
      const catalog = useCatalog.getState();
      const due = !catalog.syncedAt || Date.now() - Date.parse(catalog.syncedAt) > CATALOG_EVERY_MS;
      if (options.catalog || due || result.sent > 0) await catalog.sync();
      if (useConnectivity.getState().online) useSync.setState({ lastSyncAt: new Date().toISOString() });
    } finally {
      useSync.setState({ running: false });
    }
  })().finally(() => {
    // After the assignment, even when the run returned straight away.
    running = null;
  });
  return running;
}

/**
 * Keeps the counter in step while it runs: syncs on opening, when the
 * connection comes back, every 20 seconds while anything waits to be sent,
 * and refreshes the catalog every ten minutes. Offline, it asks the pharmacy
 * every 15 seconds whether it can be reached again. Offline mode has nothing
 * to sync.
 */
export function useSyncEngine() {
  const ready = useAuth((s) => s.status === 'ready' && !!s.branchId && s.mode === 'hybrid');
  const branchId = useAuth((s) => s.branchId);
  const product = useAuth((s) => s.product);
  const owner = useAuth((s) => holdOwner(s.me?.user.id, s.branchId, s.product));
  const online = useConnectivity((s) => s.online);
  const waiting = useOutbox((s) => queuedFor(s.entries, owner).waiting.length);

  // Open the branch's saved catalog, then sync.
  useEffect(() => {
    if (!ready || !branchId) return;
    void useCatalog
      .getState()
      .load(catalogScope(branchId, product))
      .then(() => syncNow());
    const offBackOnline = onBackOnline(() => void syncNow());
    const catalogTimer = setInterval(() => void syncNow(), CATALOG_EVERY_MS);
    return () => {
      offBackOnline();
      clearInterval(catalogTimer);
    };
  }, [ready, branchId, product]);

  // Something waits: keep trying.
  useEffect(() => {
    if (!ready || waiting === 0 || !online) return;
    const timer = setInterval(() => void syncNow(), FLUSH_EVERY_MS);
    return () => clearInterval(timer);
  }, [ready, waiting, online]);

  // Offline: ask whether the pharmacy answers again (a call that succeeds flips it online).
  useEffect(() => {
    if (!ready || online) return;
    const probe = async () => {
      if (!(await window.desktop.netOnline())) return;
      await api.till().catch(() => {});
    };
    const timer = setInterval(() => void probe(), PROBE_EVERY_MS);
    window.addEventListener('online', probe);
    return () => {
      clearInterval(timer);
      window.removeEventListener('online', probe);
    };
  }, [ready, online]);
}
