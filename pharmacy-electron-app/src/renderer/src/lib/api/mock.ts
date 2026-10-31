import { percentOf } from '../format';
import type {
  Branch,
  CashMovement,
  Counter,
  Item,
  Me,
  PaymentMethod,
  Prescription,
  PrescriptionStatus,
  Sale,
  SalePayload,
  SaleSummary,
  Till,
} from '../types';
import { ApiError } from './client';
import type { OnlineApi } from './types';

/**
 * On-device demo pharmacy. Runs the counter's rules as the pharmacy does
 * (shifts, FEFO stock, prescription-only medicines dispensed from a verified
 * prescription, discounts, retries, blind close) so the app can be tried
 * without a workspace. Everything resets when the app restarts.
 */

type Role = 'cashier' | 'pharmacist' | 'manager';

const DEMO_USERS: { id: string; name: string; email: string; role: Role }[] = [
  { id: 'u-cashier', name: 'Deng Cashier', email: 'cashier@demo.milesoftwares.com', role: 'cashier' },
  { id: 'u-pharmacist', name: 'Amina Pharmacist', email: 'pharmacist@demo.milesoftwares.com', role: 'pharmacist' },
  { id: 'u-manager', name: 'Omar Manager', email: 'manager@demo.milesoftwares.com', role: 'manager' },
];

export const DEMO_ACCOUNTS = DEMO_USERS.map(({ id, name, role }) => ({ id, name, role }));

const ORGANIZATION = { id: 'org-demo', slug: 'demo', name: 'Demo Pharmacy', currency: 'SSP', decimals: 2 };

const BRANCHES: Branch[] = [
  { id: 'b-main', name: 'Juba Main', code: 'JUB', city: 'Juba' },
  { id: 'b-munuki', name: 'Munuki', code: 'MNK', city: 'Juba' },
];

const MAX_DISCOUNT = 10;
const ABOVE_MINOR = 5_000_000;

const PAYMENT_LABELS: Record<PaymentMethod, string> = {
  cash: 'Cash',
  mobile_money: 'Mobile money',
  card: 'Card',
  bank_transfer: 'Bank transfer',
  other: 'Other',
};

const days = (n: number) => new Date(Date.now() + n * 86_400_000).toISOString().slice(0, 10);
const todayIso = () => days(0);

interface Batch {
  id: string;
  number: string;
  quantity: number;
  expires_on: string;
}

type CatalogItem = Omit<Item, 'available' | 'nearest_expiry'>;

function item(
  id: string,
  name: string,
  generic: string,
  strength: string,
  form: string,
  barcode: string,
  priceMinor: number,
  extra: Partial<CatalogItem> = {},
): CatalogItem {
  return {
    id,
    name,
    generic_name: generic,
    strength,
    form,
    unit: 'tablet',
    barcode,
    category: 'Medicines',
    price_minor: priceMinor,
    restricted: false,
    controlled: false,
    storage: null,
    packs: [],
    ...extra,
  };
}

const CATALOG: CatalogItem[] = [
  item('i-panadol', 'Panadol Extra', 'Paracetamol + Caffeine', '500mg/65mg', 'Tablet', '6001234500011', 3500, {
    packs: [{ id: 'p-panadol-strip', name: 'Strip', units: 10, price_minor: 32000 }],
  }),
  item('i-amoxil', 'Amoxil', 'Amoxicillin', '500mg', 'Capsule', '6001234500028', 8200, { unit: 'capsule', restricted: true }),
  item('i-brufen', 'Brufen', 'Ibuprofen', '400mg', 'Tablet', '6001234500042', 2800, {
    packs: [{ id: 'p-brufen-box', name: 'Box', units: 30, price_minor: 78000 }],
  }),
  item('i-tramal', 'Tramal', 'Tramadol', '50mg', 'Capsule', '6001234500059', 9500, { unit: 'capsule', restricted: true, controlled: true }),
  item('i-piriton', 'Piriton', 'Chlorphenamine', '4mg', 'Tablet', '6001234500066', 1200),
  item('i-ventolin', 'Ventolin Inhaler', 'Salbutamol', '100mcg', 'Inhaler', '6001234500073', 120000, {
    unit: 'inhaler',
    restricted: true,
    storage: 'Below 30°C',
  }),
  item('i-ors', 'ORS Sachets', 'Oral Rehydration Salts', '20.5g', 'Powder', '6001234500080', 600, {
    unit: 'sachet',
    packs: [{ id: 'p-ors-box', name: 'Box', units: 20, price_minor: 11000 }],
  }),
  item('i-cetirizine', 'Cetirizine', 'Cetirizine', '10mg', 'Tablet', '6001234500097', 1500),
  item('i-vitc', 'Vitamin C', 'Ascorbic Acid', '1000mg', 'Effervescent', '6001234500103', 54000, { unit: 'tube', category: 'Supplements' }),
  item('i-flagyl', 'Flagyl', 'Metronidazole', '400mg', 'Tablet', '6001234500110', 3000, { restricted: true }),
  item('i-insulin', 'Actrapid', 'Insulin (human)', '100IU/ml', 'Injection', '6001234500127', 450000, {
    unit: 'vial',
    restricted: true,
    storage: 'Refrigerate 2–8°C',
  }),
];

