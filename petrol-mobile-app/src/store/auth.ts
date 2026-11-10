import AsyncStorage from '@react-native-async-storage/async-storage';
import { create } from 'zustand';

import { ApiError, currentWorkspace, request, setRequestContext } from '@/lib/api/client';
import { platform, type BusinessChoice } from '@/lib/api/platform';
import { accessToken, freshIdToken, loadTokens, saveTokens, signInOnPlatform, signInWithCode, signInWithGoogle, signInWithPassword } from '@/lib/auth/tokens';
import { setCurrency } from '@/lib/format';
import type { Branch, Me, User } from '@/lib/types';
import { forgetPages } from '@/offline/cache';
import { useOutbox } from '@/offline/outbox';

const SESSION_KEY = 'miles-petrol.session';
const WORKSPACE_KEY = 'miles-petrol.workspace';
/** Where the old offline mode kept its choice; cleared on opening. */
const OLD_MODE_KEY = 'miles-petrol.mode';

/**
 * signedOut → noBusiness (signed in, none yet: create one) or
 * chooseBusiness (in more than one) → needsBranch (more than one branch) →
 * ready.
 */
type Status = 'loading' | 'signedOut' | 'noBusiness' | 'chooseBusiness' | 'needsBranch' | 'ready';

/** How the person signs in: email and password (then a two-step code if asked), Google on the device, or the browser. */
export type SignInWith =
  | { with: 'password'; email: string; password: string }
  | { with: 'code'; code: string; recovery?: boolean }
  | { with: 'google' }
  | { with: 'browser' };

/** Who is signed in where; no secrets (the tokens live in the secure store). */
interface Saved {
  workspace: string;
  me: Me;
  branchId: string | null;
}

interface AuthState {
  status: Status;
  /** The workspace's address. */
  workspace: string | null;
  me: Me | null;
  branchId: string | null;
  /** Signed in with more than one business: the ones to choose from. */
  choices: BusinessChoice[];
  /** Whether the station answered the last call. */
  reachable: boolean;

  hydrate(): Promise<void>;

  /**
   * 'twoFactor' wants a code next; 'choose' lists the stations;
   * 'noBusiness' stays signed in, to create one without signing in again.
   */
  signIn(how: SignInWith): Promise<'signedIn' | 'choose' | 'noBusiness' | 'twoFactor'>;
  /** Open a business of whoever is signed in: one they chose, or one just created. */
  chooseBusiness(workspace: string): Promise<void>;
  chooseBranch(branchId: string): Promise<void>;
  refreshMe(): Promise<void>;

  signOut(): Promise<void>;
}

async function saveSession(saved: Saved | null) {
  if (saved) await AsyncStorage.setItem(SESSION_KEY, JSON.stringify(saved));
  else await AsyncStorage.removeItem(SESSION_KEY);
}

/** A branch still open to this member, or the only one there is. */
function branchFor(me: Me, wanted: string | null): string | null {
  if (wanted && me.branches.some((b) => b.id === wanted)) return wanted;
  return me.branches.length === 1 ? me.branches[0].id : null;
}

/** The sign-in's ID token while the person chooses a business; never stored. */
let pendingIdToken: string | null = null;

