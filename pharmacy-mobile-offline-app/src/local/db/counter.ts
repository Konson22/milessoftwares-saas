import type {
  CashCounterpart,
  CashDirection,
  CashMovement,
  Counter,
  DispensePayload,
  Item,
  PaymentMethod,
  Prescription,
  PrescriptionPayload,
  PrescriptionStatus,
  PrescriptionSummary,
  ReturnPayload,
  Sale,
  SalePayload,
  SaleSummary,
  Till,
} from '@/lib/types';
import { lineGross, type PriceTier } from '@/lib/pricing';
import { availableUnits, itemByCode, medicineLabel, priceTiers, searchItems, sellableBatches, type MedicineRow } from './catalog';
import { all, nextNumber, one, run, transaction } from './database';
import { insertExpense, tillCategories, tillCategory } from './expenses';
import { currentUser, hasPermission, type UserRow } from './session';
import { branch, formatMoney, PAYMENT_LABELS, settings } from './settings';
import { addDays, guard, int, invalid, isDay, notFound, now, percentOf, text, today, uuid, Validation } from './util';

/**
 * The counter's rules: shifts, selling soonest-to-expire stock first,
 * prescription-only medicines dispensed from a verified prescription,
 * discounts, voids and returns. Each change is one transaction.
 */

export function can(user: UserRow): Counter['can'] {
  const has = (p: string) => hasPermission(user, p);
  return {
    sell: has('pharmacy.sales.create'),
    view_sales: has('pharmacy.sales.view'),
    void: has('pharmacy.sales.void'),
    discount: has('pharmacy.sales.discount'),
    manage_tills: has('pharmacy.tills.manage'),
    view_prescriptions: has('pharmacy.prescriptions.view'),
    record_prescriptions: has('pharmacy.prescriptions.record'),
    verify_prescriptions: has('pharmacy.prescriptions.verify'),
  };
}

export function counter(): Counter {
  const user = currentUser();
  const s = settings();
  const allowed = can(user);
  const till = openTillOf(user.id);
  const here = branch();
  return {
    branch: { id: here.id, name: here.name, code: here.code },
    can: allowed,
    max_discount_percent: allowed.discount ? s.max_discount_percent : 0,
    counter: {
      shifts_required: s.shifts_required,
      default_float_minor: s.default_float_minor,
      blind_close: s.blind_close && !allowed.manage_tills,
      require_customer_always: s.require_customer_always,
      require_customer_for_restricted: true,
      require_customer_above_minor: s.require_customer_above_minor,
    },
    payment_methods: s.payment_methods.map((value) => ({ value, label: PAYMENT_LABELS[value] })),
    receipt: {
      business_name: s.name,
      address: s.address,
      contact: s.contact,
      header: s.receipt_header,
      footer: s.receipt_footer,
      show_customer: s.show_customer,
      show_staff: s.show_staff,
      paper_size: s.paper_size,
    },
    till: till ? { id: till.id, number: till.number } : null,
  };
}

export function items(search: unknown): Item[] {
  currentUser();
  return searchItems(typeof search === 'string' ? search : '');
}

export function findByCode(code: unknown): Item | null {
  currentUser();
  return typeof code === 'string' ? itemByCode(code) : null;
}

// Shifts ---------------------------------------------------------------------

interface TillRow {
  id: string;
  number: string;
  user_id: string;
  status: 'open' | 'closed';
  opened_at: string;
  closed_at: string | null;
  opening_float_minor: number;
  counted_cash_minor: number | null;
  closing_note: string | null;
  blind: number;
}

const openTillOf = (userId: string) => one<TillRow>("SELECT * FROM tills WHERE user_id = ? AND status = 'open'", userId);

function cashLabel(direction: CashDirection, counterpart: CashCounterpart) {
  if (counterpart === 'bank') return direction === 'in' ? 'From the bank' : 'Banked';
  if (counterpart === 'expense') return 'Paid out';
  return direction === 'in' ? 'From the safe' : 'To the safe';
}

