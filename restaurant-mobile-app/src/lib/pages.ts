import { cachedPage, scope, storePage } from '@/offline/cache';
import { floorAction } from '@/offline/local';
import { localIds, pendingOrders, useOutbox } from '@/offline/outbox';
import { useSync } from '@/offline/sync';

import { isOffline, request } from './api/client';

/**
 * The restaurant's pages as data. The workspace answers them
 * (restaurant-app App\Support\AppPages): GET
 * {workspace}/restaurant/api/v1/app/<web path> gives the page the web would
 * render, its component and props; an action answers its message and the
 * page to go to next. Paths are the web's ("floor", "orders/<id>").
 *
 * Every page loaded is kept on the phone (src/offline/cache.ts) and shown
 * when the restaurant cannot be reached. The floor's own actions (an order
 * opened, lines, the kitchen, payment) still work then: they wait on the
 * phone and are sent when it can (src/offline).
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

/** A record made on the phone that the restaurant knows by another id now. */
function renamed(path: string): string {
  const [section, id, ...rest] = path.split('/');
  const to = id ? useSync.getState().renamed[id.split('?')[0]] : undefined;
  return to ? [section, to, ...rest].join('/') : path;
}

/**
 * Pages the phone answers itself while something on them waits to be sent:
 * the restaurant does not know it yet, so its page would not show it.
 */
function phoneAnswers(path: string): boolean {
  const owner = scope();
  if (!owner) return false;
  const { entries } = useOutbox.getState();
  const [section, id] = path.split('?')[0].split('/');
  if (section === 'floor' && !id) return entries.some((e) => e.owner === owner && e.error === null);
  if (section === 'orders' && id) return pendingOrders(entries, owner).has(id);
  if (section === 'bills' && id) return localIds(entries, owner).has(id);
  return false;
}

export async function getPage<P>(path: string): Promise<AppPage<P>> {
  const clean = renamed(path.replace(/^\//, ''));
  if (phoneAnswers(clean)) {
    const kept = await cachedPage<P>(clean);
    if (kept) return kept;
  }
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
export async function act(method: Method, path: string, data: unknown, from: string): Promise<ActionResult> {
  const clean = renamed(path.replace(/^\//, ''));
  const onFloor = await floorAction(method, clean, (data ?? {}) as Record<string, any>);
  if (onFloor) return onFloor;
  return request<ActionResult>(method, base(clean), data, { 'X-App-Page': from.split('?')[0] });
}

/** A query string from filters, leaving out empty ones. */
export function withQuery(path: string, query: Record<string, string | number | boolean | null | undefined>): string {
  const params = Object.entries(query)
    .filter(([, value]) => value !== null && value !== undefined && value !== '' && value !== false)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value === true ? '1' : String(value))}`);
  return params.length ? `${path}?${params.join('&')}` : path;
}
