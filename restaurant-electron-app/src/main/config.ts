/** Where the app finds the platform: .env (VITE_*), baked in at build time. */

const env = import.meta.env;

/** The platform's auth host (OpenID provider): sign-in, tokens, a new business. */
export const AUTH_URL = (env.VITE_AUTH_URL ?? 'https://auth.milesoftwares.com').replace(/\/$/, '');

/** The loopback port the sign-in comes back to (RFC 8252): http://127.0.0.1:<port>/oauth. */
export const OAUTH_PORT = Number(env.VITE_OAUTH_PORT ?? 48218);

/** The restaurant's public client, registered on the platform for the restaurant application. */
export const CLIENT_ID = env.VITE_CLIENT_ID ?? '';

const WORKSPACE_URL = (env.VITE_WORKSPACE_URL ?? 'https://{workspace}.milesoftwares.com').replace(/\/$/, '');

/** The restaurant's path on workspace hosts. */
export const PRODUCT_PATH = (env.VITE_PRODUCT_PATH ?? 'restaurant').replace(/^\/|\/$/g, '');

export function workspaceUrl(workspace: string): string {
  return WORKSPACE_URL.replace('{workspace}', workspace);
}