/** What the drawer took and should hold. Refunds count against the sale's own shift. */
export function tillFigures(tillId: string, openingFloat: number) {
  const sold = all<{ payment_method: string; total_minor: number; status: string; returned: number; returns: number }>(
    `SELECT s.payment_method, s.total_minor, s.status,
       COALESCE((SELECT SUM(r.total_minor) FROM sale_returns r WHERE r.sale_id = s.id), 0) AS returned,
       (SELECT COUNT(*) FROM sale_returns r WHERE r.sale_id = s.id) AS returns
     FROM sales s WHERE s.till_id = ?`,
    tillId,
  );
  const kept = sold.filter((s) => s.status === 'completed');
  const taken: Record<string, number> = {};
  for (const sale of kept) taken[sale.payment_method] = (taken[sale.payment_method] ?? 0) + sale.total_minor - sale.returned;
  const cashRefunds = kept.filter((s) => s.payment_method === 'cash').reduce((sum, s) => sum + s.returned, 0);

  const movements = all<{ id: string; direction: CashDirection; counterpart: CashCounterpart; amount_minor: number; reason: string; at: string; by: string; category: string | null; expense_number: string | null }>(
    `SELECT c.*, u.name AS by, ec.name AS category, e.number AS expense_number
     FROM cash_movements c JOIN users u ON u.id = c.user_id
     LEFT JOIN expenses e ON e.cash_movement_id = c.id LEFT JOIN expense_categories ec ON ec.id = e.category_id
     WHERE c.till_id = ? ORDER BY c.at DESC`,
    tillId,
  );
  const sum = (direction: CashDirection) => movements.filter((m) => m.direction === direction).reduce((total, m) => total + m.amount_minor, 0);
  const cashIn = sum('in');
  const cashOut = sum('out');

  return {
    taken,
    cashRefunds,
    cashIn,
    cashOut,
    salesCount: kept.length,
    refundsCount: sold.reduce((n, s) => n + s.returns, 0) + sold.filter((s) => s.status === 'voided').length,
    expected: openingFloat + (taken.cash ?? 0) + cashIn - cashOut,
    movements: movements.map(
      (m): CashMovement => ({
        id: m.id,
        direction: m.direction,
        counterpart: m.counterpart,
        label: m.category ? `Paid out: ${m.category} (${m.expense_number})` : cashLabel(m.direction, m.counterpart),
        amount_minor: m.amount_minor,
        reason: m.reason,
        recorded_by: m.by,
        recorded_at: m.at,
      }),
    ),
  };
}

function presentTill(till: TillRow): Till {
  const f = tillFigures(till.id, till.opening_float_minor);
  const blind = Boolean(till.blind);
  const closed = till.status === 'closed';
  return {
    id: till.id,
    number: till.number,
    status: till.status,
    opened_at: till.opened_at,
    closed_at: till.closed_at,
    opening_float_minor: till.opening_float_minor,
    counted_cash_minor: till.counted_cash_minor,
    expected_cash_minor: blind ? null : f.expected,
    difference_minor: blind || !closed ? null : (till.counted_cash_minor ?? 0) - f.expected,
    closing_note: till.closing_note,
    blind,
    summary: {
      sales_count: f.salesCount,
      refunds_count: f.refundsCount,
      taken_minor: blind ? null : f.taken,
      collected_minor: blind ? null : f.taken,
      cash_refunds_minor: blind ? null : f.cashRefunds,
      cash_in_minor: f.cashIn,
      cash_out_minor: f.cashOut,
    },
    movements: f.movements,
  };
}

export function till(): Till | null {
  const found = openTillOf(currentUser().id);
  return found ? presentTill(found) : null;
}

export function openTill(opening: unknown): Till {
  const user = currentUser();
  guard(can(user).sell);
  const float = int(opening);
  if (float === null || float < 0) throw invalid('opening_float', 'The float cannot be negative.');
  return transaction(() => {
    if (openTillOf(user.id)) throw invalid('opening_float', 'Your shift is already open.');
    const id = uuid();
    const blind = settings().blind_close && !can(user).manage_tills;
    run(
      'INSERT INTO tills (id, number, user_id, status, opened_at, opening_float_minor, blind) VALUES (?, ?, ?, ?, ?, ?, ?)',
      id,
      nextNumber('till', 'SH', 5),
      user.id,
      'open',
      now(),
      float,
      blind ? 1 : 0,
    );
    return presentTill(one<TillRow>('SELECT * FROM tills WHERE id = ?', id)!);
  });
}

/** Expense categories the drawer may pay out for. */
export function expenseCategories(): { id: string; name: string }[] {
  currentUser();
  return tillCategories();
}

/** Cash into or out of the drawer. Paid out for an expense, it is recorded under its category, against this shift. */
export function moveCash(direction: unknown, counterpart: unknown, amount: unknown, reason: unknown, submissionId: unknown, categoryId?: unknown): Till {
  const user = currentUser();
  return transaction(() => {
    const open = openTillOf(user.id);
    if (!open) throw invalid('amount', 'Your shift is not open.');
    const id = text(submissionId);
    if (!id) throw invalid('submission_id', 'Missing submission id.');
    // The same submission is recorded once.
    if (!one('SELECT id FROM cash_movements WHERE submission_id = ?', id)) {
      const minor = int(amount);
      const why = text(reason);
      if (direction !== 'in' && direction !== 'out') throw invalid('direction', 'Into the drawer or out of it.');
      if (counterpart !== 'safe' && counterpart !== 'bank' && counterpart !== 'expense') throw invalid('counterpart', 'Choose where the cash comes from or goes.');
      if (!minor || minor <= 0) throw invalid('amount_minor', 'Enter an amount above zero.');
      if (!why) throw invalid('reason', 'Say why the cash goes in or comes out.');
      if (counterpart === 'expense' && direction === 'in') throw invalid('counterpart', 'Cash paid for an expense only comes out of the drawer.');
      const category = counterpart === 'expense' ? tillCategory(categoryId) : null;
      if (direction === 'out' && minor > tillFigures(open.id, open.opening_float_minor).expected) {
        throw invalid('amount', 'That is more cash than the drawer holds.');
      }
      const movementId = uuid();
      run(
        'INSERT INTO cash_movements (id, till_id, submission_id, direction, counterpart, amount_minor, reason, user_id, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        movementId,
        open.id,
        id,
        direction,
        counterpart,
        minor,
        why,
        user.id,
        now(),
      );
      if (category) {
        insertExpense(user, {
          category,
          amount: minor,
          spentOn: today(),
          paidFrom: 'till',
          tillId: open.id,
          cashMovementId: movementId,
          payee: null,
          reference: null,
          note: why,
        });
      }
    }
    return presentTill(open);
  });
}

