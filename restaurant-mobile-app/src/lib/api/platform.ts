import { accessToken } from '../auth/tokens';
import { AUTH_URL, reach } from '../config';
import { ApiError } from './client';

/**
 * The platform as the person signed in (central-app routes/api.php,
 * /api/v1/account): the businesses they are in that use the restaurant, and
 * a new business of their own, before there is a workspace to call.
 */

/** A business the person is in that uses the restaurant (a plan or a trial). */
export interface RestaurantChoice {
  id: string;
  /** Its workspace address. */
  slug: string;
  name: string;
  status: 'active' | 'suspended';
  is_owner: boolean;
}

export interface NewBusiness {
  display_name: string;
  legal_name: string;
  /** The workspace address: <slug>.tatook.com. */
  slug: string;
  country_code: string;
  default_currency: string;
  timezone: string;
  /** Its first branch, where the floor opens. */
  branch_name: string;
  branch_city: string | null;
}

export interface CreatedBusiness {
  organization: { id: string; slug: string; name: string };
  /** The restaurant's free trial, when it offers one. */
  trial: { application: string; ends_at: string | null } | null;
}

async function call<T>(method: string, path: string, body?: unknown, retried = false): Promise<T> {
  const token = await accessToken(retried);
  if (!token) throw new ApiError('Sign in again.', 401);

  const target = reach(`${AUTH_URL}/api/v1/account/${path.replace(/^\//, '')}`);
  let response: Response;
  try {
    response = await fetch(target.url, {
      method,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        ...target.headers,
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError('Cannot reach Miles Softwares. Check the connection.', 0, {}, true);
  }

  const json = await response.json().catch(() => null);
  if (response.status === 401 && !retried && json?.error === 'invalid_token') return call<T>(method, path, body, true);
  if (!response.ok) {
    const errors = (json?.errors ?? {}) as Record<string, string[]>;
    const message =
      Object.values(errors)[0]?.[0] ??
      json?.message ??
      (response.status === 401
        ? 'Sign in again.'
        : response.status === 429
          ? 'Too many attempts. Wait a while, then try again.'
          : `Request failed (${response.status})`);
    throw new ApiError(message, response.status, errors, false, json?.error ?? null);
  }
  return json as T;
}

export const platform = {
  /** Only the businesses with the restaurant: the platform answers for this app's client. */
  restaurants: async () => (await call<{ data: RestaurantChoice[] }>('GET', 'organizations')).data,
  slugAvailability: (slug: string) =>
    call<{ available: boolean; errors: string[] }>('GET', `organizations/slug-availability?slug=${encodeURIComponent(slug)}`),
  /** A new business, owned by the person, with the restaurant's free trial when it has one. */
  createBusiness: (business: NewBusiness) => call<CreatedBusiness>('POST', 'organizations', business),
};
