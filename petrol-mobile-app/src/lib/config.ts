/**
 * Where the app finds the platform. Set in .env (EXPO_PUBLIC_*), baked in
 * at build time.
 */

/** The platform's auth host (OpenID provider): sign-in and tokens. */
export const AUTH_URL = (process.env.EXPO_PUBLIC_AUTH_URL ?? 'https://auth.tatook.com').replace(/\/$/, '');

/** The mobile app's public client, registered on the platform for the station. */
export const CLIENT_ID = process.env.EXPO_PUBLIC_CLIENT_ID ?? '';

/**
 * Native Google sign-in (Google Cloud OAuth clients). The web client's ID is
 * the platform's GOOGLE_CLIENT_ID: Google issues the ID token for it, and the
 * platform takes it. Android finds its own client by package name and
 * signing key; iOS names its client here (and its URL scheme in app.config.js).
 */
export const GOOGLE_WEB_CLIENT_ID = process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID ?? '';
export const GOOGLE_IOS_CLIENT_ID = process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID ?? '';

/**
 * A workspace's address; {workspace} is its slug. Local development points
 * it at the dev PC (http://{workspace}.milesoftwares.localhost does not
 * resolve on a phone).
 */
const WORKSPACE_URL = (process.env.EXPO_PUBLIC_WORKSPACE_URL ?? 'https://{workspace}.tatook.com').replace(/\/$/, '');

/** The station's path on workspace hosts. */
export const PRODUCT_PATH = (process.env.EXPO_PUBLIC_PRODUCT_PATH ?? 'petrol').replace(/^\/|\/$/g, '');

export function workspaceUrl(workspace: string): string {
  return WORKSPACE_URL.replace('{workspace}', workspace);
}

/** The station on the web: {workspace}/petrol/<path>. */
export function webUrl(workspace: string, path = ''): string {
  return `${workspaceUrl(workspace)}/${PRODUCT_PATH}${path ? `/${path.replace(/^\//, '')}` : ''}`;
}

/**
 * Local development on a phone: it cannot resolve *.milesoftwares.localhost,
 * so the app's own calls go to this address (e.g. http://127.0.0.1 with
 * `adb reverse tcp:80 tcp:80`) and name the host in the Host header. The
 * sign-in browser needs nothing: it sends *.localhost to loopback itself.
 */
const DEV_SERVER = (process.env.EXPO_PUBLIC_DEV_SERVER ?? '').replace(/\/$/, '');

/** Where a call to `url` actually goes, and the headers that keep its host. */
export function reach(url: string): { url: string; headers: Record<string, string> } {
  if (!DEV_SERVER) return { url, headers: {} };
  const match = url.match(/^https?:\/\/([^/]+)(.*)$/);
  if (!match) return { url, headers: {} };
  return { url: `${DEV_SERVER}${match[2]}`, headers: { Host: match[1] } };
}

/** A workspace address is lowercase letters, digits and dashes. */
export function normalizeWorkspace(input: string): string {
  return input
    .trim()
    .toLowerCase()
    .replace(/^https?:\/\//, '')
    .split('.')[0]
    .replace(/[^a-z0-9-]/g, '');
}
