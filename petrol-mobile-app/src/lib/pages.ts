import { cachedPage, storePage } from '@/offline/cache';

import { isOffline, request } from './api/client';

/**
 * The station's pages as data. The workspace answers them (petrol-app
 * App\Support\AppPages): GET {workspace}/petrol/api/v1/app/<web path> gives
 * the page the web would render, its component and props; an action answers
 * its message and the page to go to next. Paths are the web's ("sales",
 * "sales/<id>").
 *
 * Every page loaded is kept on the phone (src/offline/cache.ts) and shown
 * when the station cannot be reached. A sale recorded then waits on the
 * phone and is sent when it can (src/offline/outbox.ts).
 */

export interface AppPage<P = Record<string, any>> {
  component: string;
  props: P & SharedProps;
  url: string;
  /** The copy kept on the phone, shown without a connection. */
  stale?: boolean;
}

/** What every page carries (HandleInertiaRequests). */
export interface SharedProps {
  access: { isOwner: boolean; permissions: string[] } | null;
  branch: { id: string; name: string } | null;
  branchPermissions: string[];
  currency: string | null;
}

export interface ActionResult {
  message: string | null;
  /** The page to show next; null: stay, and reload this one. */
  redirect: string | null;
}

export type Method = 'POST' | 'PUT' | 'PATCH' | 'DELETE';

const base = (path: string) => `v1/app/${path.replace(/^\//, '')}`;

export async function getPage<P>(path: string): Promise<AppPage<P>> {
  const clean = path.replace(/^\//, '');
  try {
    const page = await request<AppPage<P>>('GET', base(clean));
    void storePage(clean, page as AppPage);
    return page;
  } catch (error) {
    if (isOffline(error)) {
      const kept = await cachedPage<P>(clean);
      if (kept) return { ...kept, stale: true };
    }
    throw error;
  }
}

/** An action of the page at `from`, as its form on the web would send it. */
export function act(method: Method, path: string, data: unknown, from: string): Promise<ActionResult> {
  return request<ActionResult>(method, base(path.replace(/^\//, '')), data, { 'X-App-Page': from.split('?')[0] });
}

/** A query string from filters, leaving out empty ones. */
export function withQuery(path: string, query: Record<string, string | number | boolean | null | undefined>): string {
  const params = Object.entries(query)
    .filter(([, value]) => value !== null && value !== undefined && value !== '' && value !== false)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value === true ? '1' : String(value))}`);
  return params.length ? `${path}?${params.join('&')}` : path;
}
