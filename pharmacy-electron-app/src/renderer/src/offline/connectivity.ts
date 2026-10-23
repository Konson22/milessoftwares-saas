import { create } from 'zustand';

import { API_MODE, setSimulatedOffline } from '@/lib/api';

/**
 * Whether the pharmacy can be reached right now. Every call says so (it got
 * an answer, or it never did), and the OS says when the network is gone
 * altogether. Going from offline to online is what starts a sync.
 */
interface ConnectivityState {
  online: boolean;
  /** When the pharmacy last answered. */
  lastSeenAt: string | null;
  /** Demo only: the mock pharmacy answers as if the network were down. */
  simulated: boolean;
  setReachable(reachable: boolean): void;
  simulateOffline(offline: boolean): void;
}

type Listener = () => void;
const backOnline = new Set<Listener>();

/** Called each time the pharmacy can be reached again after it could not. */
export function onBackOnline(listener: Listener): () => void {
  backOnline.add(listener);
  return () => backOnline.delete(listener);
}

export const useConnectivity = create<ConnectivityState>((set, get) => ({
  online: navigator.onLine,
  lastSeenAt: null,
  simulated: false,

  setReachable(reachable) {
    const was = get().online;
    set({ online: reachable, ...(reachable ? { lastSeenAt: new Date().toISOString() } : {}) });
    if (reachable && !was) backOnline.forEach((listener) => listener());
  },

  simulateOffline(offline) {
    if (API_MODE !== 'mock') return;
    setSimulatedOffline(offline);
    set({ simulated: offline });
    // Back on: the next call finds out; a probe runs straight away.
    if (offline) get().setReachable(false);
  },
}));

window.addEventListener('offline', () => useConnectivity.getState().setReachable(false));
