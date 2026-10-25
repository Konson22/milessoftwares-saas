import { request } from './api';

/**
 * The restaurant's web pages as data (restaurant-app App\Support\AppPages):
 * GET {workspace}/restaurant/api/v1/app/<web path> answers the page the web
 * would render, its component and props; an action answers its message and
 * the page to go to next. Same controllers, same permissions as the web.
 * Paths are the web's ("floor", "orders/<id>").
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

const base = (path: string) => `v1/app/${path.replace(/^\//, '')}`;

export function getPage<P>(path: string): Promise<AppPage<P>> {
  return request<AppPage<P>>('GET', base(path));
}

/** An action of the page at `from` (POST, PUT, DELETE), as its form on the web would send it. */
export function act(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, data: unknown, from: string): Promise<ActionResult> {
  return request<ActionResult>(method, base(path), data, { 'X-App-Page': from.split('?')[0] });
}

/** A query string from filters, leaving out empty ones. */
export function withQuery(path: string, query: Record<string, string | number | boolean | null | undefined>): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value === null || value === undefined || value === '' || value === false) continue;
    params.set(key, value === true ? '1' : String(value));
  }
  const q = params.toString();
  return q ? `${path}?${q}` : path;
}
