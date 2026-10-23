import type { Product } from '../shared/ipc';

/** Where the app finds the platform: .env (VITE_*), baked in at build time. */

const env = import.meta.env;

/** The platform's auth host (OpenID provider): sign-in and tokens. */
export const AUTH_URL = (env.VITE_AUTH_URL ?? 'https://auth.milesoftwares.com').replace(/\/$/, '');

/** The loopback port the sign-in comes back to (RFC 8252): http://127.0.0.1:<port>/oauth. */
export const OAUTH_PORT = Number(env.VITE_OAUTH_PORT ?? 48217);

const WORKSPACE_URL = (env.VITE_WORKSPACE_URL ?? 'https://{workspace}.milesoftwares.com').replace(/\/$/, '');

const trim = (path: string) => path.replace(/^\/|\/$/g, '');

/**
 * What the counter sells for: the pharmacy, or a shop (Retail, served by the
 * same deployment). Each has its path on workspace hosts and its own public
 * client, registered on the platform for that application.
 */
export const PRODUCTS: Record<Product, { path: string; clientId: string }> = {
  pharmacy: { path: trim(env.VITE_PRODUCT_PATH ?? 'pharmacy'), clientId: env.VITE_CLIENT_ID ?? '' },
  retail: { path: trim(env.VITE_RETAIL_PATH ?? 'retail'), clientId: env.VITE_RETAIL_CLIENT_ID ?? '' },
};

export function workspaceUrl(workspace: string): string {
  return WORKSPACE_URL.replace('{workspace}', workspace);
}
