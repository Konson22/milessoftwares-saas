/** Where the app finds the platform: .env (VITE_*), baked in at build time. */

const env = import.meta.env;

const WORKSPACE_URL = (env.VITE_WORKSPACE_URL ?? 'https://{workspace}.milesoftwares.com').replace(/\/$/, '');
const PRODUCT_PATH = (env.VITE_PRODUCT_PATH ?? 'restaurant').replace(/^\/|\/$/g, '');

export function workspaceUrl(workspace: string): string {
  return WORKSPACE_URL.replace('{workspace}', workspace);
}

/** The restaurant on the web, for what the app does not do itself: {workspace}/restaurant/<path>. */
export function webUrl(workspace: string, path = ''): string {
  return `${workspaceUrl(workspace)}/${PRODUCT_PATH}${path ? `/${path.replace(/^\//, '')}` : ''}`;
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
