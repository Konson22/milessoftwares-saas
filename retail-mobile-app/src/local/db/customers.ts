import { availableCredit, balanceOf, customerOption, invoicesOf, NET, type CustomerRow, type InvoiceState } from './accounts';
import { contains, type ProductRecord } from './catalog';
import { recordSale, type PricedLine } from './counter';
import { all, nextNumber, one, run, transaction } from './database';
import type { ActionResult, Query } from './pages';
import { hasPermission, type UserRow } from './session';
import { formatMoney, methodLabel, PAYMENT_LABELS, settings } from './settings';
import { addDays, bool, flag, guard, int, invalid, isDay, notFound, now, percentOf, text, toMajor, toMinor, today, uuid, Validation } from './util';

/**
 * The back office for customers: their accounts (invoices and payments),
 * quotations, and invoicing a quotation. Sales on account at the counter are
 * invoices too (counter.ts).
 */

type Data = Record<string, unknown>;

const has = (user: UserRow, permission: string) => hasPermission(user, permission);
const done = (message: string | null, redirect: string | null = null): ActionResult => ({ message, print: null, redirect });
const minor = (value: unknown) => toMinor(value, settings().decimals);
const major = (value: number) => toMajor(value, settings().decimals);

/** Each customer's balance, in one query. */
const BALANCE = `COALESCE((SELECT SUM(${NET}) FROM sales s WHERE s.customer_id = c.id AND s.payment_method = 'account' AND s.status = 'completed'), 0)
  - COALESCE((SELECT SUM(p.amount_minor) FROM customer_payments p WHERE p.customer_id = c.id AND p.voided_at IS NULL), 0)`;

function findCustomer(id: string): CustomerRow {
  const c = one<CustomerRow>('SELECT * FROM customers WHERE id = ?', id);
  if (!c) throw notFound();
  return c;
}

/** Invoices still owing, over every customer: what is owed, and how much of it is overdue. */
export function owedFigures(): { owedMinor: number; overdueMinor: number; overdueCount: number } {
  let owed = 0;
  let overdue = 0;
  let count = 0;
  for (const c of all<{ id: string }>(`SELECT c.id FROM customers c WHERE (${BALANCE}) > 0`)) {
    for (const inv of invoicesOf(c.id)) {
      owed += inv.owing_minor;
      if (inv.overdue) {
        overdue += inv.owing_minor;
        count++;
      }
    }
  }
  return { owedMinor: owed, overdueMinor: overdue, overdueCount: count };
}

// Customers ------------------------------------------------------------------

export function customersIndex(user: UserRow, query: Query) {
  guard(has(user, 'retail.customers.view'));
  const search = query.get('search')?.trim() ?? '';
  const filter = query.get('filter') ?? '';
  const where = ['1 = 1'];
  const params: string[] = [];
  if (search) {
    const like = contains(search);
    where.push("(c.name LIKE ? ESCAPE '\\' OR c.phone LIKE ? ESCAPE '\\' OR c.number = ?)");
    params.push(like, like, search.toUpperCase());
  }
  if (filter !== 'inactive') where.push('c.active = 1');
  const rows = all<CustomerRow & { balance: number }>(
    `SELECT c.*, ${BALANCE} AS balance FROM customers c WHERE ${where.join(' AND ')} ORDER BY c.name COLLATE NOCASE LIMIT 1000`,
    ...params,
  ).filter((c) => filter !== 'owing' || c.balance > 0);
  const figures = owedFigures();
  return {
    customers: rows.map((c) => ({
      id: c.id,
      number: c.number,
      name: c.name,
      phone: c.phone,
      onAccount: Boolean(c.on_account),
      creditLimitMinor: c.credit_limit_minor,
      balanceMinor: c.balance,
      active: Boolean(c.active),
    })),
    filters: { search, filter },
    owedMinor: figures.owedMinor,
    overdueMinor: figures.overdueMinor,
    canManage: has(user, 'retail.customers.manage'),
  };
}

