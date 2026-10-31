import { router, useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { create } from 'zustand';

import { ApiError, errorMessage } from '@/lib/api';
import { act, getPage, type AppPage, type SharedProps } from '@/lib/pages';
import { haptic } from '@/lib/feedback';
import { toast } from '@/store/toast';

/** What the member may do, from the last page the shop answered (for the menu). */
export const useAccess = create<{ shared: SharedProps | null; clear(): void }>((set) => ({
  shared: null,
  clear: () => set({ shared: null }),
}));

function remember(page: AppPage) {
  const { access, branch, branchPermissions, currency } = page.props;
  useAccess.setState({ shared: { access, branch, branchPermissions, currency } });
}

/** The menu's permissions: the dashboard's, loaded on opening and on a branch change. */
export async function loadAccess() {
  try {
    remember(await getPage(''));
  } catch {
    // Offline, or the demo: the counter only.
  }
}

/** A section or record opened from a page: in the drawer's own screen, or pushed over it. */
export function openPage(path: string) {
  router.push({ pathname: '/page/[...path]', params: { path: path.split('/') } });
}

/** A back-office page from the shop, loaded again whenever its screen comes back into view. */
export function usePage<P = Record<string, any>>(path: string) {
  const [page, setPage] = useState<AppPage<P> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  // The path last asked for: an older answer arriving late is dropped.
  const current = useRef(path);
  useEffect(() => {
    current.current = path;
  }, [path]);

  const reload = useCallback(async () => {
    setLoading(true);
    try {
      const data = await getPage<P>(path);
      if (current.current !== path) return;
      remember(data as AppPage);
      setPage(data);
      setError(null);
    } catch (e) {
      if (current.current !== path) return;
      setError(errorMessage(e));
    } finally {
      if (current.current === path) setLoading(false);
    }
  }, [path]);

  useFocusEffect(
    useCallback(() => {
      void reload();
    }, [reload]),
  );

  return { page, error, loading, reload };
}

/**
 * After a form: a section ("products") is the screen it was opened from,
 * so back to it; a record ("receipts/<id>") takes the form's place.
 */
function leaveFor(path: string) {
  if (!router.canGoBack()) return openPage(path);
  if (!path.includes('/')) return router.back();
  router.replace({ pathname: '/page/[...path]', params: { path: path.split('/') } });
}

export type FieldErrors = Record<string, string>;

/**
 * Actions from a page, as its forms on the web: the message shown, then the
 * page the shop names, or this one again. Validation comes back per field.
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
        haptic.success();
        if (result.message) toast(result.message);
        if (result.redirect !== null && result.redirect !== from) leaveFor(result.redirect);
        else await reload();
        return true;
      } catch (e) {
        haptic.error();
        if (e instanceof ApiError && e.status === 422) {
          setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        }
        toast(errorMessage(e), 'error');
        return false;
      } finally {
        setBusy(false);
      }
    },
    [from, reload],
  );

  return { run, busy, errors, setErrors };
}
