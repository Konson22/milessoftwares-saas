import { API_MODE, ApiError } from './api';
import { call, request } from './api/client';
import { isLocal } from './mode';

/**
 * The pharmacy's web pages as data (pharmacy-app App\Support\AppPages):
 * GET {workspace}/pharmacy/api/v1/app/<web path> answers the page the web
 * would render, its component and props; an action answers its message and
 * the page to go to next. Same controllers, same permissions as the web.
 * Paths are the web's ("stock", "purchase-orders/<id>").
 *
 * Offline, the database on this PC answers them (src/main/db/pages.ts), in
 * the same shapes, with its own pages ("medicines/<id>/edit",
 * "receipts/create").
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
  /** A document to print, as the web's flash.print. */
  print: unknown;
  /** The page to show next; null: stay, and reload this one. */
  redirect: string | null;
}

const base = (path: string) => `v1/app/${path.replace(/^\//, '')}`;

export function pagesAvailable() {
  return isLocal() || API_MODE === 'live';
}

export function getPage<P>(path: string): Promise<AppPage<P>> {
  if (isLocal()) return call<AppPage<P>>('page', path.replace(/^\//, ''));
  if (!pagesAvailable()) return Promise.reject(new ApiError('Back-office pages work with a workspace (live mode), not the demo.', 404));
  return request<AppPage<P>>('GET', base(path));
}

/** An action of the page at `from` (POST, PUT, DELETE), as its form would send it. */
export function act(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, data: unknown, from = path): Promise<ActionResult> {
  if (isLocal()) return call<ActionResult>('act', method, path.replace(/^\//, ''), data);
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
