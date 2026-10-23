import { useCallback, useEffect, useRef, useState } from 'react';
import { create } from 'zustand';

import { ApiError, errorMessage } from '@/lib/api';
import { act, getPage, type AppPage, type SharedProps } from '@/lib/pages';
import { toast, useUi } from '@/store/ui';

/** What the member may do, from the last page answered (for the menu). */
export const useAccess = create<{ shared: SharedProps | null }>(() => ({ shared: null }));

function remember(page: AppPage) {
  const { access, branch, branchPermissions, currency } = page.props;
  useAccess.setState({ shared: { access, branch, branchPermissions, currency } });
}

/** The menu's permissions, from the dashboard. */
export async function loadAccess() {
  try {
    remember(await getPage<Record<string, unknown>>(''));
  } catch {
    useAccess.setState({ shared: null });
  }
}

interface PageState<P> {
  page: AppPage<P> | null;
  error: string | null;
  loading: boolean;
}

/** A back-office page, loaded from the database. */
export function usePage<P = Record<string, any>>(path: string) {
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
      setState({ page: null, error: e instanceof ApiError && e.status === 403 ? 'You may not open this page.' : errorMessage(e), loading: false });
    }
  }, [path]);

  useEffect(() => {
    void load();
  }, [load]);

  return { ...state, reload: load };
}

export type FieldErrors = Record<string, string>;

/**
 * Actions from a page, as its forms: the message shown, then the page the
 * action names, or this one again. Validation comes back per field.
 */
export function useAction(from: string, reload: () => Promise<void> | void) {
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<FieldErrors>({});

  const run = useCallback(
    async (method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, data: unknown = {}): Promise<boolean> => {
      setBusy(true);
      setErrors({});
      try {
        const result = await act(method, path, data);
        if (result.message) toast(result.message);
        if (result.redirect !== null && result.redirect !== from) useUi.getState().open(result.redirect);
        else await reload();
        return true;
      } catch (e) {
        if (e instanceof ApiError && e.status === 422) {
          setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        }
        toast(errorMessage(e), 'red');
        return false;
      } finally {
        setBusy(false);
      }
    },
    [from, reload],
  );

  return { run, busy, errors, setErrors };
}