export function closeTill(counted: unknown, note: unknown): Till {
  const user = currentUser();
  return transaction(() => {
    const open = openTillOf(user.id);
    if (!open) throw invalid('counted_cash', 'Your shift is not open.');
    const minor = int(counted);
    if (minor === null || minor < 0) throw invalid('counted_cash', 'The count cannot be negative.');
    const why = text(note);
    const expected = tillFigures(open.id, open.opening_float_minor).expected;
    if (minor !== expected && !why && !open.blind) throw invalid('closing_note', 'Say why the count differs from what the drawer should hold.');
    run("UPDATE tills SET status = 'closed', closed_at = ?, counted_cash_minor = ?, closing_note = ? WHERE id = ?", now(), minor, why, open.id);
    return presentTill(one<TillRow>('SELECT * FROM tills WHERE id = ?', open.id)!);
  });
}

// Sales ----------------------------------------------------------------------

interface SaleRow {
  id: string;
  number: string;
  submission_id: string;
  status: 'completed' | 'voided';
  sold_at: string;
  user_id: string;
  till_id: string | null;
  customer_name: string | null;
  customer_phone: string | null;
  prescription_id: string | null;
  total_minor: number;
  discount_minor: number;
  payment_method: PaymentMethod;
  payment_reference: string | null;
  amount_tendered_minor: number | null;
  change_minor: number | null;
  voided_at: string | null;
  voided_by: string | null;
  void_reason: string | null;
  copies: number;
}

interface SellOptions {
  /** Prescription-only medicines this sale may include (dispensing). */
  authorized?: string[];
  prescription?: { id: string; patient_name: string };
}