function stockFor(): Record<string, Batch[]> {
  const b = (number: string, quantity: number, expiresIn: number): Batch => ({
    id: crypto.randomUUID(),
    number,
    quantity,
    expires_on: days(expiresIn),
  });
  return {
    'i-panadol': [b('PX-2207', 12, 45), b('PX-2311', 140, 400)],
    'i-amoxil': [b('AM-118', 60, 300)],
    'i-brufen': [b('BR-77', 90, 500)],
    'i-tramal': [b('TR-12', 30, 250)],
    'i-piriton': [b('PI-40', 100, 700)],
    'i-ventolin': [b('VE-9', 6, 20)],
    'i-ors': [b('OR-301', 200, 900)],
    'i-cetirizine': [b('CE-5', 10, -10)],
    'i-vitc': [b('VC-88', 30, 365)],
    'i-flagyl': [],
    'i-insulin': [b('AC-3', 8, 120)],
  };
}

interface MockSale extends Sale {
  branch_id: string;
  seller_id: string;
  till_id: string | null;
  submission_id: string;
  batches: { line_id: string; item_id: string; batch: Batch }[];
}

interface MockTill extends Till {
  branch_id: string;
  user_id: string;
}

interface MockPrescription extends Prescription {
  branch_id: string;
  created_at: number;
}

const stock: Record<string, Record<string, Batch[]>> = Object.fromEntries(BRANCHES.map((b) => [b.id, stockFor()]));
const tills: MockTill[] = [];
/** Cash movements already recorded, by the app's submission id. */
const movedSubmissions = new Set<string>();
const sales: MockSale[] = [];
const prescriptions: MockPrescription[] = [];
let seq = 100;
let userId: string | null = null;
let branchId: string | null = null;

/** The request context of the live API, for the demo: who and where. */
export function setMockContext(user: string | null, branch: string | null) {
  userId = user;
  branchId = branch;
}

const wait = (ms = 200) => new Promise((resolve) => setTimeout(resolve, ms));
const clone = <T>(value: T): T => structuredClone(value);
const invalid = (field: string, message: string) => new ApiError(message, 422, { [field]: [message] });

function me() {
  const user = DEMO_USERS.find((u) => u.id === userId);
  if (!user) throw new ApiError('Sign in again.', 401, {}, false, 'session_required');
  return user;
}

function here() {
  me();
  if (!branchId || !BRANCHES.some((b) => b.id === branchId)) {
    throw new ApiError('Choose a branch to work in.', 409, {}, false, 'branch_required');
  }
  return branchId;
}

function can() {
  const role = me().role;
  const manager = role === 'manager';
  const pharmacist = role !== 'cashier';
  return {
    sell: true,
    view_sales: manager,
    void: manager,
    discount: pharmacist,
    manage_tills: manager,
    view_prescriptions: true,
    record_prescriptions: true,
    verify_prescriptions: pharmacist,
  };
}

function guard(allowed: boolean) {
  if (!allowed) throw new ApiError('You may not do that here.', 403);
}

function sellable(batches: Batch[]) {
  const today = todayIso();
  return batches.filter((b) => b.quantity > 0 && b.expires_on >= today).sort((a, b) => a.expires_on.localeCompare(b.expires_on));
}

function present(catalogItem: CatalogItem): Item {
  const batches = sellable(stock[here()][catalogItem.id] ?? []);
  return {
    ...clone(catalogItem),
    available: batches.reduce((sum, b) => sum + b.quantity, 0),
    nearest_expiry: batches[0]?.expires_on ?? null,
  };
}

function cashLabel(direction: CashMovement['direction'], counterpart: CashMovement['counterpart']) {
  if (counterpart === 'bank') return direction === 'in' ? 'From the bank' : 'Banked';
  if (counterpart === 'expense') return 'Paid out';
  return direction === 'in' ? 'From the safe' : 'To the safe';
}

