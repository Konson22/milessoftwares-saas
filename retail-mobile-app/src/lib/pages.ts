import { call } from './api';

/**
 * The shop's back-office pages as data, answered by the database on
 * this phone (src/local/db/pages.ts), in the same shape the online app reads
 * from the workspace: a page is its component and props; an action answers
 * its message and the page to go to next. Paths are as the web's ("stock",
 * "products/<id>/edit").
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

export function getPage<P>(path: string): Promise<AppPage<P>> {
  return call<AppPage<P>>('page', path.replace(/^\//, ''));
}

/** An action of the page at `from` (POST, PUT, DELETE), as its form would send it. */
export function act(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, data: unknown, _from?: string): Promise<ActionResult> {
  return call<ActionResult>('act', method, path.replace(/^\//, ''), data);
}

/** A query string from filters, leaving out empty ones. */
export function withQuery(path: string, query: Record<string, string | number | boolean | null | undefined>): string {
  const params = Object.entries(query)
    .filter(([, value]) => value !== null && value !== undefined && value !== '' && value !== false)
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(value === true ? '1' : String(value))}`);
  return params.length ? `${path}?${params.join('&')}` : path;
}