export function customerShow(user: UserRow, id: string) {
  guard(has(user, 'retail.customers.view'));
  const c = findCustomer(id);
  const balance = balanceOf(c.id);
  const invoices = invoicesOf(c.id).reverse();
  const payments = all<{ id: string; number: string; amount_minor: number; method: string; reference: string | null; note: string | null; paid_at: string; by: string; voided_at: string | null; void_reason: string | null; till_status: string | null }>(
    `SELECT p.*, u.name AS by, t.status AS till_status FROM customer_payments p JOIN users u ON u.id = p.user_id LEFT JOIN tills t ON t.id = p.till_id
     WHERE p.customer_id = ? ORDER BY p.paid_at DESC LIMIT 200`,
    c.id,
  );
  const canVoid = has(user, 'retail.sales.void');
  return {
    customer: {
      id: c.id,
      number: c.number,
      name: c.name,
      phone: c.phone,
      email: c.email,
      address: c.address,
      note: c.note,
      onAccount: Boolean(c.on_account),
      creditLimit: c.credit_limit_minor === null ? '' : major(c.credit_limit_minor),
      creditLimitMinor: c.credit_limit_minor,
      active: Boolean(c.active),
      since: c.created_at,
    },
    balanceMinor: balance,
    availableMinor: c.on_account ? availableCredit(c) : 0,
    invoices,
    // The rest bought outright, under this customer's name.
    otherSales: all<{ id: string; number: string; sold_at: string; total_minor: number; status: string; payment_method: string }>(
      `SELECT id, number, sold_at, total_minor, status, payment_method FROM sales WHERE customer_id = ? AND payment_method <> 'account' ORDER BY sold_at DESC LIMIT 50`,
      c.id,
    ).map((s) => ({ ...s, method: methodLabel(s.payment_method) })),
    payments: payments.map((p) => ({
      id: p.id,
      number: p.number,
      amountMinor: p.amount_minor,
      method: methodLabel(p.method),
      reference: p.reference,
      note: p.note,
      paidAt: p.paid_at,
      by: p.by,
      voided: p.voided_at !== null,
      voidReason: p.void_reason,
      // A cash payment counted in a closed shift stays: that count is done.
      canVoid: canVoid && p.voided_at === null && p.till_status !== 'closed',
    })),
    quotations: all<{ id: string; number: string; status: string; issued_on: string; total_minor: number }>(
      'SELECT id, number, status, issued_on, total_minor FROM quotations WHERE customer_id = ? ORDER BY created_at DESC LIMIT 50',
      c.id,
    ),
    paymentMethods: settings().payment_methods.map((value) => ({ value, label: PAYMENT_LABELS[value] })),
    canManage: has(user, 'retail.customers.manage'),
    canTakePayment: has(user, 'retail.customers.payments') && balance > 0,
    canQuote: has(user, 'retail.quotations.manage') && Boolean(c.active),
  };
}

export function saveCustomer(user: UserRow, id: string | null, data: Data): ActionResult {
  // Anyone at the counter may add a customer; who buys on account, and how much, is a supervisor's.
  guard(has(user, id ? 'retail.customers.manage' : 'retail.customers.view'));
  const existing = id ? findCustomer(id) : null;
  const manage = has(user, 'retail.customers.manage');

  const v = new Validation();
  const name = text(data.name);
  const phone = text(data.phone);
  const email = text(data.email);
  const limitText = text(data.credit_limit);
  const limit = limitText === null ? null : minor(limitText);
  if (!name) v.add('name', 'Enter the name.');
  if (email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) v.add('email', 'Not an email address.');
  if (limitText !== null && (limit === null || limit < 0)) v.add('credit_limit', 'Not an amount.');
  if (phone && one('SELECT id FROM customers WHERE phone = ? AND id <> ?', phone, id ?? '')) v.add('phone', 'Another customer has this phone number.');
  v.check();

  const onAccount = manage ? bool(data.on_account) : Boolean(existing?.on_account);
  const creditLimit = manage ? limit : (existing?.credit_limit_minor ?? null);
  if (existing) {
    run(
      'UPDATE customers SET name = ?, phone = ?, email = ?, address = ?, note = ?, on_account = ?, credit_limit_minor = ?, active = ? WHERE id = ?',
      name!,
      phone,
      email,
      text(data.address),
      text(data.note),
      flag(onAccount),
      creditLimit,
      flag(data.active === undefined ? true : bool(data.active)),
      existing.id,
    );
    return done(`${name} saved.`);
  }
  const newId = uuid();
  run(
    `INSERT INTO customers (id, number, name, phone, email, address, note, on_account, credit_limit_minor, active, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)`,
    newId,
    nextNumber('customer', 'C', 5),
    name!,
    phone,
    email,
    text(data.address),
    text(data.note),
    flag(onAccount),
    creditLimit,
    now(),
  );
  return done(`${name} added.`, `customers/${newId}`);
}

