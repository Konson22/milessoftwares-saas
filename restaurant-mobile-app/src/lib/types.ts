/**
 * Who is signed in where, as the restaurant's GET api/v1/me answers it
 * (restaurant-app MeController). Ids are UUIDs; amounts are integers in the currency's minor unit.
 */

export interface User {
  id: string;
  name: string;
  /** Online: the platform account. */
  email?: string;
}

export interface Organization {
  id: string;
  slug: string;
  name: string;
  currency: string;
  /** Minor-unit digits of the currency (2, or 0 for UGX, RWF…). */
  decimals: number;
}

export interface Branch {
  id: string;
  name: string;
  code: string;
  city: string | null;
}

export interface Me {
  user: User;
  organization: Organization;
  is_owner: boolean;
  branches: Branch[];
  /** Why there is no branch to choose: none exist yet, or no restaurant role in one. */
  reason: 'no_branches' | 'no_role' | null;
}