function openTill() {
  const branch = here();
  return tills.find((t) => t.status === 'open' && t.user_id === me().id && t.branch_id === branch) ?? null;
}

function presentTill(till: MockTill): Till {
  const own = sales.filter((s) => s.till_id === till.id);
  const kept = own.filter((s) => s.status === 'completed');
  const taken: Record<string, number> = {};
  for (const sale of kept) {
    const returned = sale.returns.reduce((sum, r) => sum + r.total_minor, 0);
    taken[sale.payment_method] = (taken[sale.payment_method] ?? 0) + sale.total_minor - returned;
  }
  const cashRefunds = kept
    .filter((s) => s.payment_method === 'cash')
    .reduce((sum, s) => sum + s.returns.reduce((r, x) => r + x.total_minor, 0), 0);
  const sum = (direction: CashMovement['direction']) =>
    till.movements.filter((m) => m.direction === direction).reduce((total, m) => total + m.amount_minor, 0);
  const cashIn = sum('in');
  const cashOut = sum('out');
  const expected = till.opening_float_minor + (taken.cash ?? 0) + cashIn - cashOut;
  const closed = till.status === 'closed';
  const blind = till.blind;

  const { branch_id: _b, user_id: _u, ...rest } = till;
  return clone({
    ...rest,
    expected_cash_minor: blind ? null : expected,
    difference_minor: blind || !closed ? null : (till.counted_cash_minor ?? 0) - expected,
    summary: {
      sales_count: kept.length,
      refunds_count: own.reduce((n, s) => n + s.returns.length, 0) + own.filter((s) => s.status === 'voided').length,
      taken_minor: blind ? null : taken,
      collected_minor: blind ? null : taken,
      cash_refunds_minor: blind ? null : cashRefunds,
      cash_in_minor: cashIn,
      cash_out_minor: cashOut,
    },
  });
}

function presentSale(sale: MockSale): Sale {
  const { branch_id: _b, seller_id, till_id: _t, submission_id: _s, batches: _x, ...rest } = sale;
  const manager = can().void;
  const returnedAll = sale.lines.every((l) => l.returned >= l.quantity);
  return clone({
    ...rest,
    can_void: manager && sale.status === 'completed' && sale.returns.length === 0,
    can_return: manager && sale.status === 'completed' && !returnedAll,
    sold_by: DEMO_USERS.find((u) => u.id === seller_id)?.name ?? '—',
  });
}

function visibleSale(id: string) {
  const branch = here();
  const sale = sales.find((s) => s.id === id && s.branch_id === branch);
  if (!sale || (sale.seller_id !== me().id && !can().view_sales && !can().void)) throw new ApiError('Not found.', 404);
  return sale;
}

/** FEFO: takes units from the earliest-expiring batches. */
function take(branch: string, itemId: string, units: number, name: string) {
  const batches = sellable(stock[branch][itemId] ?? []);
  const available = batches.reduce((sum, b) => sum + b.quantity, 0);
  if (available < units) throw new Error(`Only ${available} of ${name} in stock; ${units} requested.`);
  const parts: { batch: Batch; quantity: number }[] = [];
  let left = units;
  for (const batch of batches) {
    if (left === 0) break;
    const quantity = Math.min(batch.quantity, left);
    batch.quantity -= quantity;
    left -= quantity;
    parts.push({ batch, quantity });
  }
  return parts;
}

interface SellOptions {
  authorized?: string[];
  prescription?: MockPrescription;
}