/** Money from the customer toward what they owe; cash during a shift goes in its drawer. */
export function recordPayment(user: UserRow, customerId: string, data: Data): ActionResult {
  guard(has(user, 'retail.customers.payments'));
  return transaction(() => {
    const c = findCustomer(customerId);
    const submission = text(data.submission_id) ?? uuid();
    if (one('SELECT id FROM customer_payments WHERE submission_id = ?', submission)) return done('This payment was already recorded.');
    const s = settings();
    const balance = balanceOf(c.id);
    const amount = minor(data.amount);
    const method = text(data.method) ?? '';

    const v = new Validation();
    if (balance <= 0) v.add('amount', `${c.name} owes nothing.`);
    else if (amount === null || amount <= 0) v.add('amount', 'Enter the amount paid.');
    else if (amount > balance) v.add('amount', `${c.name} owes ${formatMoney(balance)}: no more than that.`);
    if (!(s.payment_methods as string[]).includes(method)) v.add('method', 'Choose how they paid.');
    v.check();

    const till = one<{ id: string }>("SELECT id FROM tills WHERE user_id = ? AND status = 'open'", user.id);
    if (method === 'cash' && !till && s.shifts_required && !has(user, 'retail.tills.manage')) {
      throw invalid('method', 'Open your shift to take cash, so it is counted in your drawer.');
    }
    const number = nextNumber('payment', 'PAY', 6);
    run(
      `INSERT INTO customer_payments (id, number, submission_id, customer_id, amount_minor, method, reference, note, till_id, user_id, paid_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      uuid(),
      number,
      submission,
      c.id,
      amount!,
      method,
      method === 'cash' ? null : text(data.reference),
      text(data.note),
      // Against the shift it was taken in; only cash changes what the drawer holds.
      till?.id ?? null,
      user.id,
      now(),
    );
    const left = balanceOf(c.id);
    return done(`${number}: ${formatMoney(amount!)} from ${c.name}. ${left > 0 ? `They owe ${formatMoney(left)}.` : 'Their account is settled.'}`);
  });
}

export function voidPayment(user: UserRow, paymentId: string, data: Data): ActionResult {
  guard(has(user, 'retail.sales.void'));
  const p = one<{ id: string; number: string; voided_at: string | null; till_id: string | null }>('SELECT * FROM customer_payments WHERE id = ?', paymentId);
  if (!p) throw notFound();
  if (p.voided_at) throw invalid('reason', 'This payment is already voided.');
  if (p.till_id && one("SELECT id FROM tills WHERE id = ? AND status = 'closed'", p.till_id)) {
    throw invalid('reason', 'It was counted in a shift that is closed: record the money going back as cash out instead.');
  }
  const why = text(data.reason);
  if (!why) throw invalid('reason', 'Say why the payment is voided.');
  run('UPDATE customer_payments SET voided_at = ?, voided_by = ?, void_reason = ? WHERE id = ?', now(), user.id, why, p.id);
  return done(`${p.number} voided: it no longer counts toward the account.`);
}

// Invoices -------------------------------------------------------------------

export function invoicesIndex(user: UserRow, query: Query) {
  guard(has(user, 'retail.customers.view'));
  const filter = query.get('filter') ?? '';
  const search = query.get('search')?.trim().toLowerCase() ?? '';
  const customers = all<{ id: string; name: string; number: string }>(
    "SELECT DISTINCT c.id, c.name, c.number FROM customers c JOIN sales s ON s.customer_id = c.id WHERE s.payment_method = 'account'",
  );
  const rows: (InvoiceState & { customer: string; customerId: string })[] = [];
  for (const c of customers) for (const inv of invoicesOf(c.id)) rows.push({ ...inv, customer: c.name, customerId: c.id });
  const shown = rows
    .filter((r) => (filter === 'owing' ? r.owing_minor > 0 : filter === 'overdue' ? r.overdue : true))
    .filter((r) => !search || r.number.toLowerCase().includes(search) || r.customer.toLowerCase().includes(search))
    .sort((a, b) => b.sold_at.localeCompare(a.sold_at))
    .slice(0, 300);
  const figures = owedFigures();
  return { invoices: shown, filters: { filter, search }, ...figures };
}

// Quotations -----------------------------------------------------------------

interface QuotationRow {
  id: string;
  number: string;
  customer_id: string;
  status: 'open' | 'invoiced' | 'cancelled';
  issued_on: string;
  valid_until: string;
  note: string | null;
  total_minor: number;
  discount_minor: number;
  sale_id: string | null;
  user_id: string;
  created_at: string;
}

interface QuotationLineRow {
  id: string;
  product_id: string;
  name: string;
  pack_id: string | null;
  pack: string | null;
  pack_units: number;
  quantity: number;
  unit_price_minor: number;
  discount_percent: number;
  discount_minor: number;
  line_total_minor: number;
}

function findQuotation(id: string): QuotationRow {
  const q = one<QuotationRow>('SELECT * FROM quotations WHERE id = ?', id);
  if (!q) throw notFound();
  return q;
}

const linesOf = (id: string) => all<QuotationLineRow>('SELECT * FROM quotation_lines WHERE quotation_id = ? ORDER BY position', id);

export function quotationsIndex(user: UserRow, query: Query) {
  guard(has(user, 'retail.quotations.manage'));
  const status = query.get('status') ?? 'open';
  const t = today();
  const rows = all<QuotationRow & { customer: string; lines: number }>(
    `SELECT q.*, c.name AS customer, (SELECT COUNT(*) FROM quotation_lines l WHERE l.quotation_id = q.id) AS lines
     FROM quotations q JOIN customers c ON c.id = q.customer_id
     WHERE (? = 'all' OR q.status = ?) ORDER BY q.created_at DESC LIMIT 300`,
    status,
    status,
  );
  return {
    quotations: rows.map((q) => ({
      id: q.id,
      number: q.number,
      customer: q.customer,
      status: q.status,
      issuedOn: q.issued_on,
      validUntil: q.valid_until,
      expired: q.status === 'open' && q.valid_until < t,
      lines: q.lines,
      totalMinor: q.total_minor,
    })),
    filters: { status },
  };
}

export function quotationForm(user: UserRow, id: string | null, query: Query) {
  guard(has(user, 'retail.quotations.manage'));
  const q = id ? findQuotation(id) : null;
  if (q && q.status !== 'open') throw invalid('status', 'Only an open quotation can be changed.');
  const s = settings();
  const products = all<ProductRecord>('SELECT * FROM products WHERE active = 1 ORDER BY name COLLATE NOCASE');
  const packs = all<{ id: string; product_id: string; name: string; units: number; price_minor: number }>('SELECT * FROM packs ORDER BY position');
  return {
    quotation: q
      ? {
          id: q.id,
          number: q.number,
          customerId: q.customer_id,
          validUntil: q.valid_until,
          note: q.note ?? '',
          lines: linesOf(q.id).map((l) => ({
            productId: l.product_id,
            name: l.name,
            packId: l.pack_id,
            quantity: String(l.quantity),
            price: major(l.unit_price_minor),
            discountPercent: l.discount_percent ? String(l.discount_percent) : '',
          })),
        }
      : null,
    customerId: query.get('customer') ?? '',
    customers: all<CustomerRow>('SELECT * FROM customers WHERE active = 1 ORDER BY name COLLATE NOCASE').map((c) => ({ id: c.id, number: c.number, name: c.name, phone: c.phone })),
    products: products.map((p) => ({
      id: p.id,
      name: p.name,
      unit: p.unit,
      barcode: p.barcode,
      price: major(p.price_minor),
      priceMinor: p.price_minor,
      packs: packs.filter((k) => k.product_id === p.id).map((k) => ({ id: k.id, name: k.name, units: k.units, price: major(k.price_minor), priceMinor: k.price_minor })),
    })),
    validUntil: addDays(today(), s.quote_days),
    canSetPrice: has(user, 'retail.products.manage'),
    maxDiscount: has(user, 'retail.sales.discount') ? s.max_discount_percent : 0,
  };
}

export function saveQuotation(user: UserRow, id: string | null, data: Data): ActionResult {
  guard(has(user, 'retail.quotations.manage'));
  const existing = id ? findQuotation(id) : null;
  if (existing && existing.status !== 'open') throw invalid('status', 'Only an open quotation can be changed.');
  const s = settings();
  const canSetPrice = has(user, 'retail.products.manage');
  const maxDiscount = has(user, 'retail.sales.discount') ? s.max_discount_percent : 0;

  const v = new Validation();
  const customer = one<CustomerRow>('SELECT * FROM customers WHERE id = ? AND active = 1', text(data.customer_id) ?? '');
  if (!customer) v.add('customer_id', 'Choose the customer.');
  const validUntil = text(data.valid_until);
  if (!isDay(validUntil)) v.add('valid_until', 'Enter a date.');
  else if (validUntil < today()) v.add('valid_until', 'It cannot end before today.');
  const lines = (Array.isArray(data.lines) ? data.lines : []) as Data[];
  if (lines.length === 0) v.add('lines', 'Add what is quoted.');

  const checked = lines.map((line, i) => {
    const product = one<ProductRecord>('SELECT * FROM products WHERE id = ? AND active = 1', text(line.product_id) ?? '');
    const packId = text(line.pack_id);
    const pack = product && packId ? one<{ id: string; name: string; units: number; price_minor: number }>('SELECT * FROM packs WHERE id = ? AND product_id = ?', packId, product.id) : null;
    const quantity = int(line.quantity);
    const listPrice = pack?.price_minor ?? product?.price_minor ?? 0;
    const priceText = text(line.price);
    const price = priceText === null ? listPrice : minor(priceText);
    const percent = Number(text(line.discount_percent) ?? 0);
    if (!product) v.add(`lines.${i}.product_id`, 'This product is not sold now.');
    if (packId && !pack) v.add(`lines.${i}.pack_id`, 'This pack is not sold now.');
    if (!quantity || quantity < 1) v.add(`lines.${i}.quantity`, 'At least one.');
    if (price === null || price <= 0) v.add(`lines.${i}.price`, 'Not a price.');
    else if (price !== listPrice && !canSetPrice) v.add(`lines.${i}.price`, `The price is ${formatMoney(listPrice)}; a supervisor may quote another.`);
    if (!Number.isFinite(percent) || percent < 0 || percent > 100) v.add(`lines.${i}.discount_percent`, 'From 0 to 100%.');
    else if (percent > maxDiscount) v.add(`lines.${i}.discount_percent`, maxDiscount === 0 ? 'You may not give discounts.' : `At most ${maxDiscount}%.`);
    const gross = (price ?? 0) * (quantity ?? 0);
    return { product: product!, pack, quantity: quantity!, price: price!, percent, gross, discount: percentOf(gross, percent) };
  });
  v.check();

  const at = now();
  const quotationId = existing?.id ?? uuid();
  const total = checked.reduce((sum, l) => sum + l.gross - l.discount, 0);
  const discount = checked.reduce((sum, l) => sum + l.discount, 0);
  const number = transaction(() => {
    let n = existing?.number;
    if (existing) {
      run(
        'UPDATE quotations SET customer_id = ?, valid_until = ?, note = ?, total_minor = ?, discount_minor = ?, updated_at = ? WHERE id = ?',
        customer!.id,
        validUntil!,
        text(data.note),
        total,
        discount,
        at,
        existing.id,
      );
      run('DELETE FROM quotation_lines WHERE quotation_id = ?', existing.id);
    } else {
      n = nextNumber('quotation', 'QT', 6);
      run(
        `INSERT INTO quotations (id, number, customer_id, status, issued_on, valid_until, note, total_minor, discount_minor, user_id, created_at, updated_at)
         VALUES (?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?)`,
        quotationId,
        n,
        customer!.id,
        today(),
        validUntil!,
        text(data.note),
        total,
        discount,
        user.id,
        at,
        at,
      );
    }
    checked.forEach((l, position) =>
      run(
        `INSERT INTO quotation_lines (id, quotation_id, position, product_id, name, pack_id, pack, pack_units, quantity, unit_price_minor, discount_percent, discount_minor, line_total_minor)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        uuid(),
        quotationId,
        position,
        l.product.id,
        l.product.name,
        l.pack?.id ?? null,
        l.pack ? `${l.pack.name} of ${l.pack.units}` : null,
        l.pack?.units ?? 1,
        l.quantity,
        l.price,
        l.percent,
        l.discount,
        l.gross - l.discount,
      ),
    );
    return n!;
  });
  return done(existing ? `${number} saved.` : `Quotation ${number} for ${customer!.name}.`, `quotations/${quotationId}`);
}

