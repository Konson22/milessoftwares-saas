import AsyncStorage from '@react-native-async-storage/async-storage';
import { create } from 'zustand';

import { ApiError, request, setRequestContext } from '@/lib/api/client';
import { platform, type RestaurantChoice } from '@/lib/api/platform';
import { accessToken, loadTokens, saveTokens, signInOnPlatform, signInWithCode, signInWithPassword } from '@/lib/auth/tokens';
import { setCurrency } from '@/lib/format';
import { loadMode, saveMode } from '@/lib/mode';
import type { Branch, Me, Mode, User } from '@/lib/types';
import { dailyBackup } from '@/local/backup';
import { openDatabase } from '@/local/db/database';
import * as session from '@/local/db/session';
import { LocalError } from '@/local/db/util';
import { setSignedOutHandler } from '@/local/pages';

const SESSION_KEY = 'miles-restaurant.session';
const WORKSPACE_KEY = 'miles-restaurant.workspace';

/**
 * chooseMode: first opening (or after switching): online or on this phone.
 * Offline: setUp on the very first run, then signedOut ⇄ ready.
 * Online: signedOut → chooseRestaurant (in more than one) → needsBranch
 * (more than one branch) → ready.
 */
type Status = 'loading' | 'chooseMode' | 'setUp' | 'signedOut' | 'chooseRestaurant' | 'needsBranch' | 'ready';

/** How the person signs in online: email and password (then a two-step code if asked), or the browser. */
export type SignInWith =
  | { with: 'password'; email: string; password: string }
  | { with: 'code'; code: string; recovery?: boolean }
  | { with: 'browser' };

/** Online: who is signed in where; no secrets (the tokens live in the secure store). */
interface Saved {
  workspace: string;
  me: Me;
  branchId: string | null;
}

interface AuthState {
  mode: Mode | null;
  status: Status;
  /** Online: the workspace's address. */
  workspace: string | null;
  me: Me | null;
  branchId: string | null;
  /** Online, signed in with more than one restaurant: the ones to choose from. */
  choices: RestaurantChoice[];
  /** Online: whether the restaurant answered the last call. */
  reachable: boolean;

  hydrate(): Promise<void>;
  chooseMode(mode: Mode): Promise<void>;
  /** Back to choosing the mode; the other mode's data stays. */
  switchMode(): Promise<void>;

  /** Offline, first run: the restaurant and its first manager, who is then signed in. */
  setUp(input: session.SetUpInput): Promise<void>;
  /** Offline: a username and password on this phone. */
  signInLocal(username: string, password: string): Promise<void>;

