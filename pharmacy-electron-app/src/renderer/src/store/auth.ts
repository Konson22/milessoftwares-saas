import { create } from 'zustand';

import type { Product } from '../../../shared/ipc';
import { api, API_MODE, ApiError, setRequestContext } from '@/lib/api';
import { setMockContext } from '@/lib/api/mock';
import { setProduct } from '@/lib/config';
import { setCurrency } from '@/lib/format';
import { disk } from '@/lib/storage';
import type { Branch, Counter, Me, Till } from '@/lib/types';
import { cached, remember } from '@/offline/cache';
import { useConnectivity } from '@/offline/connectivity';

import { holdOwner, useCart } from './cart';

const SESSION_KEY = 'session';
const WORKSPACE_KEY = 'workspace';
const PRODUCT_KEY = 'product';

type Status = 'loading' | 'signedOut' | 'needsBranch' | 'ready';

/** Who is signed in where; no secrets (the main process keeps the tokens). */
interface Saved {
  workspace: string;
  /** Saved before there was a shop: the pharmacy. */
  product?: Product;
  me: Me;
  branchId: string | null;
}

interface AuthState {
  status: Status;
  workspace: string | null;
  /** Selling for the pharmacy or a shop; signed out: the last one chosen. */
  product: Product;
  me: Me | null;
  branchId: string | null;
  /** This branch's counter: what this member may do, and its rules. */
  counter: Counter | null;
  till: Till | null;
  tillLoaded: boolean;
  /** When the counter and shift shown were last heard from the pharmacy (or shop), if not just now. */
  staleSince: string | null;

  hydrate(): Promise<void>;
  /** The platform's ID token (live) or the demo account (mock): see PosApi.signIn. */
  signIn(workspace: string, credential: string, product: Product): Promise<void>;
  chooseBranch(branchId: string): Promise<void>;
  signOut(): Promise<void>;
  refreshMe(): Promise<void>;
  refreshCounter(): Promise<Counter>;
  refreshTill(): Promise<Till | null>;
  setTill(till: Till | null): void;
}

function applyContext(workspace: string | null, me: Me | null, branchId: string | null, product: Product = 'pharmacy') {
  setProduct(product);
  setRequestContext({ workspace, branchId });
  if (API_MODE === 'mock') setMockContext(me?.user.id ?? null, branchId);
  if (me) setCurrency(me.organization.currency, me.organization.decimals);
}

/** A branch still open to this member, or the only one there is. */
function branchFor(me: Me, wanted: string | null): string | null {
  if (wanted && me.branches.some((b) => b.id === wanted)) return wanted;
  return me.branches.length === 1 ? me.branches[0].id : null;
}

export const useAuth = create<AuthState>((set, get) => {
  const signedOut = async () => {
    await api.signOut().catch(() => {});
    applyContext(null, null, null);
    await disk.remove(SESSION_KEY);
    useCart.getState().clear();
    set({ status: 'signedOut', me: null, branchId: null, counter: null, till: null, tillLoaded: false, staleSince: null });
  };

  setRequestContext({
    onSignedOut: () => void signedOut(),
    onReachable: (reachable) => useConnectivity.getState().setReachable(reachable),
  });

  const enter = async (workspace: string, product: Product, me: Me, wanted: string | null) => {
    const branchId = branchFor(me, wanted);
    applyContext(workspace, me, branchId, product);
    await disk.set(SESSION_KEY, { workspace, product, me, branchId } satisfies Saved);
    set({ workspace, product, me, branchId, status: branchId ? 'ready' : 'needsBranch' });
  };

  const owner = () => holdOwner(get().me?.user.id, get().branchId, get().product);

  return {
    status: 'loading',
    workspace: null,
    product: 'pharmacy',
    me: null,
    branchId: null,
    counter: null,
    till: null,
    tillLoaded: false,
    staleSince: null,

    async hydrate() {
      try {
        const saved = await disk.get<Saved>(SESSION_KEY);
        // Demo sign-ins do not outlive the app: the demo pharmacy resets with it.
        const tokens = API_MODE === 'live' && (await window.desktop.signedIn());
        if (!saved || !tokens) {
          set({
            status: 'signedOut',
            workspace: (await disk.get<string>(WORKSPACE_KEY)) ?? saved?.workspace ?? null,
            product: (await disk.get<Product>(PRODUCT_KEY)) ?? saved?.product ?? 'pharmacy',
          });
          return;
        }
        // Signed in before: open straight away, with or without a connection.
        await enter(saved.workspace, saved.product ?? 'pharmacy', saved.me, saved.branchId);
        // Branches and roles may have changed since; offline keeps what was saved.
        void get().refreshMe().catch(() => {});
      } catch {
        set({ status: 'signedOut' });
      }
    },

    async signIn(workspace, credential, product) {
      setProduct(product);
      setRequestContext({ workspace, branchId: null });
      await api.signIn(workspace, credential);
      if (API_MODE === 'mock') setMockContext(credential, null);
      const me = await api.me();
      await disk.set(WORKSPACE_KEY, workspace);
      await disk.set(PRODUCT_KEY, product);
      useCart.getState().clear();
      set({ counter: null, till: null, tillLoaded: false, staleSince: null });
      await enter(workspace, product, me, null);
    },

    async chooseBranch(branchId) {
      const { workspace, product, me } = get();
      if (!workspace || !me) return;
      if (branchId !== get().branchId) useCart.getState().clear();
      set({ counter: null, till: null, tillLoaded: false, staleSince: null });
      await enter(workspace, product, me, branchId);
    },

    signOut: signedOut,

    async refreshMe() {
      const { workspace } = get();
      if (!workspace) return;
      try {
        const me = await api.me();
        if (me.user.id !== get().me?.user.id) return void (await signedOut());
        // The branch as it is now: one may have been chosen while this was asked.
        await enter(workspace, get().product, me, get().branchId);
      } catch (error) {
        if (error instanceof ApiError && (error.status === 401 || error.status === 403)) await signedOut();
        else throw error;
      }
    },

    async refreshCounter() {
      try {
        const { data: counter, fresh, savedAt } = await cached(owner(), 'counter', () => api.counter());
        set({ counter, staleSince: fresh ? null : savedAt });
        return counter;
      } catch (error) {
        // The branch is gone or no longer this member's: choose again.
        if (error instanceof ApiError && (error.code === 'branch_required' || error.status === 403)) {
          set({ status: 'needsBranch', branchId: null, counter: null });
          await get().refreshMe().catch(() => {});
        }
        throw error;
      }
    },

    async refreshTill() {
      const { data: till, fresh, savedAt } = await cached(owner(), 'till', () => api.till());
      get().setTill(till);
      set({ staleSince: fresh ? null : savedAt });
      return till;
    },

    setTill(till) {
      const open = till?.status === 'open' ? till : null;
      remember(owner(), 'till', open);
      set({ till: open, tillLoaded: true });
    },
  };
});

export function useOwner(): string {
  return useAuth((s) => holdOwner(s.me?.user.id, s.branchId, s.product));
}

export function useBranch(): Branch | null {
  return useAuth((s) => s.me?.branches.find((b) => b.id === s.branchId) ?? null);
}

/** What this member may do at this counter (nothing until it has loaded). */
export function useCan() {
  return useAuth((s) => s.counter?.can ?? null);
}