export const useAuth = create<AuthState>((set, get) => {
  const signedOut = async () => {
    await saveTokens(null);
    await saveSession(null);
    setRequestContext({ workspace: null, branchId: null });
    pendingIdToken = null;
    // Their pages leave the phone; what waits to be sent stays, for when they sign in again.
    await forgetPages();
    set({ status: 'signedOut', me: null, branchId: null, choices: [] });
  };

  setRequestContext({
    token: accessToken,
    onSignedOut: () => void signedOut(),
    onReachable: (reachable) => {
      if (get().reachable !== reachable) set({ reachable });
    },
    onMoved: async (from, to) => {
      setRequestContext({ workspace: to });
      // What waits to be sent goes with it; pages are fetched again from the new address.
      useOutbox.getState().moveWorkspace(from, to);
      if ((await AsyncStorage.getItem(WORKSPACE_KEY)) === from) await AsyncStorage.setItem(WORKSPACE_KEY, to);
      const { workspace, me, branchId } = get();
      if (workspace === from && me) {
        await saveSession({ workspace: to, me, branchId });
        await forgetPages();
        set({ workspace: to });
      }
    },
  });

  /** Signed in at a workspace, in its branch when there is one to work in. */
  const enter = async (workspace: string, me: Me, wanted: string | null) => {
    const branchId = branchFor(me, wanted);
    setRequestContext({ workspace, branchId });
    setCurrency(me.organization.currency, me.organization.decimals);
    await saveSession({ workspace, me, branchId });
    set({ workspace, me, branchId, status: branchId ? 'ready' : 'needsBranch' });
  };

  const start = async () => {
    await AsyncStorage.removeItem(OLD_MODE_KEY);
    const raw = await AsyncStorage.getItem(SESSION_KEY);
    const saved = raw ? (JSON.parse(raw) as Saved) : null;
    const tokens = await loadTokens();
    if (!saved || !tokens) {
      set({ status: 'signedOut', workspace: (await AsyncStorage.getItem(WORKSPACE_KEY)) ?? saved?.workspace ?? null });
      return;
    }
    await enter(saved.workspace, saved.me, saved.branchId);
    // Branches and roles may have changed since; without a connection, what was saved stays.
    void get().refreshMe().catch(() => {});
  };

  /** Tells the workspace's petrol station who this is, then asks who and where. */
  const openWorkspace = async (workspace: string, idToken: string) => {
    setRequestContext({ workspace, branchId: null });
    try {
      await request('POST', 'session', { id_token: idToken });
    } catch (error) {
      await saveTokens(null);
      if (error instanceof ApiError && error.status === 404) {
        throw new ApiError(`No petrol station at “${workspace}”. Check the workspace address.`, 404);
      }
      if (error instanceof ApiError && error.status === 403) {
        throw new ApiError('Your account has no access to this petrol station. Ask the owner to add you.', 403);
      }
      throw error;
    }
    const me = await request<Me>('GET', 'v1/me');
    // Typed at an old address, the workspace is now at its current one.
    const where = currentWorkspace() ?? workspace;
    await AsyncStorage.setItem(WORKSPACE_KEY, where);
    await enter(where, me, null);
  };

  return {
    status: 'loading',
    workspace: null,
    me: null,
    branchId: null,
    choices: [],
    reachable: true,

    async hydrate() {
      try {
        await start();
      } catch (error) {
        console.warn('Opening failed', error);
        set({ status: 'signedOut' });
      }
    },

    async signIn(how) {
      const tokens =
        how.with === 'password'
          ? await signInWithPassword(how.email, how.password)
          : how.with === 'code'
            ? await signInWithCode(how.code, how.recovery)
            : how.with === 'google'
              ? await signInWithGoogle()
              : await signInOnPlatform();
      if (tokens === 'twoFactor') return 'twoFactor';

      let businesses: BusinessChoice[];
      try {
        businesses = await platform.businesses();
      } catch (error) {
        await saveTokens(null);
        throw error;
      }
      // None yet: still signed in, to create one here.
      if (businesses.length === 0) {
        set({ status: 'noBusiness' });
        return 'noBusiness';
      }
      if (businesses.length === 1) {
        await openWorkspace(businesses[0].slug, tokens.idToken);
        return 'signedIn';
      }

      // The last one opened here first.
      const last = await AsyncStorage.getItem(WORKSPACE_KEY);
      pendingIdToken = tokens.idToken;
      set({ status: 'chooseBusiness', choices: [...businesses].sort((a, b) => Number(b.slug === last) - Number(a.slug === last)) });
      return 'choose';
    },

    async chooseBusiness(workspace) {
      // Just registered, or none until now: the refresh answers with a new ID token.
      await openWorkspace(workspace, pendingIdToken ?? (await freshIdToken()));
      pendingIdToken = null;
      set({ choices: [] });
    },

    async chooseBranch(branchId) {
      const { workspace, me } = get();
      if (!workspace || !me) return;
      await enter(workspace, me, branchId);
    },

    async refreshMe() {
      const { workspace, branchId } = get();
      if (!workspace) return;
      try {
        const me = await request<Me>('GET', 'v1/me');
        if (me.user.id !== get().me?.user.id) return void (await signedOut());
        await enter(workspace, me, branchId);
      } catch (error) {
        if (error instanceof ApiError && (error.status === 401 || error.status === 403)) await signedOut();
        else throw error;
      }
    },

    signOut: signedOut,
  };
});

export function useBranch(): Branch | null {
  return useAuth((s) => s.me?.branches.find((b) => b.id === s.branchId) ?? null);
}

const NO_BRANCHES: Branch[] = [];

export function useBranches(): Branch[] {
  return useAuth((s) => s.me?.branches ?? NO_BRANCHES);
}

export function useUser(): User | null {
  return useAuth((s) => s.me?.user ?? null);
}