function sellNow(user: UserRow, payload: SalePayload, options: SellOptions = {}): { sale: Sale; duplicate: boolean } {
  const submission = text(payload?.submission_id);
  if (!submission) throw invalid('submission_id', 'Missing submission id.');
  const earlier = one<{ id: string }>('SELECT id FROM sales WHERE submission_id = ?', submission);
  if (earlier) return { sale: presentSale(earlier.id, user), duplicate: true };

  const s = settings();
  const allowed = can(user);
  guard(allowed.sell);
  const till = openTillOf(user.id);
  if (s.shifts_required && !till) throw invalid('till', 'Open your shift before selling.');
  if (!Array.isArray(payload.items) || payload.items.length === 0) throw invalid('items', 'The cart is empty.');
  if (!s.payment_methods.includes(payload.payment_method)) throw invalid('payment_method', 'This way of paying is not taken here.');

  const maxDiscount = allowed.discount ? s.max_discount_percent : 0;
  // Each medicine's shelf as it is before this sale; a second line of the
  // same medicine prices the units after the first's.
  const shelves = new Map<string, { tiers: PriceTier[]; taken: number }>();
  const priced = payload.items.map((line, index) => {
    const medicine = one<MedicineRow>('SELECT * FROM medicines WHERE id = ? AND active = 1', line.item_id);
    if (!medicine) throw invalid(`items.${index}.item_id`, 'This item cannot be sold.');
    const quantity = int(line.quantity);
    if (!quantity || quantity < 1) throw invalid(`items.${index}.quantity`, 'Enter a quantity of at least one.');
    if ((medicine.prescription_only || medicine.controlled) && !options.authorized?.includes(medicine.id)) {
      throw invalid(`items.${index}.item_id`, `${medicine.name} needs a verified prescription.`);
    }
    const percent = Number(line.discount_percent ?? 0);
    if (!Number.isFinite(percent) || percent < 0 || percent > 100) throw invalid(`items.${index}.discount_percent`, 'A discount is from 0 to 100%.');
    if (percent > maxDiscount) {
      throw invalid(`items.${index}.discount_percent`, maxDiscount === 0 ? 'You may not give discounts.' : `You may take off at most ${maxDiscount}%.`);
    }
    const pack = line.pack_id ? one<{ id: string; name: string; units: number; price_minor: number }>('SELECT * FROM packs WHERE id = ? AND medicine_id = ?', line.pack_id, medicine.id) : null;
    if (line.pack_id && !pack) throw invalid(`items.${index}.pack_id`, 'This pack is not sold here.');
    const units = quantity * (pack?.units ?? 1);
    let shelf = shelves.get(medicine.id);
    if (!shelf) shelves.set(medicine.id, (shelf = { tiers: priceTiers(medicine.id, medicine.price_minor), taken: 0 }));
    const skip = shelf.taken;
    shelf.taken += units;
    const gross = lineGross(shelf.tiers, medicine.price_minor, pack, quantity, skip);
    // A unit's price at each point of the line, so each batch's share is its own price.
    const priceTo = (n: number) => Math.round(lineGross(shelf.tiers, medicine.price_minor, pack ? { units: 1, price_minor: pack.price_minor / pack.units } : null, n, skip));
    return { medicine, pack, packs: quantity, units, gross, priceTo, discount: percentOf(gross, percent), index };
  });

  const total = priced.reduce((sum, l) => sum + l.gross - l.discount, 0);
  const named = Boolean(text(payload.customer_name));
  if (!named && !options.prescription) {
    if (s.require_customer_always) throw invalid('customer_name', 'Enter the customer’s name.');
    if (s.require_customer_above_minor !== null && total > s.require_customer_above_minor) {
      throw invalid('customer_name', `Sales over ${formatMoney(s.require_customer_above_minor)} record the customer. Enter their name.`);
    }
  }

  let tendered: number | null = null;
  if (payload.payment_method === 'cash') {
    tendered = int(payload.amount_tendered_minor) ?? total;
    if (tendered < total) throw invalid('amount_tendered', 'The cash given is less than the total.');
  }

  // Every medicine's stock, counted over all its lines, before taking any.
  const wanted = new Map<string, number>();
  for (const line of priced) wanted.set(line.medicine.id, (wanted.get(line.medicine.id) ?? 0) + line.units);
  for (const line of priced) {
    const available = availableUnits(line.medicine.id);
    const units = wanted.get(line.medicine.id)!;
    if (available < units) throw invalid(`items.${line.index}.quantity`, `Only ${available} of ${line.medicine.name} in stock; ${units} requested.`);
  }

  const id = uuid();
  const number = nextNumber('sale', 'S', 6);
  const at = now();
  run(
    `INSERT INTO sales (id, number, submission_id, status, sold_at, user_id, till_id, customer_name, customer_phone, prescription_id,
      total_minor, discount_minor, payment_method, payment_reference, amount_tendered_minor, change_minor)
     VALUES (?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    id,
    number,
    submission,
    at,
    user.id,
    till?.id ?? null,
    text(payload.customer_name) ?? options.prescription?.patient_name ?? null,
    text(payload.customer_phone),
    options.prescription?.id ?? null,
    total,
    priced.reduce((sum, l) => sum + l.discount, 0),
    payload.payment_method,
    text(payload.payment_reference),
    tendered,
    tendered === null ? null : tendered - total,
  );

  let position = 0;
  priced.forEach((line, itemIndex) => {
    run(
      'INSERT INTO sale_items (id, sale_id, position, medicine_id, name, pack, quantity, unit_price_minor, discount_minor, line_total_minor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      uuid(),
      id,
      itemIndex,
      line.medicine.id,
      medicineLabel(line.medicine),
      line.pack ? `${line.pack.name} of ${line.pack.units}` : null,
      line.packs,
      Math.round(line.gross / line.packs),
      line.discount,
      line.gross - line.discount,
    );

    // FEFO, each batch at its price, the discount shared over them.
    let left = line.units;
    let done = 0;
    let discountLeft = line.discount;
    const parts: { batch: ReturnType<typeof sellableBatches>[number]; quantity: number }[] = [];
    for (const batch of sellableBatches(line.medicine.id)) {
      if (left === 0) break;
      const quantity = Math.min(batch.quantity, left);
      left -= quantity;
      parts.push({ batch, quantity });
    }
    parts.forEach((part, i) => {
      const last = i === parts.length - 1;
      const share = last ? discountLeft : Math.floor((line.discount * part.quantity) / line.units);
      discountLeft -= share;
      const partGross = last ? line.gross - line.priceTo(done) : line.priceTo(done + part.quantity) - line.priceTo(done);
      done += part.quantity;
      run('UPDATE batches SET quantity = quantity - ? WHERE id = ?', part.quantity, part.batch.id);
      run(
        `INSERT INTO sale_lines (id, sale_id, medicine_id, batch_id, name, batch_number, expires_on, quantity, returned, unit_cost_minor, line_total_minor, position)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)`,
        uuid(),
        id,
        line.medicine.id,
        part.batch.id,
        medicineLabel(line.medicine),
        part.batch.batch_number,
        part.batch.expires_on,
        part.quantity,
        part.batch.unit_cost_minor,
        partGross - share,
        position++,
      );
      movement(line.medicine.id, part.batch.id, 'sale', -part.quantity, null, null, number, user.id);
    });
  });

  return { sale: presentSale(id, user), duplicate: false };
}

export function movement(medicineId: string, batchId: string, type: string, quantity: number, reason: string | null, note: string | null, reference: string | null, userId: string) {
  run(
    'INSERT INTO stock_movements (id, medicine_id, batch_id, type, quantity, reason, note, reference, user_id, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    uuid(),
    medicineId,
    batchId,
    type,
    quantity,
    reason,
    note,
    reference,
    userId,
    now(),
  );
}

function presentSale(id: string, viewer: UserRow): Sale {
  const sale = one<SaleRow & { seller: string; voider: string | null; rx_number: string | null; rx_patient: string | null }>(
    `SELECT s.*, u.name AS seller, v.name AS voider, p.number AS rx_number, p.patient_name AS rx_patient
     FROM sales s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN users v ON v.id = s.voided_by
     LEFT JOIN prescriptions p ON p.id = s.prescription_id
     WHERE s.id = ?`,
    id,
  );
  if (!sale) throw notFound();
  const receiptLines = all<{ name: string; pack: string | null; quantity: number; unit_price_minor: number; discount_minor: number; line_total_minor: number }>(
    'SELECT name, pack, quantity, unit_price_minor, discount_minor, line_total_minor FROM sale_items WHERE sale_id = ? ORDER BY position',
    id,
  );
  const lines = all<Sale['lines'][number]>(
    'SELECT id, name, batch_number, expires_on, quantity, returned, line_total_minor FROM sale_lines WHERE sale_id = ? ORDER BY position',
    id,
  );
  const returns = all<Sale['returns'][number]>('SELECT id, number, returned_at, reason, total_minor FROM sale_returns WHERE sale_id = ? ORDER BY returned_at', id);
  const manager = can(viewer).void;
  const returnedAll = lines.every((l) => l.returned >= l.quantity);

  return {
    id: sale.id,
    number: sale.number,
    status: sale.status,
    sold_at: sale.sold_at,
    sold_by: sale.seller,
    customer_name: sale.customer_name,
    customer_phone: sale.customer_phone,
    prescription: sale.prescription_id ? { id: sale.prescription_id, number: sale.rx_number ?? '', patient_name: sale.rx_patient ?? '' } : null,
    currency: settings().currency,
    total_minor: sale.total_minor,
    discount_minor: sale.discount_minor,
    payment_method: sale.payment_method,
    payment_method_label: PAYMENT_LABELS[sale.payment_method] ?? sale.payment_method,
    payment_reference: sale.payment_reference,
    amount_tendered_minor: sale.amount_tendered_minor,
    change_minor: sale.change_minor,
    voided_at: sale.voided_at,
    voided_by: sale.voider,
    void_reason: sale.void_reason,
    receipt_lines: receiptLines,
    lines,
    returns,
    can_void: manager && sale.status === 'completed' && returns.length === 0,
    can_return: manager && sale.status === 'completed' && !returnedAll,
  };
}

function visibleSale(id: unknown, user: UserRow): SaleRow {
  const sale = typeof id === 'string' ? one<SaleRow>('SELECT * FROM sales WHERE id = ?', id) : null;
  const allowed = can(user);
  if (!sale || (sale.user_id !== user.id && !allowed.view_sales && !allowed.void)) throw notFound();
  return sale;
}

export function sell(payload: SalePayload): { sale: Sale; duplicate: boolean } {
  const user = currentUser();
  return transaction(() => sellNow(user, payload));
}

export function sales(day: unknown): { everyone: boolean; sales: SaleSummary[] } {
  const user = currentUser();
  if (!isDay(day)) throw invalid('day', 'Not a day.');
  const everyone = can(user).view_sales;
  const rows = all<SaleRow & { seller: string; rx_number: string | null; returns: number }>(
    `SELECT s.*, u.name AS seller, p.number AS rx_number, (SELECT COUNT(*) FROM sale_returns r WHERE r.sale_id = s.id) AS returns
     FROM sales s JOIN users u ON u.id = s.user_id LEFT JOIN prescriptions p ON p.id = s.prescription_id
     WHERE date(s.sold_at, 'localtime') = ? AND (? = 1 OR s.user_id = ?)
     ORDER BY s.sold_at DESC`,
    day,
    everyone ? 1 : 0,
    user.id,
  );
  return {
    everyone,
    sales: rows.map((s) => ({
      id: s.id,
      number: s.number,
      status: s.status,
      sold_at: s.sold_at,
      sold_by: s.seller,
      customer_name: s.customer_name,
      prescription_number: s.rx_number,
      payment_method: s.payment_method,
      payment_method_label: PAYMENT_LABELS[s.payment_method] ?? s.payment_method,
      total_minor: s.total_minor,
      returned: s.returns > 0,
    })),
  };
}

export function sale(id: unknown): Sale {
  const user = currentUser();
  return presentSale(visibleSale(id, user).id, user);
}

export function printed(id: unknown): { copy: number } {
  const user = currentUser();
  const found = visibleSale(id, user);
  run('UPDATE sales SET copies = copies + 1 WHERE id = ?', found.id);
  return { copy: found.copies + 1 };
}

/** A dispensed prescription takes back what a voided or returned sale gave. */
function undispense(saleId: string, medicineId: string, units: number) {
  const sale = one<{ prescription_id: string | null }>('SELECT prescription_id FROM sales WHERE id = ?', saleId);
  if (!sale?.prescription_id || units <= 0) return;
  const item = one<{ id: string; dispensed: number }>('SELECT id, dispensed FROM prescription_items WHERE prescription_id = ? AND medicine_id = ? AND dispensed > 0 ORDER BY position LIMIT 1', sale.prescription_id, medicineId);
  if (!item) return;
  run('UPDATE prescription_items SET dispensed = ? WHERE id = ?', Math.max(0, item.dispensed - units), item.id);
  refreshPrescriptionStatus(sale.prescription_id);
}

export function voidSale(id: unknown, reason: unknown): Sale {
  const user = currentUser();
  guard(can(user).void);
  return transaction(() => {
    const found = visibleSale(id, user);
    const returns = one<{ n: number }>('SELECT COUNT(*) AS n FROM sale_returns WHERE sale_id = ?', found.id)!.n;
    if (found.status !== 'completed' || returns > 0) throw invalid('reason', 'This sale cannot be voided.');
    const why = text(reason);
    if (!why) throw invalid('reason', 'Say why the sale is voided.');
    for (const line of all<{ medicine_id: string; batch_id: string; quantity: number }>('SELECT medicine_id, batch_id, quantity FROM sale_lines WHERE sale_id = ?', found.id)) {
      run('UPDATE batches SET quantity = quantity + ? WHERE id = ?', line.quantity, line.batch_id);
      movement(line.medicine_id, line.batch_id, 'void', line.quantity, why, null, found.number, user.id);
      undispense(found.id, line.medicine_id, line.quantity);
    }
    run("UPDATE sales SET status = 'voided', voided_at = ?, voided_by = ?, void_reason = ? WHERE id = ?", now(), user.id, why, found.id);
    return presentSale(found.id, user);
  });
}

export function returnSale(id: unknown, payload: ReturnPayload): { number: string; total_minor: number; sale: Sale } {
  const user = currentUser();
  guard(can(user).void);
  return transaction(() => {
    const found = visibleSale(id, user);
    if (found.status !== 'completed') throw invalid('lines', 'Only a completed sale takes returns.');
    const why = text(payload?.reason);
    if (!why) throw invalid('reason', 'Say why it comes back.');
    const wanted = Array.isArray(payload.lines) ? payload.lines : [];
    type Line = { id: string; medicine_id: string; batch_id: string; name: string; quantity: number; returned: number; line_total_minor: number };
    const checked = wanted.map((w, index) => {
      const line = one<Line>('SELECT * FROM sale_lines WHERE id = ? AND sale_id = ?', w.sale_line_id, found.id);
      if (!line) throw invalid(`lines.${index}.sale_line_id`, 'Not a line of this sale.');
      const quantity = int(w.quantity) ?? -1;
      if (quantity < 0 || quantity > line.quantity - line.returned) {
        throw invalid(`lines.${index}.quantity`, `At most ${line.quantity - line.returned} of ${line.name} can come back.`);
      }
      return { line, quantity, restock: Boolean(w.restock) };
    });

    const returnId = uuid();
    const number = nextNumber('return', 'R', 6);
    let total = 0;
    const rows: { line: Line; quantity: number; restock: boolean; amount: number }[] = [];
    for (const { line, quantity, restock } of checked) {
      if (quantity === 0) continue;
      const amount = Math.floor((line.line_total_minor * quantity) / line.quantity);
      total += amount;
      rows.push({ line, quantity, restock, amount });
    }
    if (rows.length === 0) throw invalid('lines', 'Choose what comes back.');

    run('INSERT INTO sale_returns (id, number, sale_id, returned_at, reason, total_minor, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)', returnId, number, found.id, now(), why, total, user.id);
    for (const { line, quantity, restock, amount } of rows) {
      run('INSERT INTO sale_return_lines (id, return_id, sale_line_id, quantity, restock, amount_minor) VALUES (?, ?, ?, ?, ?, ?)', uuid(), returnId, line.id, quantity, restock ? 1 : 0, amount);
      run('UPDATE sale_lines SET returned = returned + ? WHERE id = ?', quantity, line.id);
      if (restock) {
        run('UPDATE batches SET quantity = quantity + ? WHERE id = ?', quantity, line.batch_id);
        movement(line.medicine_id, line.batch_id, 'return', quantity, why, null, number, user.id);
      }
    }
    return { number, total_minor: total, sale: presentSale(found.id, user) };
  });
}

// Prescriptions --------------------------------------------------------------

interface RxRow {
  id: string;
  number: string;
  patient_name: string;
  patient_age: number | null;
  prescriber_name: string;
  prescriber_registration: string | null;
  issued_on: string;
  expires_on: string;
  notes: string | null;
  status: PrescriptionStatus;
  recorded_by: string;
  verified_by: string | null;
  rejection_reason: string | null;
  created_at: string;
}

const STATUS_LABELS: Record<PrescriptionStatus, string> = {
  recorded: 'Waiting for a pharmacist',
  verified: 'Verified',
  partly_dispensed: 'Partly dispensed',
  dispensed: 'Dispensed',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
};

const OPEN_STATUSES: PrescriptionStatus[] = ['recorded', 'verified', 'partly_dispensed'];

function refreshPrescriptionStatus(id: string) {
  const rx = one<RxRow>('SELECT * FROM prescriptions WHERE id = ?', id);
  if (!rx || rx.status === 'rejected' || rx.status === 'cancelled' || rx.status === 'recorded') return;
  const items = all<{ quantity: number; dispensed: number }>('SELECT quantity, dispensed FROM prescription_items WHERE prescription_id = ?', id);
  const status: PrescriptionStatus = items.every((i) => i.dispensed >= i.quantity)
    ? 'dispensed'
    : items.some((i) => i.dispensed > 0)
      ? 'partly_dispensed'
      : 'verified';
  run('UPDATE prescriptions SET status = ? WHERE id = ?', status, id);
}

/** What a prescribed medicine sells for now: its batches' prices, or the price when it was prescribed. */
function pricedNow(medicineId: string, prescribedPrice: number) {
  const listPrice = one<{ price_minor: number }>('SELECT price_minor FROM medicines WHERE id = ?', medicineId)?.price_minor ?? prescribedPrice;
  const tiers = priceTiers(medicineId, listPrice);
  return { unit_price_minor: tiers[0]?.unit_price ?? listPrice, price_tiers: tiers };
}

function presentPrescription(id: string, user: UserRow): Prescription {
  const rx = one<RxRow & { recorder: string; verifier: string | null }>(
    `SELECT p.*, r.name AS recorder, v.name AS verifier FROM prescriptions p
     JOIN users r ON r.id = p.recorded_by LEFT JOIN users v ON v.id = p.verified_by WHERE p.id = ?`,
    id,
  );
  if (!rx) throw notFound();
  const allowed = can(user);
  const open = rx.status === 'verified' || rx.status === 'partly_dispensed';
  const expired = rx.expires_on < today();
  const items = all<{ id: string; medicine_id: string; name: string; unit: string; controlled: number; quantity: number; dispensed: number; unit_price_minor: number; directions: string }>(
    'SELECT * FROM prescription_items WHERE prescription_id = ? ORDER BY position',
    id,
  );
  return {
    id: rx.id,
    number: rx.number,
    patient_name: rx.patient_name,
    patient_age: rx.patient_age,
    prescriber_name: rx.prescriber_name,
    prescriber_registration: rx.prescriber_registration,
    issued_on: rx.issued_on,
    expires_on: rx.expires_on,
    expired,
    status: rx.status,
    status_label: STATUS_LABELS[rx.status],
    notes: rx.notes,
    recorded_by: rx.recorder,
    verified_by: rx.verifier,
    rejection_reason: rx.rejection_reason,
    items: items.map((i) => ({
      id: i.id,
      medicine_id: i.medicine_id,
      name: i.name,
      unit: i.unit,
      controlled: Boolean(i.controlled),
      quantity: i.quantity,
      dispensed: i.dispensed,
      remaining: i.quantity - i.dispensed,
      ...pricedNow(i.medicine_id, i.unit_price_minor),
      directions: i.directions,
      available: availableUnits(i.medicine_id),
    })),
    sales: all<{ id: string; number: string; sold_at: string; total_minor: number; status: string }>(
      'SELECT id, number, sold_at, total_minor, status FROM sales WHERE prescription_id = ? ORDER BY sold_at',
      id,
    ).map((s) => ({ id: s.id, number: s.number, sold_at: s.sold_at, total_minor: s.total_minor, voided: s.status === 'voided' })),
    can: {
      verify: rx.status === 'recorded' && allowed.verify_prescriptions,
      reject: (rx.status === 'recorded' || rx.status === 'verified') && allowed.verify_prescriptions,
      dispense: open && !expired && allowed.sell,
    },
  };
}

function findPrescription(id: unknown, user: UserRow): RxRow {
  guard(can(user).view_prescriptions);
  const rx = typeof id === 'string' ? one<RxRow>('SELECT * FROM prescriptions WHERE id = ?', id) : null;
  if (!rx) throw notFound();
  return rx;
}

export function prescriptions(status: unknown, search: unknown): PrescriptionSummary[] {
  const user = currentUser();
  guard(can(user).view_prescriptions);
  const wanted = typeof status === 'string' && status in STATUS_LABELS ? [status as PrescriptionStatus] : OPEN_STATUSES;
  const q = typeof search === 'string' ? search.trim().toLowerCase() : '';
  const rows = all<RxRow & { items_count: number }>(
    `SELECT p.*, (SELECT COUNT(*) FROM prescription_items i WHERE i.prescription_id = p.id) AS items_count
     FROM prescriptions p WHERE p.status IN (${wanted.map(() => '?').join(', ')}) ORDER BY p.created_at DESC LIMIT 300`,
    ...wanted,
  );
  return rows
    .filter((rx) => !q || [rx.number, rx.patient_name, rx.prescriber_name].some((v) => v.toLowerCase().includes(q)))
    .map((rx) => ({
      id: rx.id,
      number: rx.number,
      patient_name: rx.patient_name,
      prescriber_name: rx.prescriber_name,
      issued_on: rx.issued_on,
      expired: rx.expires_on < today(),
      status: rx.status,
      status_label: STATUS_LABELS[rx.status],
      items_count: rx.items_count,
    }));
}

export function prescription(id: unknown): Prescription {
  const user = currentUser();
  return presentPrescription(findPrescription(id, user).id, user);
}

export function recordPrescription(payload: PrescriptionPayload): Prescription {
  const user = currentUser();
  guard(can(user).record_prescriptions);
  const v = new Validation();
  const patient = text(payload?.patient_name);
  const prescriber = text(payload?.prescriber_name);
  const age = payload.patient_age === null || payload.patient_age === undefined ? null : int(payload.patient_age);
  if (!patient) v.add('patient_name', 'Enter the patient’s name.');
  if (!prescriber) v.add('prescriber_name', 'Enter the prescriber’s name.');
  if (age !== null && (age < 0 || age > 130)) v.add('patient_age', 'Not an age.');
  if (!isDay(payload.issued_on)) v.add('issued_on', 'Enter the day it was written.');
  else if (payload.issued_on > today()) v.add('issued_on', 'It cannot be written in the future.');
  const items = Array.isArray(payload.items) ? payload.items : [];
  if (items.length === 0) v.add('items', 'Add the medicines prescribed.');
  const medicines = items.map((line, index) => {
    const medicine = one<MedicineRow>('SELECT * FROM medicines WHERE id = ? AND active = 1', line.medicine_id);
    if (!medicine) v.add(`items.${index}.medicine_id`, 'Not a medicine.');
    const quantity = int(line.quantity);
    if (!quantity || quantity < 1) v.add(`items.${index}.quantity`, 'At least one.');
    if (!text(line.directions)) v.add(`items.${index}.directions`, 'Enter the directions.');
    return medicine;
  });
  v.check();

  return transaction(() => {
    const id = uuid();
    run(
      `INSERT INTO prescriptions (id, number, patient_name, patient_age, prescriber_name, prescriber_registration, issued_on, expires_on, notes, status, recorded_by, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'recorded', ?, ?)`,
      id,
      nextNumber('prescription', 'RX', 6),
      patient!,
      age,
      prescriber!,
      text(payload.prescriber_registration),
      payload.issued_on,
      addDays(payload.issued_on, settings().prescription_valid_days),
      text(payload.notes),
      user.id,
      now(),
    );
    items.forEach((line, index) => {
      const medicine = medicines[index]!;
      run(
        `INSERT INTO prescription_items (id, prescription_id, medicine_id, name, unit, controlled, quantity, dispensed, unit_price_minor, directions, position)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)`,
        uuid(),
        id,
        medicine.id,
        medicineLabel(medicine),
        medicine.unit,
        medicine.controlled,
        int(line.quantity)!,
        medicine.price_minor,
        text(line.directions)!,
        index,
      );
    });
    return presentPrescription(id, user);
  });
}

export function verifyPrescription(id: unknown): Prescription {
  const user = currentUser();
  guard(can(user).verify_prescriptions);
  const rx = findPrescription(id, user);
  if (rx.status !== 'recorded') throw invalid('status', 'Only a prescription waiting for a pharmacist is verified.');
  run("UPDATE prescriptions SET status = 'verified', verified_by = ? WHERE id = ?", user.id, rx.id);
  return presentPrescription(rx.id, user);
}

export function rejectPrescription(id: unknown, reason: unknown): Prescription {
  const user = currentUser();
  guard(can(user).verify_prescriptions);
  const rx = findPrescription(id, user);
  if (rx.status !== 'recorded' && rx.status !== 'verified') throw invalid('status', 'This prescription cannot be rejected now.');
  const why = text(reason);
  if (!why) throw invalid('reason', 'Say why it is rejected.');
  run("UPDATE prescriptions SET status = 'rejected', rejection_reason = ? WHERE id = ?", why, rx.id);
  return presentPrescription(rx.id, user);
}

export function dispense(id: unknown, payload: DispensePayload) {
  const user = currentUser();
  return transaction(() => {
    const rx = findPrescription(id, user);
    const brief = (s: Sale) => ({ id: s.id, number: s.number, total_minor: s.total_minor, change_minor: s.change_minor });

    const earlier = one<{ id: string }>('SELECT id FROM sales WHERE submission_id = ?', text(payload?.submission_id) ?? '');
    if (earlier) return { sale: brief(presentSale(earlier.id, user)), duplicate: true, prescription: presentPrescription(rx.id, user) };
    if (!presentPrescription(rx.id, user).can.dispense) throw invalid('status', 'This prescription cannot be dispensed now.');

    const items = all<{ id: string; medicine_id: string; name: string; quantity: number; dispensed: number }>(
      'SELECT id, medicine_id, name, quantity, dispensed FROM prescription_items WHERE prescription_id = ? ORDER BY position',
      rx.id,
    );
    const lines = items
      .map((item) => ({ item, quantity: payload.quantities ? (int(payload.quantities[item.id]) ?? 0) : item.quantity - item.dispensed }))
      .filter((l) => l.quantity > 0);
    if (lines.length === 0) throw invalid('quantities', 'Choose what to give now.');
    for (const line of lines) {
      if (line.quantity > line.item.quantity - line.item.dispensed) {
        throw invalid(`quantities.${line.item.id}`, `At most ${line.item.quantity - line.item.dispensed} of ${line.item.name} is left.`);
      }
    }

    const { sale: sold } = sellNow(
      user,
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
    for (const line of lines) run('UPDATE prescription_items SET dispensed = dispensed + ? WHERE id = ?', line.quantity, line.item.id);
    refreshPrescriptionStatus(rx.id);
    return { sale: brief(sold), duplicate: false, prescription: presentPrescription(rx.id, user) };
  });
}
