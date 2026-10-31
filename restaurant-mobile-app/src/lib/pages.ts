import * as local from '@/local/pages';
import { LocalError } from '@/local/db/util';

import { ApiError, request } from './api/client';
import { mode } from './mode';

/**
 * The restaurant's pages as data, the same in both modes. Online, the
 * workspace answers them (restaurant-app App\Support\AppPages):
 * GET {workspace}/restaurant/api/v1/app/<web path> gives the page the web
 * would render, its component and props; an action answers its message and
 * the page to go to next. Offline, the restaurant on this phone
 * (src/local/pages.ts) answers the same paths with the same shapes, by the
 * same rules. Paths are the web's ("floor", "orders/<id>").
 */

export interface AppPage<P = Record<string, any>> {
  component: string;
  props: P & SharedProps;
  url: string;
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

const online = () => mode() === 'online';
const base = (path: string) => `v1/app/${path.replace(/^\//, '')}`;

/** The phone's answer, or its refusal as the workspace would give it. */
async function onPhone<T>(task: () => T): Promise<T> {
  // A tick first, so a screen can show that it is busy.
  await new Promise((resolve) => setTimeout(resolve, 0));
  try {
    return task();
  } catch (error) {
    if (error instanceof LocalError) {
      if (error.status === 401) local.onSignedOut();
      throw new ApiError(error.message, error.status, error.errors, false, error.code);
    }
    console.error('The restaurant on this phone failed', error);
    throw new ApiError(error instanceof Error ? error.message : 'Something went wrong.', 500);
  }
}

export function getPage<P>(path: string): Promise<AppPage<P>> {
  const clean = path.replace(/^\//, '');
  if (online()) return request<AppPage<P>>('GET', base(clean));
  return onPhone(() => local.getPage(clean) as AppPage<P>);
}

/** An action of the page at `from`, as its form on the web would send it. */
export function act(method: Method, path: string, data: unknown, from: string): Promise<ActionResult> {
  const clean = path.replace(/^\//, '');
  if (online()) return request<ActionResult>(method, base(clean), data, { 'X-App-Page': from.split('?')[0] });
  return onPhone(() => local.act(method, clean, (data ?? {}) as Record<string, unknown>));
}

/** A query string from filters, leaving out empty ones. */
export function withQuery(path: string, query: Record<string, string | number | boolean | null | undefined>): string {
  const params = Object.entries(query)
    .filter(([, value]) => value !== null && value !== undefined && value !== '' && value !== false)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value === true ? '1' : String(value))}`);
  return params.length ? `${path}?${params.join('&')}` : path;
}