function sell(payload: SalePayload, options: SellOptions = {}): { sale: Sale; duplicate: boolean } {
  const branch = here();
  const user = me();
  const earlier = sales.find((s) => s.submission_id === payload.submission_id);
  if (earlier) return { sale: presentSale(earlier), duplicate: true };

  const till = openTill();
  if (!till) throw invalid('till', 'Start your shift before taking or giving back money.');

  const maxDiscount = can().discount ? MAX_DISCOUNT : 0;
  const priced = payload.items.map((line, index) => {
    const catalogItem = CATALOG.find((i) => i.id === line.item_id);
    if (!catalogItem) throw invalid(`items.${index}.item_id`, 'This item cannot be sold.');
    if (catalogItem.restricted && !options.authorized?.includes(catalogItem.id)) {
      throw invalid(`items.${index}.item_id`, `${catalogItem.name} needs a verified prescription.`);
    }
    const percent = line.discount_percent ?? 0;
    if (percent > maxDiscount) {
      throw invalid(
        `items.${index}.discount_percent`,
        maxDiscount === 0 ? 'You may not give discounts.' : `You may take off at most ${maxDiscount}%.`,
      );
    }
    const pack = line.pack_id ? catalogItem.packs.find((p) => p.id === line.pack_id) : null;
    if (line.pack_id && !pack) throw invalid(`items.${index}.pack_id`, 'This pack is not sold here.');
    const gross = line.quantity * (pack ? pack.price_minor : catalogItem.price_minor);
    const discount = percentOf(gross, percent);
    return { catalogItem, pack, packs: line.quantity, units: line.quantity * (pack?.units ?? 1), gross, discount, index };
  });

  const total = priced.reduce((sum, l) => sum + l.gross - l.discount, 0);
  const named = Boolean(payload.customer_name?.trim());
  if (!named && !options.prescription && total > ABOVE_MINOR) {
    throw invalid('customer_name', 'This branch records the customer on sales over SSP 50,000.00. Enter their name.');
  }

  let tendered: number | null = null;
  if (payload.payment_method === 'cash') {
    tendered = payload.amount_tendered_minor ?? total;
    if (tendered < total) throw invalid('amount_tendered', 'The cash given is less than the total.');
  }

  // Check every line's stock before taking any.
  for (const line of priced) {
    const available = sellable(stock[branch][line.catalogItem.id] ?? []).reduce((sum, b) => sum + b.quantity, 0);
    if (available < line.units) {
      throw invalid(
        `items.${line.index}.quantity`,
        `Only ${available} of ${line.catalogItem.name} in stock; ${line.units} requested.`,
      );
    }
  }

  const sale: MockSale = {
    id: crypto.randomUUID(),
    number: `S-${String(++seq).padStart(6, '0')}`,
    status: 'completed',
    sold_at: new Date().toISOString(),
    sold_by: user.name,
    customer_name: payload.customer_name?.trim() || options.prescription?.patient_name || null,
    customer_phone: payload.customer_phone?.trim() || null,
    prescription: options.prescription
      ? { id: options.prescription.id, number: options.prescription.number, patient_name: options.prescription.patient_name }
      : null,
    currency: ORGANIZATION.currency,
    total_minor: total,
    discount_minor: priced.reduce((sum, l) => sum + l.discount, 0),
    payment_method: payload.payment_method,
    payment_method_label: PAYMENT_LABELS[payload.payment_method],
    payment_reference: payload.payment_reference?.trim() || null,
    amount_tendered_minor: tendered,
    change_minor: tendered === null ? null : tendered - total,
    voided_at: null,
    voided_by: null,
    void_reason: null,
    receipt_lines: [],
    lines: [],
    returns: [],
    can_void: false,
    can_return: false,
    branch_id: branch,
    seller_id: user.id,
    till_id: till.id,
    submission_id: payload.submission_id,
    batches: [],
  };

  for (const line of priced) {
    const parts = take(branch, line.catalogItem.id, line.units, line.catalogItem.name);
    let discountLeft = line.discount;
    parts.forEach((part, i) => {
      const last = i === parts.length - 1;
      const share = last ? discountLeft : Math.floor((line.discount * part.quantity) / line.units);
      discountLeft -= share;
      const lineId = crypto.randomUUID();
      sale.lines.push({
        id: lineId,
        name: line.catalogItem.name,
        batch_number: part.batch.number,
        expires_on: part.batch.expires_on,
        quantity: part.quantity,
        returned: 0,
        line_total_minor: Math.round((line.gross * part.quantity) / line.units) - share,
      });
      sale.batches.push({ line_id: lineId, item_id: line.catalogItem.id, batch: part.batch });
    });
    sale.receipt_lines.push({
      name: line.catalogItem.name,
      pack: line.pack ? `${line.pack.name} of ${line.pack.units}` : null,
      quantity: line.packs,
      unit_price_minor: line.pack ? line.pack.price_minor : line.catalogItem.price_minor,
      discount_minor: line.discount,
      line_total_minor: line.gross - line.discount,
    });
  }

  sales.unshift(sale);
  return { sale: presentSale(sale), duplicate: false };
}

