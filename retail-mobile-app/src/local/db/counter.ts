import type {
  CashCounterpart,
  CashDirection,
  CashMovement,
  Counter,
  CustomerOption,
  Item,
  ReturnPayload,
  Sale,
  SaleMethod,
  SalePayload,
  SaleSummary,
  Till,
} from '@/lib/types';
import { lineGross, type PriceTier } from '@/lib/pricing';
import { balanceOf, checkCredit, customerOption, type CustomerRow } from './accounts';
import { availableUnits, contains, itemByCode, priceTiers, searchItems, sellableBatches, type ProductRecord } from './catalog';
import { all, nextNumber, one, run, transaction } from './database';
import { insertExpense, tillCategories, tillCategory } from './expenses';
import { currentUser, hasPermission, type UserRow } from './session';
import { branch, formatMoney, methodLabel, PAYMENT_LABELS, settings } from './settings';
import { addDays, guard, int, invalid, isDay, notFound, now, percentOf, text, today, uuid } from './util';

/**
 * The counter's rules: shifts, selling the lot that expires first (then the
 * oldest), discounts, voids and returns. Each change is one transaction.
 */

export function can(user: UserRow): Counter['can'] {
  const has = (p: string) => hasPermission(user, p);
  return {
    sell: has('retail.sales.create'),
    view_sales: has('retail.sales.view'),
    void: has('retail.sales.void'),
    discount: has('retail.sales.discount'),
    manage_tills: has('retail.tills.manage'),
    sell_on_account: has('retail.sales.credit'),
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

  // Customers paying toward their accounts during the shift.
  const received: Record<string, number> = {};
  for (const p of all<{ method: string; total: number }>(
    'SELECT method, SUM(amount_minor) AS total FROM customer_payments WHERE till_id = ? AND voided_at IS NULL GROUP BY method',
    tillId,
  )) {
    received[p.method] = p.total;
  }

  return {
    taken,
    received,
    cashRefunds,
    cashIn,
    cashOut,
    salesCount: kept.length,
    refundsCount: sold.reduce((n, s) => n + s.returns, 0) + sold.filter((s) => s.status === 'voided').length,
    expected: openingFloat + (taken.cash ?? 0) + (received.cash ?? 0) + cashIn - cashOut,
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
      received_minor: blind ? null : f.received,
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
  total_minor: number;
  discount_minor: number;
  payment_method: SaleMethod;
  payment_reference: string | null;
  amount_tendered_minor: number | null;
  change_minor: number | null;
  voided_at: string | null;
  voided_by: string | null;
  void_reason: string | null;
  copies: number;
  customer_id: string | null;
  due_on: string | null;
  quotation_id: string | null;
}

/** A line of a sale, priced: its units leave the shelf at `priceTo`, the discount shared over them. */
export interface PricedLine {
  product: ProductRecord;
  /** The pack it sold by, as it was then. */
  pack: { name: string; units: number } | null;
  /** So many packs (or units). */
  packs: number;
  units: number;
  gross: number;
  /** What the first n units of the line come to, before the discount. */
  priceTo: (n: number) => number;
  discount: number;
  /** Its place in what was asked, for errors. */
  index: number;
}

export interface SaleHeader {
  submission: string;
  tillId: string | null;
  method: SaleMethod;
  reference: string | null;
  tendered: number | null;
  /** Required on account. */
  customer: CustomerRow | null;
  customerName: string | null;
  customerPhone: string | null;
  quotationId: string | null;
  /** The field a credit error is shown on. */
  creditField: string;
}

/**
 * Takes the stock and records the sale, in the caller's transaction. On
 * account it is an invoice: numbered as one, due after the shop's terms,
 * and within the customer's credit limit.
 */
export function recordSale(user: UserRow, header: SaleHeader, priced: PricedLine[]): string {
  const total = priced.reduce((sum, l) => sum + l.gross - l.discount, 0);
  const onAccount = header.method === 'account';
  if (onAccount) {
    if (!header.customer) throw invalid(header.creditField, 'Choose the customer whose account it goes on.');
    checkCredit(header.customer, total, header.creditField);
  }

  // Every product's stock, counted over all its lines, before taking any.
  const wanted = new Map<string, number>();
  for (const line of priced) wanted.set(line.product.id, (wanted.get(line.product.id) ?? 0) + line.units);
  for (const line of priced) {
    const available = availableUnits(line.product.id);
    const units = wanted.get(line.product.id)!;
    if (available < units) throw invalid(`items.${line.index}.quantity`, `Only ${available} of ${line.product.name} in stock; ${units} requested.`);
  }

  const id = uuid();
  const number = onAccount ? nextNumber('invoice', 'INV', 6) : nextNumber('sale', 'S', 6);
  const at = now();
  run(
    `INSERT INTO sales (id, number, submission_id, status, sold_at, user_id, till_id, customer_name, customer_phone,
      total_minor, discount_minor, payment_method, payment_reference, amount_tendered_minor, change_minor, customer_id, due_on, quotation_id)
     VALUES (?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    id,
    number,
    header.submission,
    at,
    user.id,
    header.tillId,
    header.customer?.name ?? header.customerName,
    header.customer?.phone ?? header.customerPhone,
    total,
    priced.reduce((sum, l) => sum + l.discount, 0),
    header.method,
    header.reference,
    header.tendered,
    header.tendered === null ? null : header.tendered - total,
    header.customer?.id ?? null,
    onAccount ? addDays(today(), settings().credit_days) : null,
    header.quotationId,
  );

  let position = 0;
  priced.forEach((line, itemIndex) => {
    run(
      'INSERT INTO sale_items (id, sale_id, position, product_id, name, pack, quantity, unit_price_minor, discount_minor, line_total_minor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      uuid(),
      id,
      itemIndex,
      line.product.id,
      line.product.name,
      line.pack ? `${line.pack.name} of ${line.pack.units}` : null,
      line.packs,
      Math.round(line.gross / line.packs),
      line.discount,
      line.gross - line.discount,
    );

    // The lot that expires first (then the oldest), each at its price, the discount shared over them.
    let left = line.units;
    let done = 0;
    let discountLeft = line.discount;
    const parts: { batch: ReturnType<typeof sellableBatches>[number]; quantity: number }[] = [];
    for (const batch of sellableBatches(line.product.id)) {
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
        `INSERT INTO sale_lines (id, sale_id, product_id, batch_id, name, batch_number, expires_on, quantity, returned, unit_cost_minor, line_total_minor, position)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)`,
        uuid(),
        id,
        line.product.id,
        part.batch.id,
        line.product.name,
        part.batch.batch_number,
        part.batch.expires_on,
        part.quantity,
        part.batch.unit_cost_minor,
        partGross - share,
        position++,
      );
      movement(line.product.id, part.batch.id, 'sale', -part.quantity, null, null, number, user.id);
    });
  });
  return id;
}

function sellNow(user: UserRow, payload: SalePayload): { sale: Sale; duplicate: boolean } {
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
  const onAccount = payload.payment_method === 'account';
  if (onAccount) guard(allowed.sell_on_account);
  else if (!(s.payment_methods as string[]).includes(payload.payment_method)) throw invalid('payment_method', 'This way of paying is not taken here.');
  const customerId = text(payload.customer_id);
  const customer = customerId ? one<CustomerRow>('SELECT * FROM customers WHERE id = ?', customerId) : null;
  if (customerId && !customer) throw invalid('customer_id', 'This customer is not on the list.');

  const maxDiscount = allowed.discount ? s.max_discount_percent : 0;
  // Each product's shelf as it is before this sale; a second line of the
  // same product prices the units after the first's.
  const shelves = new Map<string, { tiers: PriceTier[]; taken: number }>();
  const priced = payload.items.map((line, index) => {
    const product = one<ProductRecord>('SELECT * FROM products WHERE id = ? AND active = 1', line.item_id);
    if (!product) throw invalid(`items.${index}.item_id`, 'This item cannot be sold.');
    const quantity = int(line.quantity);
    if (!quantity || quantity < 1) throw invalid(`items.${index}.quantity`, 'Enter a quantity of at least one.');
    const percent = Number(line.discount_percent ?? 0);
    if (!Number.isFinite(percent) || percent < 0 || percent > 100) throw invalid(`items.${index}.discount_percent`, 'A discount is from 0 to 100%.');
    if (percent > maxDiscount) {
      throw invalid(`items.${index}.discount_percent`, maxDiscount === 0 ? 'You may not give discounts.' : `You may take off at most ${maxDiscount}%.`);
    }
    const pack = line.pack_id ? one<{ id: string; name: string; units: number; price_minor: number }>('SELECT * FROM packs WHERE id = ? AND product_id = ?', line.pack_id, product.id) : null;
    if (line.pack_id && !pack) throw invalid(`items.${index}.pack_id`, 'This pack is not sold here.');
    const units = quantity * (pack?.units ?? 1);
    let shelf = shelves.get(product.id);
    if (!shelf) shelves.set(product.id, (shelf = { tiers: priceTiers(product.id, product.price_minor), taken: 0 }));
    const skip = shelf.taken;
    shelf.taken += units;
    const gross = lineGross(shelf.tiers, product.price_minor, pack, quantity, skip);
    // A unit's price at each point of the line, so each batch's share is its own price.
    const priceTo = (n: number) => Math.round(lineGross(shelf.tiers, product.price_minor, pack ? { units: 1, price_minor: pack.price_minor / pack.units } : null, n, skip));
    return { product, pack, packs: quantity, units, gross, priceTo, discount: percentOf(gross, percent), index };
  });

  const total = priced.reduce((sum, l) => sum + l.gross - l.discount, 0);
  const named = Boolean(text(payload.customer_name)) || customer !== null;
  if (!named) {
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

  const id = recordSale(
    user,
    {
      submission,
      tillId: till?.id ?? null,
      method: payload.payment_method,
      reference: onAccount ? null : text(payload.payment_reference),
      tendered,
      customer,
      customerName: text(payload.customer_name),
      customerPhone: text(payload.customer_phone),
      quotationId: null,
      creditField: 'customer_id',
    },
    priced.map((l) => ({ ...l, pack: l.pack ? { name: l.pack.name, units: l.pack.units } : null })),
  );
  return { sale: presentSale(id, user), duplicate: false };
}

export function movement(productId: string, batchId: string, type: string, quantity: number, reason: string | null, note: string | null, reference: string | null, userId: string) {
  run(
    'INSERT INTO stock_movements (id, product_id, batch_id, type, quantity, reason, note, reference, user_id, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    uuid(),
    productId,
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
  const sale = one<SaleRow & { seller: string; voider: string | null }>(
    `SELECT s.*, u.name AS seller, v.name AS voider
     FROM sales s
     JOIN users u ON u.id = s.user_id
     LEFT JOIN users v ON v.id = s.voided_by
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
  const customer = sale.customer_id ? one<CustomerRow>('SELECT * FROM customers WHERE id = ?', sale.customer_id) : null;
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
    customer: customer ? { id: customer.id, number: customer.number, name: customer.name, balance_minor: balanceOf(customer.id) } : null,
    invoice: sale.payment_method === 'account',
    due_on: sale.due_on,
    currency: settings().currency,
    total_minor: sale.total_minor,
    discount_minor: sale.discount_minor,
    payment_method: sale.payment_method,
    payment_method_label: methodLabel(sale.payment_method),
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
  const rows = all<SaleRow & { seller: string; returns: number }>(
    `SELECT s.*, u.name AS seller, (SELECT COUNT(*) FROM sale_returns r WHERE r.sale_id = s.id) AS returns
     FROM sales s JOIN users u ON u.id = s.user_id
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
      payment_method: s.payment_method,
      payment_method_label: methodLabel(s.payment_method),
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

export function voidSale(id: unknown, reason: unknown): Sale {
  const user = currentUser();
  guard(can(user).void);
  return transaction(() => {
    const found = visibleSale(id, user);
    const returns = one<{ n: number }>('SELECT COUNT(*) AS n FROM sale_returns WHERE sale_id = ?', found.id)!.n;
    if (found.status !== 'completed' || returns > 0) throw invalid('reason', 'This sale cannot be voided.');
    const why = text(reason);
    if (!why) throw invalid('reason', 'Say why the sale is voided.');
    for (const line of all<{ product_id: string; batch_id: string; quantity: number }>('SELECT product_id, batch_id, quantity FROM sale_lines WHERE sale_id = ?', found.id)) {
      run('UPDATE batches SET quantity = quantity + ? WHERE id = ?', line.quantity, line.batch_id);
      movement(line.product_id, line.batch_id, 'void', line.quantity, why, null, found.number, user.id);
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
    type Line = { id: string; product_id: string; batch_id: string; name: string; quantity: number; returned: number; line_total_minor: number };
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
        movement(line.product_id, line.batch_id, 'return', quantity, why, null, number, user.id);
      }
    }
    return { number, total_minor: total, sale: presentSale(found.id, user) };
  });
}


// Customers ------------------------------------------------------------------

/** Customers to sell to on account (or just to name on a sale): by name, phone or number. */
export function customers(search: unknown): CustomerOption[] {
  const user = currentUser();
  guard(hasPermission(user, 'retail.customers.view'));
  const q = typeof search === 'string' ? search.trim() : '';
  const like = contains(q);
  return all<CustomerRow>(
    `SELECT * FROM customers WHERE active = 1 AND (? = '' OR name LIKE ? ESCAPE '\' OR phone LIKE ? ESCAPE '\' OR number = ?)
     ORDER BY name COLLATE NOCASE LIMIT 30`,
    q,
    like,
    like,
    q.toUpperCase(),
  ).map(customerOption);
}
