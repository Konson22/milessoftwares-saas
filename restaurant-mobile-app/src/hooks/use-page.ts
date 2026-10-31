import { router, useFocusEffect } from 'expo-router';
import { useCallback, useEffect, useRef, useState } from 'react';
import { create } from 'zustand';

import { ApiError, errorMessage } from '@/lib/api/client';
import { haptic } from '@/lib/feedback';
import { mode } from '@/lib/mode';
import { act, getPage, type AppPage, type Method, type SharedProps } from '@/lib/pages';
import { useAuth } from '@/store/auth';
import { toast } from '@/store/toast';

/** What the member may do, from the last page the restaurant answered (for the menu and the tabs). */
export const useAccess = create<{ shared: SharedProps | null; clear(): void }>((set) => ({
  shared: null,
  clear: () => set({ shared: null }),
}));

function remember(page: AppPage) {
  const { access, branch, branchPermissions, currency } = page.props;
  useAccess.setState({ shared: { access, branch, branchPermissions, currency } });
}

/** The menu's permissions: the home page's, loaded on opening and on a branch change. */
export async function loadAccess() {
  try {
    remember(await getPage(''));
  } catch {
    // Unreachable: the menu fills in with the next page that loads.
  }
}

/** A record or page opened from another: pushed over it. */
export function openPage(path: string) {
  router.push({ pathname: '/page/[...path]', params: { path: path.split('/') } });
}

/**
 * A page of the restaurant, loaded again whenever its screen comes back into
 * view. With `every`, online, also every so many seconds while it is shown
 * (the floor, the kitchen: other phones change them).
 */
export function usePage<P = Record<string, any>>(path: string, every?: number) {
  const branchId = useAuth((s) => s.branchId);
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
      // A refresh that fails keeps what is shown.
      setError(errorMessage(e));
    } finally {
      if (current.current === path) setLoading(false);
    }
    // Another branch chosen: the same page, loaded there.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, branchId]);

  useFocusEffect(
    useCallback(() => {
      void reload();
      if (!every || mode() !== 'online') return;
      const timer = setInterval(() => void reload(), every * 1000);
      return () => clearInterval(timer);
    }, [reload, every]),
  );

  return { page, error, loading, reload };
}

/**
 * After an action that names another page: a section ("floor") is a tab or
 * the screen this one was opened from, so back to it; a record
 * ("orders/<id>") opens over a section, or takes another record's place.
 */
function leaveFor(path: string, from: string) {
  const section = path.split(/[/?]/)[0];
  if (!path.includes('/')) {
    if (TAB_SECTIONS.has(section)) return router.navigate(`/${section}` as never);
    if (router.canGoBack()) return router.back();
  }
  if (!from.includes('/')) return openPage(path);
  router.replace({ pathname: '/page/[...path]', params: { path: path.split('/') } });
}

/** Sections that are tabs of their own. */
export const TAB_SECTIONS = new Set(['floor', 'kitchen', 'bills', 'tills']);

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
    async (method: Method, path: string, data: unknown = {}): Promise<boolean> => {
      setBusy(true);
      setErrors({});
      try {
        const result = await act(method, path, data, from);
        haptic.success();
        if (result.message) toast(result.message);
        if (result.redirect !== null && result.redirect !== from) leaveFor(result.redirect, from);
        else await reload();
        return true;
      } catch (e) {
        haptic.error();
        if (e instanceof ApiError && e.status === 422) {
          setErrors(Object.fromEntries(Object.entries(e.errors).map(([k, v]) => [k, v[0]])));
        }
        toast(errorMessage(e), e instanceof ApiError && e.offline ? 'warning' : 'error');
        return false;
      } finally {
        setBusy(false);
      }
    },
    [from, reload],
  );

  return { run, busy, errors, setErrors };
}
