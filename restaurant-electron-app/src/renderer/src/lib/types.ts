/**
 * Shapes of the restaurant API (restaurant-app routes/api.php), served at
 * {workspace}/restaurant/api/v1. Ids are UUIDs; amounts are integers in the
 * currency's minor unit (cents).
 */

export interface User {
  id: string;
  name: string;
  email: string;
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

/** GET me */
export interface Me {
  user: User;
  organization: Organization;
  is_owner: boolean;
  branches: Branch[];
  /** Why there is no branch to choose: none exist yet, or no restaurant role in one. */
  reason: 'no_branches' | 'no_role' | null;
}