function presentPrescription(rx: MockPrescription): Prescription {
  const { branch_id, created_at: _c, ...rest } = rx;
  const allowed = can();
  const open = rx.status === 'verified' || rx.status === 'partly_dispensed';
  return clone({
    ...rest,
    expired: rx.expires_on < todayIso(),
    items: rx.items.map((i) => ({
      ...i,
      remaining: i.quantity - i.dispensed,
      available: sellable(stock[branch_id][i.medicine_id] ?? []).reduce((sum, b) => sum + b.quantity, 0),
    })),
    sales: sales
      .filter((s) => s.prescription?.id === rx.id)
      .map((s) => ({ id: s.id, number: s.number, sold_at: s.sold_at, total_minor: s.total_minor, voided: s.status === 'voided' })),
    can: {
      verify: rx.status === 'recorded' && allowed.verify_prescriptions,
      reject: (rx.status === 'recorded' || rx.status === 'verified') && allowed.verify_prescriptions,
      dispense: open && rx.expires_on >= todayIso() && allowed.sell,
    },
  });
}

function findPrescription(id: string) {
  const branch = here();
  const rx = prescriptions.find((p) => p.id === id && p.branch_id === branch);
  if (!rx) throw new ApiError('Not found.', 404);
  return rx;
}

const STATUS_LABELS: Record<PrescriptionStatus, string> = {
  recorded: 'Waiting for a pharmacist',
  verified: 'Verified',
  partly_dispensed: 'Partly dispensed',
  dispensed: 'Dispensed',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
};

function setStatus(rx: MockPrescription, status: PrescriptionStatus) {
  rx.status = status;
  rx.status_label = STATUS_LABELS[status];
}

function seedPrescription() {
  const rx: MockPrescription = {
    id: crypto.randomUUID(),
    number: 'RX-000041',
    patient_name: 'Grace Achol',
    patient_age: 34,
    prescriber_name: 'Dr. Peter Lado',
    prescriber_registration: 'SSMC-2291',
    issued_on: days(-1),
    expires_on: days(29),
    expired: false,
    status: 'verified',
    status_label: STATUS_LABELS.verified,
    notes: null,
    recorded_by: 'Deng Cashier',
    verified_by: 'Amina Pharmacist',
    rejection_reason: null,
    items: [
      { id: crypto.randomUUID(), medicine_id: 'i-amoxil', name: 'Amoxil 500mg', unit: 'capsule', controlled: false, quantity: 21, dispensed: 0, remaining: 21, unit_price_minor: 8200, directions: '1 capsule three times a day for 7 days', available: 0 },
      { id: crypto.randomUUID(), medicine_id: 'i-panadol', name: 'Panadol Extra 500mg/65mg', unit: 'tablet', controlled: false, quantity: 10, dispensed: 0, remaining: 10, unit_price_minor: 3500, directions: '2 tablets when needed, at most 8 a day', available: 0 },
    ],
    sales: [],
    can: { verify: false, reject: false, dispense: false },
    branch_id: 'b-main',
    created_at: Date.now() - 3_600_000,
  };
  prescriptions.push(rx);
}
seedPrescription();

