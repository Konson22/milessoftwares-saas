import type { Product } from '../../../shared/ipc';

/** Where the app finds the platform: .env (VITE_*), baked in at build time. */

const env = import.meta.env;

/** "mock" runs a demo pharmacy inside the app; "live" talks to the workspace. */
export const API_MODE: 'mock' | 'live' = env.VITE_API_MODE === 'live' ? 'live' : 'mock';

const WORKSPACE_URL = (env.VITE_WORKSPACE_URL ?? 'https://{workspace}.milesoftwares.com').replace(/\/$/, '');

const trim = (path: string) => path.replace(/^\/|\/$/g, '');

/**
 * What the counter sells for: the pharmacy, or a shop (Retail, served by the
 * same deployment at its own path). `place` is how the app names it in a
 * sentence ("No connection to the shop").
 */
export const PRODUCTS: Record<Product, { name: string; label: string; place: string; path: string }> = {
  pharmacy: { name: 'Miles Pharmacy', label: 'Pharmacy', place: 'pharmacy', path: trim(env.VITE_PRODUCT_PATH ?? 'pharmacy') },
  retail: { name: 'Miles Retail', label: 'Shop', place: 'shop', path: trim(env.VITE_RETAIL_PATH ?? 'retail') },
};

/** The shop is offered at sign-in when its client is set up (live only: the demo is a pharmacy). */
export const RETAIL_AVAILABLE = API_MODE === 'live' && !!env.VITE_RETAIL_CLIENT_ID;

let product: Product = 'pharmacy';

/** The product signed in to (store/auth sets it). */
export const currentProduct = (): Product => product;

export function setProduct(next: Product) {
  product = next;
}

/** "pharmacy" or "shop", for wording outside React. */
export const place = () => PRODUCTS[product].place;

export function workspaceUrl(workspace: string): string {
  return WORKSPACE_URL.replace('{workspace}', workspace);
}

/** The pharmacy or shop on the web, for back-office pages: {workspace}/<its path>/<path>. */
export function webUrl(workspace: string, path = ''): string {
  return `${workspaceUrl(workspace)}/${PRODUCTS[product].path}${path ? `/${path.replace(/^\//, '')}` : ''}`;
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
