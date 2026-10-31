import { useCallback, useEffect, useRef, useState } from 'react';
import { create } from 'zustand';

import { ApiError, errorMessage, isOffline } from '@/lib/api';
import { place } from '@/lib/config';
import { isLocal } from '@/lib/mode';
import { act, getPage, type AppPage, type SharedProps } from '@/lib/pages';
import { cached, peek } from '@/offline/cache';
import { useConnectivity } from '@/offline/connectivity';
import { useOwner } from '@/store/auth';
import { toast, useUi } from '@/store/ui';

/** What the member may do, from the last page the pharmacy answered (for the menu). */
export const useAccess = create<{ shared: SharedProps | null }>(() => ({ shared: null }));

function remember(page: AppPage) {
  const { access, branch, branchPermissions, currency } = page.props;
  useAccess.setState({ shared: { access, branch, branchPermissions, currency } });
}

/** The menu's permissions on opening: the saved dashboard, then a fresh one. */
export async function loadAccess(owner: string) {
  const saved = await peek<AppPage>(owner, 'page.');
  if (saved) remember(saved.data);
  try {
    remember((await cached(owner, 'page.', () => getPage<Record<string, unknown>>(''))).data);
  } catch {
    // Offline, or the demo: the saved one, or the counter only.
  }
}

interface PageState<P> {
  page: AppPage<P> | null;
  /** Shown from this PC, as the pharmacy answered at savedAt. */
  staleSince: string | null;
  error: string | null;
  loading: boolean;
}

/**
 * A back-office page: from the pharmacy, saved on this PC for reading
 * offline. Loaded again when the connection comes back.
 */
export function usePage<P = Record<string, any>>(path: string) {
  const owner = useOwner();
  const online = useConnectivity((s) => s.online);
  const [state, setState] = useState<PageState<P>>({ page: null, staleSince: null, error: null, loading: true });
  const current = useRef(path);
  current.current = path;

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true }));
    try {
      const { data, fresh, savedAt } = await cached(owner, `page.${path}`, () => getPage<P>(path));
      if (current.current !== path) return;
      remember(data as AppPage);
      setState({ page: data, staleSince: fresh ? null : savedAt, error: null, loading: false });
    } catch (e) {
      if (current.current !== path) return;
      setState({
        page: null,
        staleSince: null,
        error: isOffline(e)
          ? 'This page has not been opened on this PC yet: connect once and it is kept for reading offline.'
          : e instanceof ApiError && e.status === 403
            ? 'You may not open this page.'
            : errorMessage(e),
        loading: false,
      });
    }
  }, [owner, path]);

  useEffect(() => {
    void load();
  }, [load, online]);

  return { ...state, reload: load };
}

export type FieldErrors = Record<string, string>;

/**
 * Actions from a page, as its forms on the web: the message shown, then the
 * page the pharmacy names, or this one again. Validation comes back per
 * field. They need the connection: only sales and cash movements are kept
 * for later (the outbox).
 */
export function useAction(from: string, reload: () => Promise<void> | void) {
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});

  const run = useCallback(
    async (method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, data: unknown = {}): Promise<boolean> => {
      if (!isLocal() && !useConnectivity.getState().online) {
        toast(`This needs the connection to the ${place()}.`, 'amber');
        return false;
      }
      setBusy(true);
      setErrors({});
      try {
        const result = await act(method, path, data, from);
        if (result.message) toast(result.message);
        if (result.redirect !== null && result.redirect !== from) useUi.getState().open(result.redirect);
        else await reload();
        return true;
      } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
          setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
          toast(e.message, 'red');
        } else {
          toast(errorMessage(e), isOffline(e) ? 'amber' : 'red');
        }
        return false;
      } finally {
        setBusy(false);
      }
    },
    [from, reload],
  );

  return { run, busy, errors, setErrors };
}