export const mockApi: OnlineApi = {
  async signIn(_workspace, demoUser) {
    await wait(400);
    const user = DEMO_USERS.find((u) => u.id === demoUser);
    if (!user) throw new ApiError('Choose a demo account.', 422);
    userId = user.id;
    return {
      user: { id: user.id, name: user.name, email: user.email },
      organization: { id: ORGANIZATION.id, slug: ORGANIZATION.slug, name: ORGANIZATION.name, currency: ORGANIZATION.currency },
      is_owner: false,
    };
  },

  async signOut() {
    userId = null;
    branchId = null;
  },

  async me(): Promise<Me> {
    await wait();
    const user = me();
    return {
      user: { id: user.id, name: user.name, email: user.email },
      organization: ORGANIZATION,
      is_owner: false,
      branches: user.role === 'manager' ? BRANCHES : BRANCHES.slice(0, 1),
      reason: null,
    };
  },

  async counter(): Promise<Counter> {
    await wait();
    const branch = BRANCHES.find((b) => b.id === here())!;
    const allowed = can();
    const till = openTill();
    return {
      branch: { id: branch.id, name: branch.name, code: branch.code },
      can: allowed,
      max_discount_percent: allowed.discount ? MAX_DISCOUNT : 0,
      counter: {
        workflow: 'direct',
        workflow_label: 'Sell and take payment at the counter',
        sells_direct: true,
        shifts_required: true,
        default_float_minor: 2_000_000,
        blind_close: !allowed.manage_tills,
        manager_closes_shifts: false,
        require_customer_always: false,
        require_customer_for_restricted: true,
        require_customer_above_minor: ABOVE_MINOR,
      },
      payment_methods: (Object.keys(PAYMENT_LABELS) as PaymentMethod[]).map((value) => ({ value, label: PAYMENT_LABELS[value] })),
      receipt: {
        business_name: ORGANIZATION.name,
        address: `${branch.name}, ${branch.city}`,
        contact: '+211 920 000 000',
        header: null,
        footer: 'Thank you. Get well soon.',
        show_customer: true,
        show_staff: true,
        paper_size: '80mm',
      },
      till: till ? { id: till.id, number: till.number } : null,
    };
  },

  async items(search) {
    await wait(150);
    here();
    const q = search.trim().toLowerCase();
    const found = q
      ? CATALOG.filter((i) => [i.name, i.generic_name, i.barcode].some((v) => v?.toLowerCase().includes(q)))
      : CATALOG;
    return found
      .map(present)
      .filter((i) => q || i.available > 0)
      .slice(0, 50);
  },

  async itemByCode(code) {
    await wait(150);
    const found = CATALOG.find((i) => i.barcode === code.trim());
    return found ? present(found) : null;
  },

  async till() {
    await wait();
    const till = openTill();
    return till ? presentTill(till) : null;
  },

  async openTill(opening) {
    await wait();
    const branch = here();
    if (openTill()) throw invalid('opening_float', 'Your shift is already open here.');
    if (opening < 0) throw invalid('opening_float', 'The float cannot be negative.');
    const till: MockTill = {
      id: crypto.randomUUID(),
      number: `SH-${String(++seq).padStart(5, '0')}`,
      status: 'open',
      opened_at: new Date().toISOString(),
      closed_at: null,
      opening_float_minor: opening,
      counted_cash_minor: null,
      expected_cash_minor: null,
      difference_minor: null,
      closing_note: null,
      blind: !can().manage_tills,
      summary: { sales_count: 0, refunds_count: 0, taken_minor: null, collected_minor: null, cash_refunds_minor: null, cash_in_minor: 0, cash_out_minor: 0 },
      movements: [],
      branch_id: branch,
      user_id: me().id,
    };
    tills.push(till);
    return presentTill(till);
  },

  async moveCash(direction, counterpart, amount, reason, submissionId) {
    await wait();
    const till = openTill();
    if (!till) throw invalid('amount', 'Your shift is not open.');
    if (!movedSubmissions.has(submissionId)) {
      if (amount <= 0) throw invalid('amount_minor', 'Enter an amount above zero.');
      if (!reason.trim()) throw invalid('reason', 'Say why the cash goes in or comes out.');
      if (counterpart === 'expense' && direction === 'in') throw invalid('counterpart', 'Cash paid for an expense only comes out of the drawer.');
      const expected = presentTill({ ...till, blind: false }).expected_cash_minor ?? 0;
      if (direction === 'out' && amount > expected) throw invalid('amount', 'That is more cash than the drawer holds.');
      till.movements.push({
        id: crypto.randomUUID(),
        direction,
        counterpart,
        label: cashLabel(direction, counterpart),
        amount_minor: amount,
        reason: reason.trim(),
        recorded_by: me().name,
        recorded_at: new Date().toISOString(),
      });
      movedSubmissions.add(submissionId);
    }
    return presentTill(till);
  },

  async closeTill(counted, note) {
    await wait();
    const till = openTill();
    if (!till) throw invalid('counted_cash', 'Your shift is not open.');
    if (counted < 0) throw invalid('counted_cash', 'The count cannot be negative.');
    const expected = presentTill({ ...till, blind: false }).expected_cash_minor ?? 0;
    if (counted !== expected && !note?.trim() && !till.blind) {
      throw invalid('closing_note', 'Say why the count differs from what the drawer should hold.');
    }
    till.status = 'closed';
    till.closed_at = new Date().toISOString();
    till.counted_cash_minor = counted;
    till.closing_note = note?.trim() || null;
    return presentTill(till);
  },

  async sell(payload) {
    await wait(500);
    here();
    try {
      return sell(payload);
    } catch (error) {
      if (error instanceof ApiError) throw error;
      throw invalid('items', (error as Error).message);
    }
  },

  async sales(day) {
    await wait();
    const branch = here();
    const everyone = can().view_sales;
    const list: SaleSummary[] = sales
      .filter((s) => s.branch_id === branch && s.sold_at.slice(0, 10) === day && (everyone || s.seller_id === me().id))
      .map((s) => ({
        id: s.id,
        number: s.number,
        status: s.status,
        sold_at: s.sold_at,
        sold_by: s.sold_by,
        customer_name: s.customer_name,
        prescription_number: s.prescription?.number ?? null,
        payment_method: s.payment_method,
        payment_method_label: s.payment_method_label,
        total_minor: s.total_minor,
        returned: s.returns.length > 0,
      }));
    return { everyone, sales: list };
  },

  async sale(id) {
    await wait();
    return presentSale(visibleSale(id));
  },

  async printed(id) {
    await wait(100);
    const sale = visibleSale(id) as MockSale & { copies?: number };
    sale.copies = (sale.copies ?? 0) + 1;
    return { copy: sale.copies };
  },

  async voidSale(id, reason) {
    await wait();
    guard(can().void);
    const sale = visibleSale(id);
    if (sale.status !== 'completed' || sale.returns.length > 0) throw invalid('reason', 'This sale cannot be voided.');
    if (!reason.trim()) throw invalid('reason', 'Say why the sale is voided.');
    for (const { line_id, batch } of sale.batches) {
      batch.quantity += sale.lines.find((l) => l.id === line_id)?.quantity ?? 0;
    }
    sale.status = 'voided';
    sale.voided_at = new Date().toISOString();
    sale.voided_by = me().name;
    sale.void_reason = reason.trim();
    return presentSale(sale);
  },

  async returnSale(id, payload) {
    await wait();
    guard(can().void);
    const sale = visibleSale(id);
    if (sale.status !== 'completed') throw invalid('lines', 'Only a completed sale takes returns.');
    if (!payload.reason.trim()) throw invalid('reason', 'Say why it comes back.');
    let total = 0;
    payload.lines.forEach((wanted, index) => {
      const line = sale.lines.find((l) => l.id === wanted.sale_line_id);
      if (!line) throw invalid(`lines.${index}.sale_line_id`, 'Not a line of this sale.');
      if (wanted.quantity > line.quantity - line.returned) {
        throw invalid(`lines.${index}.quantity`, `At most ${line.quantity - line.returned} of ${line.name} can come back.`);
      }
    });
    for (const wanted of payload.lines) {
      if (wanted.quantity === 0) continue;
      const line = sale.lines.find((l) => l.id === wanted.sale_line_id)!;
      total += Math.floor((line.line_total_minor * wanted.quantity) / line.quantity);
      line.returned += wanted.quantity;
      if (wanted.restock) sale.batches.find((b) => b.line_id === line.id)!.batch.quantity += wanted.quantity;
    }
    if (total === 0) throw invalid('lines', 'Choose what comes back.');
    const number = `R-${String(++seq).padStart(6, '0')}`;
    sale.returns.push({ id: crypto.randomUUID(), number, returned_at: new Date().toISOString(), reason: payload.reason.trim(), total_minor: total });
    return { number, total_minor: total, sale: presentSale(sale) };
  },

  async prescriptions(status, search) {
    await wait();
    const branch = here();
    const q = search.trim().toLowerCase();
    const open: PrescriptionStatus[] = ['recorded', 'verified', 'partly_dispensed'];
    return prescriptions
      .filter((rx) => rx.branch_id === branch && (status ? rx.status === status : open.includes(rx.status)))
      .filter((rx) => !q || [rx.number, rx.patient_name, rx.prescriber_name].some((v) => v.toLowerCase().includes(q)))
      .sort((a, b) => b.created_at - a.created_at)
      .map((rx) => ({
        id: rx.id,
        number: rx.number,
        patient_name: rx.patient_name,
        prescriber_name: rx.prescriber_name,
        issued_on: rx.issued_on,
        expired: rx.expires_on < todayIso(),
        status: rx.status,
        status_label: rx.status_label,
        items_count: rx.items.length,
      }));
  },

  async prescription(id) {
    await wait();
    return presentPrescription(findPrescription(id));
  },

  async recordPrescription(payload) {
    await wait();
    const branch = here();
    guard(can().record_prescriptions);
    if (!payload.patient_name.trim()) throw invalid('patient_name', 'Enter the patient’s name.');
    if (!payload.prescriber_name.trim()) throw invalid('prescriber_name', 'Enter the prescriber’s name.');
    if (payload.items.length === 0) throw invalid('items', 'Add the medicines prescribed.');
    const issued = new Date(`${payload.issued_on}T00:00:00`);
    const expires = new Date(issued.getTime() + 30 * 86_400_000).toISOString().slice(0, 10);
    const rx: MockPrescription = {
      id: crypto.randomUUID(),
      number: `RX-${String(++seq).padStart(6, '0')}`,
      patient_name: payload.patient_name.trim(),
      patient_age: payload.patient_age ?? null,
      prescriber_name: payload.prescriber_name.trim(),
      prescriber_registration: payload.prescriber_registration?.trim() || null,
      issued_on: payload.issued_on,
      expires_on: expires,
      expired: false,
      status: 'recorded',
      status_label: STATUS_LABELS.recorded,
      notes: payload.notes?.trim() || null,
      recorded_by: me().name,
      verified_by: null,
      rejection_reason: null,
      items: payload.items.map((line, index) => {
        const medicine = CATALOG.find((i) => i.id === line.medicine_id);
        if (!medicine) throw invalid(`items.${index}.medicine_id`, 'Not a medicine.');
        if (!line.directions.trim()) throw invalid(`items.${index}.directions`, 'Enter the directions.');
        return {
          id: crypto.randomUUID(),
          medicine_id: medicine.id,
          name: `${medicine.name} ${medicine.strength ?? ''}`.trim(),
          unit: medicine.unit,
          controlled: medicine.controlled,
          quantity: line.quantity,
          dispensed: 0,
          remaining: line.quantity,
          unit_price_minor: medicine.price_minor,
          directions: line.directions.trim(),
          available: 0,
        };
      }),
      sales: [],
      can: { verify: false, reject: false, dispense: false },
      branch_id: branch,
      created_at: Date.now(),
    };
    prescriptions.push(rx);
    return presentPrescription(rx);
  },

  async verifyPrescription(id) {
    await wait();
    guard(can().verify_prescriptions);
    const rx = findPrescription(id);
    if (rx.status !== 'recorded') throw invalid('status', 'Only a prescription waiting for a pharmacist is verified.');
    setStatus(rx, 'verified');
    rx.verified_by = me().name;
    return presentPrescription(rx);
  },

  async rejectPrescription(id, reason) {
    await wait();
    guard(can().verify_prescriptions);
    const rx = findPrescription(id);
    if (rx.status !== 'recorded' && rx.status !== 'verified') throw invalid('status', 'This prescription cannot be rejected now.');
    if (!reason.trim()) throw invalid('reason', 'Say why it is rejected.');
    setStatus(rx, 'rejected');
    rx.rejection_reason = reason.trim();
    return presentPrescription(rx);
  },

  async dispense(id, payload) {
    await wait(500);
    const rx = findPrescription(id);
    const earlier = sales.find((s) => s.submission_id === payload.submission_id);
    if (earlier) {
      return {
        sale: { id: earlier.id, number: earlier.number, total_minor: earlier.total_minor, change_minor: earlier.change_minor },
        duplicate: true,
        prescription: presentPrescription(rx),
      };
    }
    if (!presentPrescription(rx).can.dispense) throw invalid('status', 'This prescription cannot be dispensed now.');

    const lines = rx.items
      .map((item) => ({ item, quantity: payload.quantities ? (payload.quantities[item.id] ?? 0) : item.quantity - item.dispensed }))
      .filter((l) => l.quantity > 0);
    if (lines.length === 0) throw invalid('quantities', 'Choose what to give now.');
    for (const line of lines) {
      if (line.quantity > line.item.quantity - line.item.dispensed) {
        throw invalid(`quantities.${line.item.id}`, `At most ${line.item.quantity - line.item.dispensed} of ${line.item.name} is left.`);
      }
    }

    let result: { sale: Sale; duplicate: boolean };
    try {
      result = sell(
        {
          items: lines.map((l) => ({ item_id: l.item.medicine_id, quantity: l.quantity })),
          submission_id: payload.submission_id,
          payment_method: payload.payment_method,
          payment_reference: payload.payment_reference,
          amount_tendered_minor: payload.amount_tendered_minor,
          customer_name: rx.patient_name,
        },
        { authorized: lines.map((l) => l.item.medicine_id), prescription: rx },
      );
    } catch (error) {
      if (error instanceof ApiError) throw error;
      throw invalid('status', (error as Error).message);
    }
    for (const line of lines) line.item.dispensed += line.quantity;
    setStatus(rx, rx.items.every((i) => i.dispensed >= i.quantity) ? 'dispensed' : 'partly_dispensed');
    const { sale } = result;
    return {
      sale: { id: sale.id, number: sale.number, total_minor: sale.total_minor, change_minor: sale.change_minor },
      duplicate: false,
      prescription: presentPrescription(rx),
    };
  },
};
