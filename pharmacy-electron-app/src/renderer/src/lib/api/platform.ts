import type { Product } from '../../../../shared/ipc';
import { ApiError } from './client';

/**
 * The platform as the person signed in (src/main/http.ts platformRequest):
 * the businesses they are in, and a new business of their own, before
 * there is a workspace to call.
 */

export interface NewBusiness {
  display_name: string;
  legal_name: string;
  /** The workspace address: <slug>.milesoftwares.com. */
  slug: string;
  country_code: string;
  default_currency: string;
  timezone: string;
  /** Its first branch, where the counter opens. */
  branch_name: string;
  branch_city: string | null;
}

export interface CreatedBusiness {
  organization: { id: string; slug: string; name: string };
  /** The free trial of this app's product, when it offers one. */
  trial: { application: string; ends_at: string | null } | null;
}

async function call<T>(product: Product, method: string, path: string, body?: unknown): Promise<T> {
  const response = await window.desktop.platform({ method, product, path, body });
  if (response.offline) throw new ApiError('Cannot reach Miles Softwares. Check the connection.', 0, {}, true);

  const { status, json } = response;
  if (status < 200 || status >= 300) {
    const errors = (json?.errors ?? {}) as Record<string, string[]>;
    const message =
      Object.values(errors)[0]?.[0] ??
      json?.message ??
      (status === 401 ? 'Sign in again.' : status === 429 ? 'Too many attempts. Wait a while, then try again.' : `Request failed (${status})`);
    throw new ApiError(message, status, errors, false, json?.error ?? null);
  }
  return json as T;
}

export function slugAvailability(product: Product, slug: string): Promise<{ available: boolean; errors: string[] }> {
  return call(product, 'GET', `organizations/slug-availability?${new URLSearchParams({ slug })}`);
}

export function createBusiness(product: Product, business: NewBusiness): Promise<CreatedBusiness> {
  return call(product, 'POST', 'organizations', business);
}

/** A business the person is in that uses this product (the platform answers for its client). */
export interface BusinessChoice {
  id: string;
  /** Its workspace address. */
  slug: string;
  name: string;
  status: 'active' | 'suspended';
  is_owner: boolean;
}

/** The person's businesses with the pharmacy (or the shop): signing in needs no workspace address. */
export async function businesses(product: Product): Promise<BusinessChoice[]> {
  return (await call<{ data: BusinessChoice[] }>(product, 'GET', 'organizations')).data;
}
