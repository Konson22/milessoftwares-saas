import AsyncStorage from '@react-native-async-storage/async-storage';
import { create } from 'zustand';

import { ApiError, currentWorkspace, request, setRequestContext } from '@/lib/api/client';
import { platform, type BusinessChoice } from '@/lib/api/platform';
import { accessToken, freshIdToken, loadTokens, saveTokens, signInOnPlatform, signInWithCode, signInWithGoogle, signInWithNewPassword, signInWithPassword } from '@/lib/auth/tokens';
import { unregisterPush } from '@/lib/push';

/*
 * Who is signed in, and where. Sign-in is Central's (OIDC with PKCE, or
 * Google through Central); the tokens live in the secure store. The
 * session record here holds no secrets: the workspace and the person.
 *
 * Signing out never deletes unsent entries: it is scoped to
 * workspace|user and comes back when the same person signs in again.
 */

const SESSION_KEY = 'tatook-logbook.session';
const WORKSPACE_KEY = 'tatook-logbook.workspace';

type Status = 'loading' | 'signedOut' | 'noBusiness' | 'chooseBusiness' | 'ready';

export type SignInWith =
  | { with: 'password'; email: string; password: string }
  | { with: 'code'; code: string; recovery?: boolean }
  | { with: 'newPassword'; password: string }
  | { with: 'google' }
  | { with: 'browser' };

export interface Me {
  user: { id: string; name: string; email: string };
  organization: { id: string; name: string; slug: string };
  can: { write: boolean; review: boolean };
}

interface Saved {
  workspace: string;
  me: Me;
}

interface AuthState {
  status: Status;
  workspace: string | null;
  me: Me | null;
  choices: BusinessChoice[];
  reachable: boolean;
  /** Signed out by the server (expired session): unsent work waits for the same person. */
  needsSignIn: boolean;

  hydrate(): Promise<void>;
  signIn(how: SignInWith): Promise<'signedIn' | 'choose' | 'noBusiness' | 'twoFactor' | 'setPassword'>;
  chooseBusiness(workspace: string): Promise<void>;
  refreshMe(): Promise<void>;
  signOut(): Promise<void>;
}

async function saveSession(saved: Saved | null) {
  if (saved) await AsyncStorage.setItem(SESSION_KEY, JSON.stringify(saved));
  else await AsyncStorage.removeItem(SESSION_KEY);
}

let pendingIdToken: string | null = null;

/** The scope every record on the phone carries. */
export const scopeOf = (workspace: string, userId: string) => `${workspace}|${userId}`;

export const useAuth = create<AuthState>((set, get) => {
  const signedOut = async () => {
    // While the sign-in still works: this phone stops getting the person's notices.
    await unregisterPush();
    await saveTokens(null);
    await saveSession(null);
    setRequestContext({ workspace: null });
    pendingIdToken = null;
    set({ status: 'signedOut', me: null, choices: [] });
  };

  setRequestContext({
    token: accessToken,
    // The server no longer takes this sign-in: ask for it again, keep the work.
    onSignedOut: () => set({ needsSignIn: true }),
    onReachable: (reachable) => {
      if (get().reachable !== reachable) set({ reachable });
    },
    onMoved: async (from, to) => {
      setRequestContext({ workspace: to });
      if ((await AsyncStorage.getItem(WORKSPACE_KEY)) === from) await AsyncStorage.setItem(WORKSPACE_KEY, to);
      const { workspace, me } = get();
      if (workspace === from && me) {
        await saveSession({ workspace: to, me });
        set({ workspace: to });
      }
    },
  });

  const enter = async (workspace: string, me: Me) => {
    setRequestContext({ workspace });
    await saveSession({ workspace, me });
    set({ workspace, me, status: 'ready', needsSignIn: false });
  };

  const openWorkspace = async (workspace: string, idToken: string) => {
    setRequestContext({ workspace });
    try {
      await request('POST', 'session', { id_token: idToken });
    } catch (error) {
      await saveTokens(null);
      if (error instanceof ApiError && error.status === 404) {
        throw new ApiError(`Logbook is not set up at “${workspace}”. Check the workspace address.`, 404);
      }
      if (error instanceof ApiError && error.status === 403) {
        throw new ApiError('Your account has no access to Logbook here. Ask your manager to add you.', 403);
      }
      throw error;
    }
    const me = await request<Me>('GET', 'v1/me');
    const previous = get().me;
    // Another person on this phone: theirs is scoped apart, nothing is mixed.
    if (previous && previous.user.id !== me.user.id) set({ me: null });
    const where = currentWorkspace() ?? workspace;
    await AsyncStorage.setItem(WORKSPACE_KEY, where);
    await enter(where, me);
  };

  return {
    status: 'loading',
    workspace: null,
    me: null,
    choices: [],
    reachable: true,
    needsSignIn: false,

    async hydrate() {
      try {
        const raw = await AsyncStorage.getItem(SESSION_KEY);
        const saved = raw ? (JSON.parse(raw) as Saved) : null;
        const tokens = await loadTokens();
        if (!saved || !tokens) {
          set({ status: 'signedOut', workspace: (await AsyncStorage.getItem(WORKSPACE_KEY)) ?? saved?.workspace ?? null });
          return;
        }
        // Offline is fine: entries are written without the server.
        await enter(saved.workspace, saved.me);
        void get().refreshMe().catch(() => {});
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
            : how.with === 'newPassword'
              ? await signInWithNewPassword(how.password)
              : how.with === 'google'
                ? await signInWithGoogle()
                : await signInOnPlatform();
      if (tokens === 'twoFactor' || tokens === 'setPassword') return tokens;

      let businesses: BusinessChoice[];
      try {
        businesses = await platform.businesses();
      } catch (error) {
        await saveTokens(null);
        throw error;
      }
      if (businesses.length === 0) {
        set({ status: 'noBusiness' });
        return 'noBusiness';
      }
      if (businesses.length === 1) {
        await openWorkspace(businesses[0].slug, tokens.idToken);
        return 'signedIn';
      }
      const last = await AsyncStorage.getItem(WORKSPACE_KEY);
      pendingIdToken = tokens.idToken;
      set({ status: 'chooseBusiness', choices: [...businesses].sort((a, b) => Number(b.slug === last) - Number(a.slug === last)) });
      return 'choose';
    },

    async chooseBusiness(workspace) {
      await openWorkspace(workspace, pendingIdToken ?? (await freshIdToken()));
      pendingIdToken = null;
      set({ choices: [] });
    },

    async refreshMe() {
      const { workspace } = get();
      if (!workspace) return;
      try {
        const me = await request<Me>('GET', 'v1/me');
        if (me.user.id !== get().me?.user.id) return void (await signedOut());
        await enter(workspace, me);
      } catch (error) {
        if (error instanceof ApiError && error.status === 403) await signedOut();
        else if (!(error instanceof ApiError && error.status === 401)) throw error;
      }
    },

    signOut: signedOut,
  };
});

export function useScope(): string | null {
  return useAuth((s) => (s.workspace && s.me ? scopeOf(s.workspace, s.me.user.id) : null));
}
