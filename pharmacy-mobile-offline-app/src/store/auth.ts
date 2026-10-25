import { create } from 'zustand';

import { api, setSignedOutHandler } from '@/lib/api';
import type { SetUpInput } from '@/lib/api/types';
import { setCurrency } from '@/lib/format';
import type { Branch, Counter, Me, Till } from '@/lib/types';
import { dailyBackup } from '@/local/backup';

import { holdOwner, useCart } from './cart';
import { useNotices } from './notices';

type Status = 'loading' | 'setUp' | 'signedOut' | 'ready';

interface AuthState {
  status: Status;
  me: Me | null;
  /** The one branch: the pharmacy on this phone. */
  branchId: string | null;
  /** The counter: what this member may do, and its rules. */
  counter: Counter | null;
  till: Till | null;
  tillLoaded: boolean;

  hydrate(): Promise<void>;
  /** First run: the pharmacy and its first manager, who is then signed in. */
  setUp(input: SetUpInput): Promise<void>;
  signIn(username: string, password: string): Promise<void>;
  signOut(): Promise<void>;
  refreshMe(): Promise<void>;
  refreshCounter(): Promise<Counter>;
  refreshTill(): Promise<Till | null>;
  setTill(till: Till | null): void;
}

/**
 * Who works the counter on this phone. The pharmacy and its people live in
 * the database here; signing in is a username and password, kept in memory
 * only, so reopening the app asks again.
 */
export const useAuth = create<AuthState>((set, get) => {
  const enter = (me: Me) => {
    setCurrency(me.organization.currency, me.organization.decimals, me.organization.second_currency);
    // Someone else at the counter: the cart in progress was not theirs.
    if (get().me?.user.id !== me.user.id) useCart.getState().clear();
    set({ me, branchId: me.branches[0]?.id ?? null, status: 'ready', counter: null, till: null, tillLoaded: false });
  };

  const signedOut = () => {
    useNotices.getState().clear();
    set({ status: 'signedOut', me: null, branchId: null, counter: null, till: null, tillLoaded: false });
  };

  setSignedOutHandler(() => {
    if (get().status === 'ready') signedOut();
  });

  return {
    status: 'loading',
    me: null,
    branchId: null,
    counter: null,
    till: null,
    tillLoaded: false,

    async hydrate() {
      try {
        const state = await api.state();
        if (!state.setUp) return set({ status: 'setUp' });
        dailyBackup();
        if (state.me) enter(state.me);
        else set({ status: 'signedOut' });
      } catch {
        set({ status: 'signedOut' });
      }
    },

    async setUp(input) {
      enter(await api.setUp(input));
    },

    async signIn(username, password) {
      enter(await api.signIn(username, password));
    },

    async signOut() {
      await api.signOut().catch(() => {});
      useCart.getState().clear();
      signedOut();
    },

    async refreshMe() {
      const me = await api.me();
      setCurrency(me.organization.currency, me.organization.decimals, me.organization.second_currency);
      set({ me });
    },

    async refreshCounter() {
      const counter = await api.counter();
      set({ counter });
      return counter;
    },

    async refreshTill() {
      const till = await api.till();
      get().setTill(till);
      return till;
    },

    setTill(till) {
      set({ till: till?.status === 'open' ? till : null, tillLoaded: true });
    },
  };
});

/** Who parks carts on this phone: the person signed in. */
export function useOwner(): string {
  return useAuth((s) => holdOwner(s.me?.user.id, s.branchId));
}

export function useBranch(): Branch | null {
  return useAuth((s) => s.me?.branches[0] ?? null);
}

const NO_BRANCHES: Branch[] = [];

export function useBranches(): Branch[] {
  return useAuth((s) => s.me?.branches ?? NO_BRANCHES);
}

/** What this member may do at the counter (nothing until it has loaded). */
export function useCan() {
  return useAuth((s) => s.counter?.can ?? null);
}
