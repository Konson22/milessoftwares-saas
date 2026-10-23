import { call } from './api';

/**
 * The back office as pages (src/main/db/pages.ts): a path ("stock",
 * "medicines/<id>/edit") answers the screen to draw and its props; an action
 * answers its message and the page to go to next.
 */

export interface AppPage<P = Record<string, any>> {
  component: string;
  props: P & SharedProps;
  url: string;
}

/** What every page carries. */
export interface SharedProps {
  access: { isOwner: boolean; permissions: string[] } | null;
  branch: { id: string; name: string } | null;
  branchPermissions: string[];
  currency: string | null;
}

export interface ActionResult {
  message: string | null;
  print: unknown;
  /** The page to show next; null: stay, and reload this one. */
  redirect: string | null;
}

export function getPage<P>(path: string): Promise<AppPage<P>> {
  return call<AppPage<P>>('page', path);
}

/** An action (POST, PUT, DELETE) on a path, with the form's data. */
export function act(method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, data: unknown): Promise<ActionResult> {
  return call<ActionResult>('act', method, path, data);
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