  /** Online: 'twoFactor' wants a code next; 'choose' lists the restaurants. */
  signIn(how: SignInWith): Promise<'signedIn' | 'choose' | 'twoFactor'>;
  chooseRestaurant(workspace: string): Promise<void>;
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

/** The phone's answer, its refusal as an ApiError with the fields a form shows it against. */
function onPhone<T>(task: () => T): T {
  try {
    return task();
  } catch (error) {
    if (error instanceof LocalError) throw new ApiError(error.message, error.status, error.errors, false, error.code);
    throw error;
  }
}

/** The sign-in's ID token while the person chooses a restaurant; never stored. */
let pendingIdToken: string | null = null;

export const useAuth = create<AuthState>((set, get) => {
  const signedOut = async () => {
    if (get().mode === 'online') {
      await saveTokens(null);
      await saveSession(null);
      setRequestContext({ workspace: null, branchId: null });
    } else {
      session.signOut();
    }
    pendingIdToken = null;
    set({ status: 'signedOut', me: null, branchId: null, choices: [] });
  };

  setRequestContext({
    token: accessToken,
    onSignedOut: () => void signedOut(),
    onReachable: (reachable) => {
      if (get().reachable !== reachable) set({ reachable });
    },
  });
  setSignedOutHandler(() => {
    if (get().status === 'ready') void signedOut();
  });

  /** Online: signed in at a workspace, in its branch when there is one to work in. */
  const enter = async (workspace: string, me: Me, wanted: string | null) => {
    const branchId = branchFor(me, wanted);
    setRequestContext({ workspace, branchId });
    setCurrency(me.organization.currency, me.organization.decimals);
    await saveSession({ workspace, me, branchId });
    set({ workspace, me, branchId, status: branchId ? 'ready' : 'needsBranch' });
  };

  /** Offline: someone signed in on this phone. */
  const enterLocal = (me: Me) => {
    setCurrency(me.organization.currency, me.organization.decimals);
    set({ me, workspace: null, branchId: me.branches[0]?.id ?? null, status: 'ready' });
  };

  const start = async (mode: Mode | null) => {
    set({ mode, me: null, workspace: null, branchId: null, choices: [], reachable: true });
    if (!mode) return set({ status: 'chooseMode' });

    if (mode === 'offline') {
      openDatabase();
      if (!session.hasUsers()) return set({ status: 'setUp' });
      dailyBackup();
      const user = session.signedInUser();
      if (user) enterLocal(session.me(user));
      else set({ status: 'signedOut' });
      return;
    }

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

  /** Online: tells the workspace's restaurant who this is, then asks who and where. */
  const openWorkspace = async (workspace: string, idToken: string) => {
    setRequestContext({ workspace, branchId: null });
    try {
      await request('POST', 'session', { id_token: idToken });
    } catch (error) {
      await saveTokens(null);
      if (error instanceof ApiError && error.status === 404) {
        throw new ApiError(`No restaurant at “${workspace}”. Check the workspace address.`, 404);
      }
      if (error instanceof ApiError && error.status === 403) {
        throw new ApiError('Your account has no access to this restaurant. Ask the owner to add you.', 403);
      }
      throw error;
    }
    const me = await request<Me>('GET', 'v1/me');
    await AsyncStorage.setItem(WORKSPACE_KEY, workspace);
    await enter(workspace, me, null);
  };

  return {
    mode: null,
    status: 'loading',
    workspace: null,
    me: null,
    branchId: null,
    choices: [],
    reachable: true,

    async hydrate() {
      try {
        await start(await loadMode());
      } catch (error) {
        console.warn('Opening failed', error);
        set({ status: get().mode ? 'signedOut' : 'chooseMode' });
      }
    },

    async chooseMode(mode) {
      await saveMode(mode);
      await start(mode);
    },

    async switchMode() {
      await signedOut();
      await saveMode(null);
      await start(null);
    },

    // A tick first: checking a password takes a moment, and the screen shows it is busy meanwhile.
    async setUp(input) {
      await new Promise((resolve) => setTimeout(resolve, 0));
      enterLocal(onPhone(() => session.setUp(input)));
    },

    async signInLocal(username, password) {
      await new Promise((resolve) => setTimeout(resolve, 0));
      enterLocal(onPhone(() => session.signIn(username, password)));
    },

    async signIn(how) {
      const tokens =
        how.with === 'password'
          ? await signInWithPassword(how.email, how.password)
          : how.with === 'code'
            ? await signInWithCode(how.code, how.recovery)
            : await signInOnPlatform();
      if (tokens === 'twoFactor') return 'twoFactor';

      let restaurants: RestaurantChoice[];
      try {
        restaurants = await platform.restaurants();
      } catch (error) {
        await saveTokens(null);
        throw error;
      }
      if (restaurants.length === 0) {
        await saveTokens(null);
        throw new ApiError('Your account is not in a restaurant yet. Create one on the web, or ask the owner to invite you.', 403);
      }
      if (restaurants.length === 1) {
        await openWorkspace(restaurants[0].slug, tokens.idToken);
        return 'signedIn';
      }

      // The last one opened here first.
      const last = await AsyncStorage.getItem(WORKSPACE_KEY);
      pendingIdToken = tokens.idToken;
      set({ status: 'chooseRestaurant', choices: [...restaurants].sort((a, b) => Number(b.slug === last) - Number(a.slug === last)) });
      return 'choose';
    },

    async chooseRestaurant(workspace) {
      if (!pendingIdToken) throw new ApiError('Sign in again.', 401);
      await openWorkspace(workspace, pendingIdToken);
      pendingIdToken = null;
      set({ choices: [] });
    },

    async chooseBranch(branchId) {
      const { workspace, me } = get();
      if (!workspace || !me) return;
      await enter(workspace, me, branchId);
    },

    async refreshMe() {
      const { mode, workspace, branchId } = get();
      if (mode === 'offline') {
        const user = session.signedInUser();
        if (user) enterLocal(session.me(user));
        return;
      }
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