export function quotationShow(user: UserRow, id: string) {
  guard(has(user, 'retail.quotations.manage'));
  const q = findQuotation(id);
  const c = findCustomer(q.customer_id);
  const by = one<{ name: string }>('SELECT name FROM users WHERE id = ?', q.user_id)?.name ?? '';
  const sale = q.sale_id ? one<{ id: string; number: string }>('SELECT id, number FROM sales WHERE id = ?', q.sale_id) : null;
  const expired = q.status === 'open' && q.valid_until < today();
  return {
    quotation: {
      id: q.id,
      number: q.number,
      status: q.status,
      issuedOn: q.issued_on,
      validUntil: q.valid_until,
      expired,
      note: q.note,
      totalMinor: q.total_minor,
      discountMinor: q.discount_minor,
      by,
      sale,
    },
    customer: { ...customerOption(c), email: c.email, address: c.address },
    lines: linesOf(q.id).map((l) => ({
      id: l.id,
      name: l.name,
      pack: l.pack,
      quantity: l.quantity,
      unitPriceMinor: l.unit_price_minor,
      discountPercent: l.discount_percent,
      discountMinor: l.discount_minor,
      totalMinor: l.line_total_minor,
    })),
    canEdit: q.status === 'open',
    canInvoice: q.status === 'open' && !expired && has(user, 'retail.sales.credit'),
  };
}

