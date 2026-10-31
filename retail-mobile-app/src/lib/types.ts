import type { PriceTier } from './pricing';

/**
 * What the shop's database on this phone answers the screens (src/local/db).
 * Ids are UUIDs; amounts are integers in the currency's minor unit (cents).
 */

export type Role = 'manager' | 'supervisor' | 'cashier';

export interface User {
  id: string;
  name: string;
  username: string;
  role: Role;
}

export interface Organization {
  id: string;
  slug: string;
  name: string;
  currency: string;
  /** Minor-unit digits of the currency (2, or 0 for UGX, RWF…). */
  decimals: number;
  /** Prices also show in this currency: 1 of it is `rate` of the shop's own. */
  second_currency: { code: string; decimals: number; rate: number } | null;
}

export interface Branch {
  id: string;
  name: string;
  code: string;
  city: string | null;
}

/** Who is signed in, at which shop (its one branch). */
export interface Me {
  user: User;
  organization: Organization;
  /** A manager: may do everything. */
  is_owner: boolean;
  branches: Branch[];
  reason: null;
}

export interface PaymentMethodOption {
  value: PaymentMethod;
  label: string;
}

export type PaymentMethod = 'cash' | 'mobile_money' | 'card' | 'bank_transfer' | 'other';
/** How a sale was paid: one of the methods, or on the customer's account (an invoice). */
export type SaleMethod = PaymentMethod | 'account';

/** What this member may do at the counter, and its rules (Settings). */
export interface Counter {
  branch: { id: string; name: string; code: string };
  can: {
    sell: boolean;
    view_sales: boolean;
    void: boolean;
    discount: boolean;
    manage_tills: boolean;
    /** On a customer's account, up to their limit. */
    sell_on_account: boolean;
  };
  max_discount_percent: number;
  counter: {
    shifts_required: boolean;
    default_float_minor: number;
    /** Counted blind: the cashier does not see what the drawer should hold. */
    blind_close: boolean;
    require_customer_always: boolean;
    require_customer_above_minor: number | null;
  };
  payment_methods: PaymentMethodOption[];
  receipt: {
    business_name: string | null;
    address: string | null;
    contact: string | null;
    header: string | null;
    footer: string | null;
    show_customer: boolean;
    show_staff: boolean;
    paper_size: string;
  };
  till: { id: string; number: string } | null;
}

export interface Pack {
  id: string;
  name: string;
  units: number;
  price_minor: number;
}

export interface Item {
  id: string;
  name: string;
  unit: string;
  barcode: string | null;
  category: string | null;
  price_minor: number;
  /** Sellable units here: unexpired, not held back. */
  available: number;
  nearest_expiry: string | null;
  packs: Pack[];
  /** What the next units sell for, batch by batch as they leave the shelf (soonest to expire first, then the oldest). */
  price_tiers: PriceTier[];
}

export interface CartLine {
  item: Item;
  /** Sold by this pack (so many packs), or by the unit. */
  pack_id: string | null;
  quantity: number;
  discount_percent: number;
}

export interface Till {
  id: string;
  number: string;
  status: 'open' | 'closed';
  opened_at: string;
  closed_at: string | null;
  opening_float_minor: number;
  counted_cash_minor: number | null;
  /** Null when counted blind. */
  expected_cash_minor: number | null;
  difference_minor: number | null;
  closing_note: string | null;
  blind: boolean;
  summary: {
    sales_count: number;
    refunds_count: number;
    taken_minor: Record<string, number> | null;
    collected_minor: Record<string, number> | null;
    cash_refunds_minor: number | null;
    cash_in_minor: number;
    cash_out_minor: number;
    /** Paid in by customers toward their accounts, by method. */
    received_minor: Record<string, number> | null;
  };
  /** Shown even when counted blind: the member records them. */
  movements: CashMovement[];
}

export type CashDirection = 'in' | 'out';
/** Where the cash came from or went: the safe (nothing for the books), the bank, or an expense (out only). */
export type CashCounterpart = 'safe' | 'bank' | 'expense';

/** Cash put into or taken out of the drawer during a shift. */
export interface CashMovement {
  id: string;
  direction: CashDirection;
  counterpart: CashCounterpart;
  /** "Banked", "Paid out", "To the safe"… */
  label: string;
  amount_minor: number;
  reason: string;
  recorded_by: string;
  recorded_at: string;
}

export interface SalePayload {
  items: { item_id: string; quantity: number; discount_percent?: number; pack_id?: string | null }[];
  submission_id: string;
  payment_method: SaleMethod;
  payment_reference?: string | null;
  amount_tendered_minor?: number | null;
  customer_name?: string | null;
  customer_phone?: string | null;
  /** A customer with an account; required on account. */
  customer_id?: string | null;
}

/** A customer as the counter picks one. */
export interface CustomerOption {
  id: string;
  number: string;
  name: string;
  phone: string | null;
  on_account: boolean;
  /** What they owe now. */
  balance_minor: number;
  /** What more they may owe; null: no limit (0 when they do not buy on account). */
  available_minor: number | null;
}

export type SaleStatus = 'completed' | 'voided';

export interface SaleSummary {
  id: string;
  number: string;
  status: SaleStatus;
  sold_at: string;
  sold_by: string;
  customer_name: string | null;
  payment_method: SaleMethod;
  payment_method_label: string;
  total_minor: number;
  returned: boolean;
}

export interface Sale {
  id: string;
  number: string;
  status: SaleStatus;
  sold_at: string;
  sold_by: string;
  customer_name: string | null;
  customer_phone: string | null;
  /** On account: the customer, and what they owe after this. */
  customer: { id: string; number: string; name: string; balance_minor: number } | null;
  /** An invoice (a sale on account), due on this day. */
  invoice: boolean;
  due_on: string | null;
  currency: string;
  total_minor: number;
  discount_minor: number;
  payment_method: SaleMethod;
  payment_method_label: string;
  payment_reference: string | null;
  amount_tendered_minor: number | null;
  change_minor: number | null;
  voided_at: string | null;
  voided_by: string | null;
  void_reason: string | null;
  /** One line per product, as printed. */
  receipt_lines: {
    name: string;
    pack: string | null;
    quantity: number;
    unit_price_minor: number;
    discount_minor: number;
    line_total_minor: number;
  }[];
  /** Batch by batch, in units: what a return counts in. */
  lines: {
    id: string;
    name: string;
    batch_number: string;
    expires_on: string | null;
    quantity: number;
    returned: number;
    line_total_minor: number;
  }[];
  returns: { id: string; number: string; returned_at: string; reason: string; total_minor: number }[];
  can_void: boolean;
  can_return: boolean;
}

export interface ReturnPayload {
  reason: string;
  lines: { sale_line_id: string; quantity: number; restock: boolean }[];
}

/** A cart parked on this device (the till is per device and person). */
export interface HeldCart {
  id: string;
  label: string | null;
  customer_name: string;
  lines: CartLine[];
  held_at: string;
}
