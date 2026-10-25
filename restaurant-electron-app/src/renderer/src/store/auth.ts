import { create } from 'zustand';

import { ApiError, me as fetchMe, openSession, setRequestContext } from '@/lib/api';
import { setCurrency } from '@/lib/format';
import { disk } from '@/lib/storage';
import type { Branch, Me } from '@/lib/types';

import { useUi } from './ui';

const SESSION_KEY = 'session';
const WORKSPACE_KEY = 'workspace';

type Status = 'loading' | 'signedOut' | 'needsBranch' | 'ready';

/** Who is signed in where; no secrets (the main process keeps the tokens). */
interface Saved {
  workspace: string;
  me: Me;
  branchId: string | null;
}

interface AuthState {
  status: Status;
  workspace: string | null;
  me: Me | null;
  branchId: string | null;

  hydrate(): Promise<void>;
  /** The platform's ID token, just issued: opens the session at the workspace. */
  signIn(workspace: string, idToken: string): Promise<void>;
  chooseBranch(branchId: string): Promise<void>;
  signOut(): Promise<void>;
  refreshMe(): Promise<void>;
}

function applyContext(workspace: string | null, me: Me | null, branchId: string | null) {
  setRequestContext({ workspace, branchId });
  if (me) setCurrency(me.organization.currency, me.organization.decimals);
}

/** A branch still open to this member, or the only one there is. */
function branchFor(me: Me, wanted: string | null): string | null {
  if (wanted && me.branches.some((b) => b.id === wanted)) return wanted;
  return me.branches.length === 1 ? me.branches[0].id : null;
}

export const useAuth = create<AuthState>((set, get) => {
  const signedOut = async () => {
    await window.desktop.signOut().catch(() => {});
    applyContext(null, null, null);
    await disk.remove(SESSION_KEY);
    set({ status: 'signedOut', me: null, branchId: null });
  };

  setRequestContext({
    onSignedOut: () => void signedOut(),
    onReachable: (reachable) => useUi.getState().setOnline(reachable),
  });

  const enter = async (workspace: string, me: Me, wanted: string | null) => {
    const branchId = branchFor(me, wanted);
    applyContext(workspace, me, branchId);
    await disk.set(SESSION_KEY, { workspace, me, branchId } satisfies Saved);
    set({ workspace, me, branchId, status: branchId ? 'ready' : 'needsBranch' });
  };

  return {
    status: 'loading',
    workspace: null,
    me: null,
    branchId: null,

    async hydrate() {
      try {
        const saved = await disk.get<Saved>(SESSION_KEY);
        if (!saved || !(await window.desktop.signedIn())) {
          set({ status: 'signedOut', workspace: (await disk.get<string>(WORKSPACE_KEY)) ?? saved?.workspace ?? null });
          return;
        }
        await enter(saved.workspace, saved.me, saved.branchId);
        // Branches and roles may have changed since.
        void get().refreshMe().catch(() => {});
      } catch {
        set({ status: 'signedOut' });
      }
    },

    async signIn(workspace, idToken) {
      await openSession(workspace, idToken);
      const me = await fetchMe();
      await disk.set(WORKSPACE_KEY, workspace);
      useUi.setState({ path: '', history: [] });
      await enter(workspace, me, null);
    },

    async chooseBranch(branchId) {
      const { workspace, me } = get();
      if (!workspace || !me) return;
      if (branchId !== get().branchId) useUi.setState({ path: '', history: [] });
      await enter(workspace, me, branchId);
    },

    signOut: signedOut,

    async refreshMe() {
      const { workspace } = get();
      if (!workspace) return;
      try {
        const me = await fetchMe();
        if (me.user.id !== get().me?.user.id) return void (await signedOut());
        await enter(workspace, me, get().branchId);
      } catch (error) {
        if (error instanceof ApiError && (error.status === 401 || error.status === 403)) await signedOut();
        else throw error;
      }
    },
  };
});

export function useBranch(): Branch | null {
  return useAuth((s) => s.me?.branches.find((b) => b.id === s.branchId) ?? null);
}
