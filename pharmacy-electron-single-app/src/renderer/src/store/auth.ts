import { create } from 'zustand';

import { api, setSignedOutHandler } from '@/lib/api';
import type { SetUpInput } from '@/lib/api/types';
import { setCurrency } from '@/lib/format';
import type { Branch, Counter, Me, Till } from '@/lib/types';

import { holdOwner, useCart } from './cart';
import { useUi } from './ui';

type Status = 'loading' | 'setUp' | 'signedOut' | 'ready';

interface AuthState {
  status: Status;
  me: Me | null;
  /** The counter: what this member may do, and its rules. */
  counter: Counter | null;
  till: Till | null;
  tillLoaded: boolean;

  hydrate(): Promise<void>;
  setUp(input: SetUpInput): Promise<void>;
  signIn(username: string, password: string): Promise<void>;
  signOut(): Promise<void>;
  refreshMe(): Promise<void>;
  refreshCounter(): Promise<Counter>;
  refreshTill(): Promise<Till | null>;
  setTill(till: Till | null): void;
}

export const useAuth = create<AuthState>((set, get) => {
  const enter = (me: Me) => {
    setCurrency(me.organization);
    // Someone else at the counter: the cart in progress was not theirs.
    if (get().me?.user.id !== me.user.id) useCart.getState().clear();
    set({ me, status: 'ready', counter: null, till: null, tillLoaded: false });
  };

  const signedOut = () => {
    useUi.getState().reset();
    set({ status: 'signedOut', me: null, counter: null, till: null, tillLoaded: false });
  };

  setSignedOutHandler(() => {
    if (get().status === 'ready') signedOut();
  });

  return {
    status: 'loading',
    me: null,
    counter: null,
    till: null,
    tillLoaded: false,

    async hydrate() {
      const state = await api.state();
      if (!state.setUp) return set({ status: 'setUp' });
      if (state.me) enter(state.me);
      else set({ status: 'signedOut' });
    },

    async setUp(input) {
      enter(await api.setUp(input));
    },

    async signIn(username, password) {
      enter(await api.signIn(username, password));
    },

    async signOut() {
      await api.signOut().catch(() => {});
      signedOut();
    },

    async refreshMe() {
      const me = await api.me();
      setCurrency(me.organization);
      set({ me, counter: null });
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

/** Who parks carts on this PC: the person signed in. */
export function useOwner(): string {
  return useAuth((s) => holdOwner(s.me?.user.id, 'main'));
}

export function useBranch(): Branch | null {
  return useAuth((s) => s.me?.branches[0] ?? null);
}

/** What this member may do at the counter (nothing until it has loaded). */
export function useCan() {
  return useAuth((s) => s.counter?.can ?? null);
}
