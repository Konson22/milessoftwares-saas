import { useCallback, useEffect, useRef, useState } from 'react';
import { create } from 'zustand';

import { ApiError, errorMessage } from '@/lib/api';
import { act, getPage, type AppPage, type SharedProps } from '@/lib/pages';
import { useAuth } from '@/store/auth';
import { toast, useUi } from '@/store/ui';

/** What the member may do, from the last page the restaurant answered (for the menu). */
export const useAccess = create<{ shared: SharedProps | null }>(() => ({ shared: null }));

function remember(page: AppPage) {
  const { access, branch, branchPermissions, currency } = page.props;
  useAccess.setState({ shared: { access, branch, branchPermissions, currency } });
}

/** The menu's permissions on opening, from the home page. */
export async function loadAccess() {
  try {
    remember(await getPage<Record<string, unknown>>(''));
  } catch {
    // Unreachable: the menu fills in with the next page that loads.
  }
}

interface PageState<P> {
  page: AppPage<P> | null;
  error: string | null;
  loading: boolean;
}

/**
 * A page of the restaurant, as the web shows it. With `every`, loaded again
 * on that many seconds (the floor, the kitchen screen) while it is shown.
 */
export function usePage<P = Record<string, any>>(path: string, every?: number) {
  const branchId = useAuth((s) => s.branchId);
  const [state, setState] = useState<PageState<P>>({ page: null, error: null, loading: true });
  const current = useRef(path);
  current.current = path;

  const load = useCallback(async () => {
    setState((s) => ({ ...s, loading: true }));
    try {
      const page = await getPage<P>(path);
      if (current.current !== path) return;
      remember(page as AppPage);
      setState({ page, error: null, loading: false });
    } catch (e) {
      if (current.current !== path) return;
      // A refresh that fails keeps what is shown.
      setState((s) => (s.page ? { ...s, loading: false } : { page: null, error: errorMessage(e), loading: false }));
    }
    // Another branch chosen: the same page, loaded there.
  }, [path, branchId]);

  useEffect(() => {
    void load();
    if (!every) return;
    const timer = window.setInterval(() => document.visibilityState === 'visible' && void load(), every * 1000);
    return () => window.clearInterval(timer);
  }, [load, every]);

  return { ...state, reload: load };
}

export type FieldErrors = Record<string, string>;

/**
 * Actions from a page, as its forms on the web: the message shown, then the
 * page the restaurant names, or this one again. Validation comes back per
 * field.
 */
export function useAction(from: string, reload: () => Promise<void> | void) {
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});

  const run = useCallback(
    async (method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, data: unknown = {}): Promise<boolean> => {
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
        }
        toast(errorMessage(e), e instanceof ApiError && e.offline ? 'amber' : 'red');
        return false;
      } finally {
        setBusy(false);
      }
    },
    [from, reload],
  );

  return { run, busy, errors, setErrors };
}
