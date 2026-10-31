import { accessToken } from '../auth/tokens';
import { AUTH_URL, reach } from '../config';
import { ApiError } from './client';

/**
 * The platform as the person signed in (central-app routes/api.php,
 * /api/v1/account): the businesses they are in that use the restaurant.
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

async function call<T>(method: string, path: string, retried = false): Promise<T> {
  const token = await accessToken(retried);
  if (!token) throw new ApiError('Sign in again.', 401);

  const target = reach(`${AUTH_URL}/api/v1/account/${path.replace(/^\//, '')}`);
  let response: Response;
  try {
    response = await fetch(target.url, {
      method,
      headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, ...target.headers },
    });
  } catch {
    throw new ApiError('Cannot reach Miles Softwares. Check the connection.', 0, {}, true);
  }

  const json = await response.json().catch(() => null);
  if (response.status === 401 && !retried && json?.error === 'invalid_token') return call<T>(method, path, true);
  if (!response.ok) {
    throw new ApiError(json?.message ?? (response.status === 401 ? 'Sign in again.' : `Request failed (${response.status})`), response.status);
  }
  return json as T;
}

export const platform = {
  /** Only the businesses with the restaurant: the platform answers for this app's client. */
  restaurants: async () => (await call<{ data: RestaurantChoice[] }>('GET', 'organizations')).data,
};
