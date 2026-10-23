/**
 * Shapes of the Pharmacy API (pharmacy-app routes/api.php), served at
 * {workspace}/pharmacy/api/v1. Ids are UUIDs; amounts are integers in the
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
  /** Why there is no branch to choose: none exist yet, or no pharmacy role in one. */
  reason: 'no_branches' | 'no_role' | null;
}

export interface PaymentMethodOption {
  value: PaymentMethod;
  label: string;
}

export type PaymentMethod = 'cash' | 'mobile_money' | 'card' | 'bank_transfer' | 'other';

/** GET counter: what this member may do at this branch's counter, and its rules. */
export interface Counter {
  branch: { id: string; name: string; code: string };
  can: {
    sell: boolean;
    view_sales: boolean;
    void: boolean;
    discount: boolean;
    manage_tills: boolean;
    view_prescriptions: boolean;
    record_prescriptions: boolean;
    verify_prescriptions: boolean;
  };
  max_discount_percent: number;
  counter: {
    workflow: string;
    workflow_label: string;
    /** The app sells directly and paid; other counters work on the web. */
    sells_direct: boolean;
    shifts_required: boolean;
    default_float_minor: number;
    /** Counted blind: the cashier does not see what the drawer should hold. */
    blind_close: boolean;
    manager_closes_shifts: boolean;
    require_customer_always: boolean;
    require_customer_for_restricted: boolean;
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
  generic_name: string | null;
  strength: string | null;
  form: string | null;
  unit: string;
  barcode: string | null;
  category: string | null;
  price_minor: number;
  /** Sellable units here: unexpired, not held back. */
  available: number;
  nearest_expiry: string | null;
  /** Prescription-only: sold only by dispensing a verified prescription. */
  restricted: boolean;
  controlled: boolean;
  storage: string | null;
  packs: Pack[];
  /** Running today: taken off by itself, unless the line's own discount is bigger. */
  promotion?: { name: string; percent_off: number } | null;
  /** A shop's product sold by serial number: each unit sold names its own. */
  tracks_serials?: boolean;
}

export interface CartLine {
  item: Item;
  /** Sold by this pack (so many packs), or by the unit. */
  pack_id: string | null;
  quantity: number;
  discount_percent: number;
  /** One per unit, for items that track serial numbers. */
  serials?: string[];
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
  items: { item_id: string; quantity: number; discount_percent?: number; pack_id?: string | null; serials?: string[] }[];
  submission_id: string;
  payment_method: PaymentMethod;
  payment_reference?: string | null;
  amount_tendered_minor?: number | null;
  customer_name?: string | null;
  customer_phone?: string | null;
}

export type SaleStatus = 'completed' | 'voided';

export interface SaleSummary {
  id: string;
  number: string;
  status: SaleStatus;
  sold_at: string;
  sold_by: string;
  customer_name: string | null;
  prescription_number: string | null;
  payment_method: PaymentMethod | 'account';
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
  prescription: { id: string; number: string; patient_name: string } | null;
  currency: string;
  total_minor: number;
  discount_minor: number;
  payment_method: PaymentMethod | 'account';
  payment_method_label: string;
  payment_reference: string | null;
  amount_tendered_minor: number | null;
  change_minor: number | null;
  voided_at: string | null;
  voided_by: string | null;
  void_reason: string | null;
  /** One line per medicine, as printed. */
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
    tracks_serials?: boolean;
    /** The serials this line sold, when it tracks them. */
    serials?: string[];
  }[];
  returns: { id: string; number: string; returned_at: string; reason: string; total_minor: number }[];
  can_void: boolean;
  can_return: boolean;
}

export interface ReturnPayload {
  reason: string;
  lines: { sale_line_id: string; quantity: number; restock: boolean; serials?: string[] }[];
}

export type PrescriptionStatus = 'recorded' | 'verified' | 'partly_dispensed' | 'dispensed' | 'rejected' | 'cancelled';

export interface PrescriptionSummary {
  id: string;
  number: string;
  patient_name: string;
  prescriber_name: string;
  issued_on: string;
  expired: boolean;
  status: PrescriptionStatus;
  status_label: string;
  items_count: number;
}

export interface Prescription extends Omit<PrescriptionSummary, 'items_count'> {
  patient_age: number | null;
  prescriber_registration: string | null;
  expires_on: string;
  notes: string | null;
  recorded_by: string;
  verified_by: string | null;
  rejection_reason: string | null;
  items: {
    id: string;
    medicine_id: string;
    name: string;
    unit: string;
    controlled: boolean;
    quantity: number;
    dispensed: number;
    remaining: number;
    unit_price_minor: number;
    directions: string;
    available: number;
  }[];
  sales: { id: string; number: string; sold_at: string; total_minor: number; voided: boolean }[];
  can: { verify: boolean; reject: boolean; dispense: boolean };
}

export interface PrescriptionPayload {
  patient_name: string;
  patient_age?: number | null;
  prescriber_name: string;
  prescriber_registration?: string | null;
  issued_on: string;
  notes?: string | null;
  items: { medicine_id: string; quantity: number; directions: string }[];
}

export interface DispensePayload {
  submission_id: string;
  payment_method: PaymentMethod;
  payment_reference?: string | null;
  amount_tendered_minor?: number | null;
  /** Prescription item id → quantity given now; all that is left when omitted. */
  quantities?: Record<string, number>;
}

/** A cart parked on this device (the till is per device and person). */
export interface HeldCart {
  id: string;
  label: string | null;
  customer_name: string;
  lines: CartLine[];
  held_at: string;
}