/** The quotation as an invoice on the customer's account, at the prices quoted: the stock leaves now. */
export function invoiceQuotation(user: UserRow, id: string, data: Data): ActionResult {
  guard(has(user, 'retail.quotations.manage') && has(user, 'retail.sales.credit'));
  return transaction(() => {
    const q = findQuotation(id);
    if (q.status !== 'open') throw invalid('status', q.status === 'invoiced' ? 'This quotation is already invoiced.' : 'This quotation is cancelled.');
    if (q.valid_until < today()) throw invalid('valid_until', `It expired on ${q.valid_until}. Edit it to give a new date, at today’s prices.`);
    const customer = findCustomer(q.customer_id);
    const priced: PricedLine[] = linesOf(q.id).map((l, index) => {
      const product = one<ProductRecord>('SELECT * FROM products WHERE id = ? AND active = 1', l.product_id);
      if (!product) throw invalid(`items.${index}.item_id`, `${l.name} is no longer sold.`);
      const units = l.quantity * l.pack_units;
      const gross = l.unit_price_minor * l.quantity;
      return {
        product,
        pack: l.pack ? { name: l.pack.replace(/ of \d+$/, ''), units: l.pack_units } : null,
        packs: l.quantity,
        units,
        gross,
        priceTo: (n: number) => Math.round((gross * n) / units),
        discount: l.discount_minor,
        index,
      };
    });
    const till = one<{ id: string }>("SELECT id FROM tills WHERE user_id = ? AND status = 'open'", user.id);
    const saleId = recordSale(
      user,
      {
        submission: text(data.submission_id) ?? uuid(),
        tillId: till?.id ?? null,
        method: 'account',
        reference: q.number,
        tendered: null,
        customer,
        customerName: null,
        customerPhone: null,
        quotationId: q.id,
        creditField: 'customer_id',
      },
      priced,
    );
    run("UPDATE quotations SET status = 'invoiced', sale_id = ?, updated_at = ? WHERE id = ?", saleId, now(), q.id);
    const number = one<{ number: string }>('SELECT number FROM sales WHERE id = ?', saleId)!.number;
    return done(`Invoice ${number} for ${customer.name}: ${formatMoney(q.total_minor)} on their account.`);
  });
}

export function cancelQuotation(user: UserRow, id: string): ActionResult {
  guard(has(user, 'retail.quotations.manage'));
  const q = findQuotation(id);
  if (q.status !== 'open') throw invalid('status', 'Only an open quotation can be cancelled.');
  run("UPDATE quotations SET status = 'cancelled', updated_at = ? WHERE id = ?", now(), q.id);
  return done(`${q.number} cancelled.`);
}
