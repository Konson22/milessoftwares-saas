import { decimalsFor } from '@/lib/currencies';
import { toBase, type Money } from '@/lib/pricing';
import type { PaymentMethod } from '@/lib/types';
import { contains, type BatchRow, type ProductRecord } from './catalog';
import { movement, tillFigures } from './counter';
import {
  cancelQuotation,
  customerShow,
  customersIndex,
  invoiceQuotation,
  invoicesIndex,
  owedFigures,
  quotationForm,
  quotationShow,
  quotationsIndex,
  recordPayment,
  saveCustomer,
  saveQuotation,
  voidPayment,
} from './customers';
import { billsDue, categoriesIndex, expenseTotals, expensesIndex, recordExpense, saveCategory, voidExpense } from './expenses';
import { all, nextNumber, one, run, transaction, type Param } from './database';
import { activeManagers, checkPassword, checkUsername, currentUser, hashPassword, hasPermission, permissionsOf, ROLES, type Role, type UserRow } from './session';
import { formatMoney, methodLabel, money, PAYMENT_LABELS, saveSettings, settings, type ShopSettings } from './settings';
import { addDays, bool, flag, guard, int, invalid, isDay, LocalError, notFound, now, text, toMajor, toMinor, today, uuid, Validation } from './util';

/**
 * The back office, page by page: a path ("stock", "products/<id>/edit")
 * answers the screen to draw (its component) and what it shows (its props);
 * an action answers its message and the page to go to next.
 */

export interface AppPage {
  component: string;
  props: Record<string, unknown>;
  url: string;
}

export interface ActionResult {
  message: string | null;
  print: null;
  /** The page to show next; null: stay, and reload this one. */
  redirect: string | null;
}

/** A page's query string; only get() is needed (React Native's URLSearchParams is partial). */
export interface Query {
  get(name: string): string | null;
}
type Data = Record<string, unknown>;

const has = (user: UserRow, permission: string) => hasPermission(user, permission);

function shared(user: UserRow) {
  const permissions = permissionsOf(user.role);
  const s = settings();
  return {
    access: { isOwner: user.role === 'manager', permissions },
    branch: { id: 'main', name: s.name },
    branchPermissions: permissions,
    currency: s.currency,
  };
}

const minor = (value: unknown) => toMinor(value, settings().decimals);
const major = (value: number) => toMajor(value, settings().decimals);

/** The rate a delivery was bought at: the one given, or today's under Settings. */
function rateFor(given: unknown): number | null {
  const n = Number(text(given));
  return text(given) && Number.isFinite(n) && n > 0 ? n : money().foreign?.rate ?? null;
}

/** "Box of 10 at USD 100.00 (1 USD = 4,500 SSP)" */
function boughtAs(b: { buy_unit: string | null; buy_unit_size: number; buy_price_minor: number | null; buy_currency: string | null; exchange_rate: number | null }, unit: string): string | null {
  if (b.buy_price_minor === null) return null;
  const s = settings();
  const code = b.buy_currency ?? s.currency;
  const price = `${code} ${(b.buy_price_minor / 10 ** decimalsFor(code)).toLocaleString('en-US', { minimumFractionDigits: decimalsFor(code), maximumFractionDigits: decimalsFor(code) })}`;
  const per = b.buy_unit_size > 1 ? `${b.buy_unit ?? 'Pack'} of ${b.buy_unit_size}` : `1 ${unit}`;
  const rate = code !== s.currency && b.exchange_rate ? ` (1 ${code} = ${b.exchange_rate.toLocaleString('en-US')} ${s.currency})` : '';
  return `${per} at ${price}${rate}`;
}

interface Pricing {
  units: number;
  buyUnit: string | null;
  buyUnitSize: number;
  buyQuantity: number;
  buyPriceMinor: number;
  buyCurrency: string;
  rate: number | null;
  /** All of it, in the shop's currency. */
  costMinor: number;
  unitCostMinor: number;
  /** A unit's selling price in the shop's currency; null: the product's. */
  sellPriceMinor: number | null;
}

/**
 * What a batch was bought for and sells for. Bought by the unit or by a pack
 * of so many (a box of 10 at USD 100), in either currency; a unit then costs
 * the pack's price over its size, in the shop's currency at the rate.
 */
function checkPricing(v: Validation, prefix: string, line: Data, unit: string, rate: number | null): Pricing {
  const s = settings();
  const allowed = [s.currency, s.second_currency].filter(Boolean);
  const size = int(line.buy_unit_size ?? 1) ?? 0;
  const quantity = int(line.buy_quantity ?? line.quantity);
  const buyCurrency = text(line.buy_currency) ?? s.currency;
  const sellCurrency = text(line.sell_currency) ?? s.currency;
  const buyPrice = toMinor(line.buy_price ?? line.unit_cost, decimalsFor(buyCurrency));
  const sellText = text(line.sell_price);
  const sellPrice = sellText ? toMinor(sellText, decimalsFor(sellCurrency)) : null;
  const foreignNeeded = buyCurrency !== s.currency || (sellText !== null && sellCurrency !== s.currency);

  if (size < 1) v.add(`${prefix}buy_unit_size`, `How many ${unit}s it comes in: 1 or more.`);
  if (!quantity || quantity < 1) v.add(`${prefix}buy_quantity`, 'At least one.');
  if (!allowed.includes(buyCurrency)) v.add(`${prefix}buy_currency`, 'Not a currency taken here.');
  if (!allowed.includes(sellCurrency)) v.add(`${prefix}sell_currency`, 'Not a currency taken here.');
  if (foreignNeeded && !rate) v.add(`${prefix}buy_currency`, `Set the exchange rate (1 ${s.second_currency ?? 'USD'} in ${s.currency}) under Settings first.`);
  if (buyPrice === null) v.add(`${prefix}buy_price`, 'Enter what it cost.');
  if (sellText !== null && (sellPrice === null || sellPrice <= 0)) v.add(`${prefix}sell_price`, 'Not a price.');

  // At this delivery's rate, which may differ from today's.
  const at: Money = {
    base: { code: s.currency, decimals: s.decimals },
    foreign: s.second_currency && rate ? { code: s.second_currency, decimals: decimalsFor(s.second_currency), rate } : null,
  };
  const each = Math.max(size, 1);
  const packCost = toBase(at, buyPrice ?? 0, buyCurrency);
  return {
    units: (quantity ?? 0) * each,
    buyUnit: each > 1 ? (text(line.buy_unit) ?? 'Pack') : null,
    buyUnitSize: each,
    buyQuantity: quantity ?? 0,
    buyPriceMinor: buyPrice ?? 0,
    buyCurrency,
    rate: buyCurrency === s.currency && sellCurrency === s.currency ? null : rate,
    costMinor: Math.round(packCost * (quantity ?? 0)),
    unitCostMinor: Math.round(packCost / each),
    sellPriceMinor: sellPrice === null ? null : Math.round(toBase(at, sellPrice, sellCurrency)),
  };
}

/**
 * A new list price for a product, from new stock. Stock already on the shelf
 * keeps the price it was selling at; packs keep their saving over single units.
 */
function reprice(productId: string, price: number) {
  const was = one<{ price_minor: number }>('SELECT price_minor FROM products WHERE id = ?', productId)!.price_minor;
  if (price === was) return;
  run('UPDATE batches SET sell_price_minor = ? WHERE product_id = ? AND sell_price_minor IS NULL AND quantity > 0', was, productId);
  run('UPDATE products SET price_minor = ?, updated_at = ? WHERE id = ?', price, now(), productId);
  if (was > 0) run('UPDATE packs SET price_minor = CAST(ROUND(price_minor * ? / ?) AS INTEGER) WHERE product_id = ?', price, was, productId);
}

/** Puts a batch on the shelf, priced. */
function shelve(product: ProductRecord, number: string, expires: string | null, p: Pricing, receiptId: string | null, at: string): string {
  const id = uuid();
  run(
    `INSERT INTO batches (id, product_id, batch_number, expires_on, quantity, unit_cost_minor, status, receipt_id, received_at,
      sell_price_minor, buy_unit, buy_unit_size, buy_price_minor, buy_currency, exchange_rate)
     VALUES (?, ?, ?, ?, ?, ?, 'available', ?, ?, ?, ?, ?, ?, ?, ?)`,
    id,
    product.id,
    number,
    expires,
    p.units,
    p.unitCostMinor,
    receiptId,
    at,
    p.sellPriceMinor,
    p.buyUnit,
    p.buyUnitSize,
    p.buyPriceMinor,
    p.buyCurrency,
    p.rate,
  );
  run('UPDATE products SET last_unit_cost_minor = ? WHERE id = ?', p.unitCostMinor, product.id);
  // The newest stock's price is the product's price from now on.
  if (p.sellPriceMinor !== null) reprice(product.id, p.sellPriceMinor);
  return id;
}

function parse(path: unknown): { segments: string[]; query: Query; clean: string } {
  const raw = typeof path === 'string' ? path.replace(/^\/+/, '') : '';
  const [pathname, search = ''] = raw.split('#')[0].split('?');
  const segments = pathname.split('/').filter(Boolean).map(decodeURIComponent);
  const values = new Map<string, string>();
  for (const pair of search.split('&').filter(Boolean)) {
    const [key, value = ''] = pair.split('=');
    const name = decodeURIComponent(key.replace(/\+/g, ' '));
    if (!values.has(name)) values.set(name, decodeURIComponent(value.replace(/\+/g, ' ')));
  }
  return { segments, query: { get: (name) => values.get(name) ?? null }, clean: raw };
}

const done = (message: string | null, redirect: string | null = null): ActionResult => ({ message, print: null, redirect });

// Pages ----------------------------------------------------------------------

export function getPage(path: unknown): AppPage {
  const user = currentUser();
  const { segments: s, query, clean } = parse(path);
  const page = (component: string, props: Record<string, unknown>): AppPage => ({ component, props: { ...shared(user), ...props }, url: clean });

  if (s.length === 0) return page('home', home(user));
  if (s[0] === 'stock' && s.length === 1) return page('stock/index', stockIndex(user, query));
  if (s[0] === 'stock' && s.length === 2) return page('stock/show', stockShow(user, s[1]));
  if (s[0] === 'batches' && s.length === 1) return page('batches/index', batchesIndex(user, query));
  if (s[0] === 'products' && s.length === 1) return page('products/index', productsIndex(user, query));
  if (s[0] === 'products' && s[1] === 'create') return page('products/form', productForm(user, null));
  if (s[0] === 'products' && s[2] === 'edit') return page('products/form', productForm(user, s[1]));
  if (s[0] === 'suppliers' && s.length === 1) return page('suppliers/index', suppliersIndex(user));
  if (s[0] === 'customers' && s.length === 1) return page('customers/index', customersIndex(user, query));
  if (s[0] === 'customers' && s.length === 2) return page('customers/show', customerShow(user, s[1]));
  if (s[0] === 'invoices' && s.length === 1) return page('invoices/index', invoicesIndex(user, query));
  if (s[0] === 'quotations' && s.length === 1) return page('quotations/index', quotationsIndex(user, query));
  if (s[0] === 'quotations' && s[1] === 'create') return page('quotations/form', quotationForm(user, null, query));
  if (s[0] === 'quotations' && s[2] === 'edit') return page('quotations/form', quotationForm(user, s[1], query));
  if (s[0] === 'quotations' && s.length === 2) return page('quotations/show', quotationShow(user, s[1]));
  if (s[0] === 'receipts' && s.length === 1) return page('receipts/index', receiptsIndex(user, query));
  if (s[0] === 'receipts' && s[1] === 'create') return page('receipts/form', receiptForm(user));
  if (s[0] === 'receipts' && s.length === 2) return page('receipts/show', receiptShow(user, s[1]));
  if (s[0] === 'expenses' && s.length === 1) return page('expenses/index', expensesIndex(user, query));
  if (s[0] === 'expenses' && s[1] === 'categories') return page('expenses/categories', categoriesIndex(user));
  if (s[0] === 'reports' && s.length === 1) return page('reports/index', reports(user, query));
  if (s[0] === 'users' && s.length === 1) return page('users/index', usersIndex(user));
  if (s[0] === 'settings' && s.length === 1) return page('settings/index', settingsPage(user));
  throw notFound();
}

export function act(method: unknown, path: unknown, data: unknown): ActionResult {
  const user = currentUser();
  const { segments: s } = parse(path);
  const body = (data && typeof data === 'object' ? data : {}) as Data;
  const route = `${String(method).toUpperCase()} ${s.map((part, i) => (i === 1 ? ':id' : part)).join('/')}`;

  switch (route) {
    case 'POST stock/:id/opening':
      return addOpeningStock(user, s[1], body);
    case 'POST batches/:id/adjustments':
      return adjustBatch(user, s[1], body);
    case 'POST batches/:id/disposals':
      return disposeBatch(user, s[1], body);
    case 'POST batches/:id/hold':
      return holdBatch(user, s[1], body);
    case 'POST batches/:id/release':
      return releaseBatch(user, s[1]);
    case 'POST batches/:id/price':
      return priceBatch(user, s[1], body);
    case 'POST products':
      return saveProduct(user, null, body);
    case 'PUT products/:id':
      return saveProduct(user, s[1], body);
    case 'POST suppliers':
      return saveSupplier(user, null, body);
    case 'PUT suppliers/:id':
      return saveSupplier(user, s[1], body);
    case 'POST customers':
      return saveCustomer(user, null, body);
    case 'PUT customers/:id':
      return saveCustomer(user, s[1], body);
    case 'POST customers/:id/payments':
      return recordPayment(user, s[1], body);
    case 'POST payments/:id/void':
      return voidPayment(user, s[1], body);
    case 'POST quotations':
      return saveQuotation(user, null, body);
    case 'PUT quotations/:id':
      return saveQuotation(user, s[1], body);
    case 'POST quotations/:id/invoice':
      return invoiceQuotation(user, s[1], body);
    case 'POST quotations/:id/cancel':
      return cancelQuotation(user, s[1]);
    case 'POST receipts':
      return receive(user, body);
    case 'POST expenses':
      return recordExpense(user, body);
    case 'POST expenses/:id/void':
      return voidExpense(user, s[1], body);
    case 'POST expense-categories':
      return saveCategory(user, null, body);
    case 'PUT expense-categories/:id':
      return saveCategory(user, s[1], body);
    case 'POST users':
      return saveUser(user, null, body);
    case 'PUT users/:id':
      return saveUser(user, s[1], body);
    case 'PUT settings':
      return updateSettings(user, body);
    default:
      throw notFound();
  }
}

// Dashboard ------------------------------------------------------------------

function home(user: UserRow) {
  const s = settings();
  const everyone = has(user, 'retail.sales.view');
  const sales = one<{ count: number; total: number }>(
    `SELECT COUNT(*) AS count, COALESCE(SUM(total_minor - COALESCE((SELECT SUM(r.total_minor) FROM sale_returns r WHERE r.sale_id = sales.id), 0)), 0) AS total
     FROM sales WHERE status = 'completed' AND date(sold_at, 'localtime') = ? AND (? = 1 OR user_id = ?)`,
    today(),
    everyone ? 1 : 0,
    user.id,
  )!;
  const rows = has(user, 'retail.stock.view') ? stockRows('') : null;
  const soon = addDays(today(), s.expiring_days);
  return {
    sales: { count: sales.count, totalMinor: sales.total, everyone },
    stock: rows
      ? {
          low: rows.filter((r) => r.low).length,
          expiring: rows.filter((r) => r.expiringSoon).length,
          expired: rows.filter((r) => r.expired > 0).length,
        }
      : null,
    billsDue: has(user, 'retail.expenses.manage') ? billsDue() : null,
    credit: has(user, 'retail.customers.view') ? owedFigures() : null,
    expiringDays: s.expiring_days,
    expiringBefore: soon,
  };
}

// Stock ----------------------------------------------------------------------

interface StockRow {
  id: string;
  label: string;
  unit: string;
  sellable: number;
  expired: number;
  held: number;
  nextExpiry: string | null;
  low: boolean;
  expiringSoon: boolean;
  reorderLevel: number;
}

function stockRows(search: string): StockRow[] {
  const t = today();
  const soon = addDays(t, settings().expiring_days);
  const params: Param[] = [t, t, t, t];
  let where = 'm.active = 1';
  if (search) {
    const like = contains(search);
    where += " AND (m.name LIKE ? ESCAPE '\\' OR m.category LIKE ? ESCAPE '\\' OR m.barcode = ?)";
    params.push(like, like, search);
  }
  return all<{ id: string; name: string; unit: string; reorder_level: number; sellable: number; expired: number; held: number; next_expiry: string | null }>(
    `SELECT m.id, m.name, m.unit, m.reorder_level,
       COALESCE(SUM(CASE WHEN b.status = 'available' AND (b.expires_on IS NULL OR b.expires_on >= ?) THEN b.quantity END), 0) AS sellable,
       COALESCE(SUM(CASE WHEN b.expires_on < ? THEN b.quantity END), 0) AS expired,
       COALESCE(SUM(CASE WHEN b.status <> 'available' AND (b.expires_on IS NULL OR b.expires_on >= ?) THEN b.quantity END), 0) AS held,
       MIN(CASE WHEN b.status = 'available' AND (b.expires_on IS NULL OR b.expires_on >= ?) THEN b.expires_on END) AS next_expiry
     FROM products m LEFT JOIN batches b ON b.product_id = m.id AND b.quantity > 0
     WHERE ${where}
     GROUP BY m.id
     ORDER BY m.name COLLATE NOCASE`,
    ...params,
  ).map((r) => ({
    id: r.id,
    label: r.name,
    unit: r.unit,
    sellable: r.sellable,
    expired: r.expired,
    held: r.held,
    nextExpiry: r.next_expiry,
    low: r.sellable <= r.reorder_level,
    expiringSoon: r.next_expiry !== null && r.next_expiry <= soon,
    reorderLevel: r.reorder_level,
  }));
}

function stockIndex(user: UserRow, query: Query) {
  guard(has(user, 'retail.stock.view'));
  const search = query.get('search')?.trim() ?? '';
  const filter = query.get('filter') ?? 'all';
  const keep: Record<string, (r: StockRow) => boolean> = {
    low: (r) => r.low,
    expiring: (r) => r.expiringSoon,
    expired: (r) => r.expired > 0,
    held: (r) => r.held > 0,
  };
  const rows = stockRows(search);
  return {
    rows: keep[filter] ? rows.filter(keep[filter]) : rows,
    filters: { search, filter },
    expiringDays: settings().expiring_days,
    canAdjust: has(user, 'retail.stock.adjust'),
    canReceive: has(user, 'retail.purchasing.receive'),
  };
}

const ADJUST_REASONS = [
  ['count', 'Stock count'],
  ['found', 'Found'],
  ['lost', 'Lost or stolen'],
  ['correction', 'Correcting a mistake'],
  ['other', 'Other'],
].map(([value, label]) => ({ value, label }));

const DISPOSAL_REASONS = [
  ['expired', 'Expired'],
  ['damaged', 'Damaged'],
  ['recalled', 'Recalled'],
  ['other', 'Other'],
].map(([value, label]) => ({ value, label }));

function stockShow(user: UserRow, id: string) {
  guard(has(user, 'retail.stock.view'));
  const product = one<ProductRecord>('SELECT * FROM products WHERE id = ?', id);
  if (!product) throw notFound();
  const t = today();
  const batches = all<BatchRow>(
    'SELECT * FROM batches WHERE product_id = ? AND (quantity > 0 OR received_at >= ?) ORDER BY expires_on IS NULL, expires_on, received_at LIMIT 100',
    id,
    addDays(t, -90),
  );
  return {
    product: {
      id: product.id,
      label: product.name,
      unit: product.unit,
      reorderLevel: product.reorder_level,
      tracksExpiry: Boolean(product.tracks_expiry),
    },
    batches: batches.map((b) => ({
      id: b.id,
      batchNumber: b.batch_number,
      expiresOn: b.expires_on,
      expired: b.expires_on !== null && b.expires_on < t,
      quantity: b.quantity,
      unitCostMinor: b.unit_cost_minor,
      sellPriceMinor: b.sell_price_minor ?? product.price_minor,
      ownPrice: b.sell_price_minor !== null,
      boughtAs: boughtAs(b, product.unit),
      status: b.status,
      holdReason: b.hold_reason,
      locations: [],
      notPutAway: 0,
    })),
    listPriceMinor: product.price_minor,
    packs: all<{ name: string; units: number }>('SELECT name, units FROM packs WHERE product_id = ? ORDER BY position', product.id),
    pricing: pricingOptions(),
    canPrice: has(user, 'retail.products.manage'),
    movements: all<{ id: string; at: string; type: string; quantity: number; batchNumber: string; reason: string | null; note: string | null; reference: string | null; by: string }>(
      `SELECT sm.id, sm.at, sm.type, sm.quantity, b.batch_number AS batchNumber, sm.reason, sm.note, sm.reference, u.name AS by
       FROM stock_movements sm JOIN batches b ON b.id = sm.batch_id JOIN users u ON u.id = sm.user_id
       WHERE sm.product_id = ? ORDER BY sm.at DESC LIMIT 40`,
      id,
    ).map((m) => ({ ...m, note: [m.note, m.reference].filter(Boolean).join(' · ') || null })),
    pendingDisposals: [],
    locations: [],
    reasons: ADJUST_REASONS,
    disposalReasons: DISPOSAL_REASONS,
    canAdjust: has(user, 'retail.stock.adjust'),
    canHold: has(user, 'retail.stock.hold'),
  };
}

function findBatch(id: string): BatchRow {
  const batch = one<BatchRow>('SELECT * FROM batches WHERE id = ?', id);
  if (!batch) throw notFound();
  return batch;
}

function addOpeningStock(user: UserRow, productId: string, data: Data): ActionResult {
  guard(has(user, 'retail.stock.adjust'));
  const product = one<ProductRecord>('SELECT * FROM products WHERE id = ?', productId);
  if (!product) throw notFound();
  const v = new Validation();
  const expires = text(data.expires_on);
  if (expires ? !isDay(expires) : product.tracks_expiry) v.add('expires_on', 'Enter the expiry date.');
  const pricing = checkPricing(v, '', data, product.unit, rateFor(data.exchange_rate));
  v.check();

  transaction(() => {
    const id = shelve(product, text(data.batch_number) ?? nextNumber('lot', 'LOT', 6), expires, pricing, null, now());
    movement(product.id, id, 'opening', pricing.units, 'Stock added', null, null, user.id);
  });
  return done(`Added ${pricing.units} ${product.unit}(s) of ${product.name}.`);
}

/** What the pricing fields offer: the currencies, and the rate today. */
function pricingOptions() {
  const s = settings();
  return {
    currency: s.currency,
    decimals: s.decimals,
    secondCurrency: s.second_currency,
    secondDecimals: s.second_currency ? decimalsFor(s.second_currency) : 2,
    rate: s.exchange_rate,
  };
}

/** A batch's own selling price, or back to the product's (empty). */
function priceBatch(user: UserRow, id: string, data: Data): ActionResult {
  guard(has(user, 'retail.products.manage'));
  const batch = findBatch(id);
  const s = settings();
  const price = text(data.sell_price);
  const currency = text(data.sell_currency) ?? s.currency;
  if (currency !== s.currency && (currency !== s.second_currency || !s.exchange_rate)) {
    throw invalid('sell_currency', `Set the exchange rate under Settings to price in ${currency}.`);
  }
  const entered = price === null ? null : toMinor(price, decimalsFor(currency));
  if (price !== null && (entered === null || entered <= 0)) throw invalid('sell_price', 'Not a price.');
  const base = entered === null ? null : Math.round(toBase(money(), entered, currency));
  run('UPDATE batches SET sell_price_minor = ? WHERE id = ?', base, batch.id);
  return done(base === null ? `Batch ${batch.batch_number} sells at the product’s price.` : `Batch ${batch.batch_number} sells at ${formatMoney(base)} a unit.`);
}

// Batches --------------------------------------------------------------------

function batchesIndex(user: UserRow, query: Query) {
  guard(has(user, 'retail.stock.view'));
  const t = today();
  const soon = addDays(t, settings().expiring_days);
  const search = query.get('search')?.trim() ?? '';
  const filter = query.get('filter') ?? 'stock';
  const where = ['m.active = 1'];
  const params: Param[] = [];
  if (filter !== 'all') where.push('b.quantity > 0');
  if (filter === 'expiring') {
    where.push('b.expires_on >= ? AND b.expires_on <= ?');
    params.push(t, soon);
  }
  if (filter === 'expired') {
    where.push('b.expires_on < ?');
    params.push(t);
  }
  if (filter === 'held') where.push("b.status <> 'available'");
  if (search) {
    const like = contains(search);
    where.push("(m.name LIKE ? ESCAPE '\\' OR m.category LIKE ? ESCAPE '\\' OR b.batch_number LIKE ? ESCAPE '\\' OR m.barcode = ?)");
    params.push(like, like, like, search);
  }
  const rows = all<BatchRow & { name: string; unit: string; list_price: number; receipt_number: string | null; supplier: string | null }>(
    `SELECT b.*, m.name, m.unit, m.price_minor AS list_price, r.number AS receipt_number, s.name AS supplier
     FROM batches b JOIN products m ON m.id = b.product_id
     LEFT JOIN receipts r ON r.id = b.receipt_id LEFT JOIN suppliers s ON s.id = r.supplier_id
     WHERE ${where.join(' AND ')}
     ORDER BY b.expires_on IS NULL, b.expires_on, m.name COLLATE NOCASE LIMIT 500`,
    ...params,
  );
  return {
    batches: rows.map((b) => {
      const price = b.sell_price_minor ?? b.list_price;
      return {
        id: b.id,
        productId: b.product_id,
        product: b.name,
        unit: b.unit,
        batchNumber: b.batch_number,
        expiresOn: b.expires_on,
        expired: b.expires_on !== null && b.expires_on < t,
        expiringSoon: b.expires_on !== null && b.expires_on >= t && b.expires_on <= soon,
        quantity: b.quantity,
        status: b.status,
        unitCostMinor: b.unit_cost_minor,
        sellPriceMinor: price,
        ownPrice: b.sell_price_minor !== null,
        marginPercent: price > 0 ? Math.round(((price - b.unit_cost_minor) / price) * 100) : null,
        boughtAs: boughtAs(b, b.unit),
        receiptId: b.receipt_id,
        receiptNumber: b.receipt_number,
        supplier: b.supplier,
      };
    }),
    filters: { search, filter },
    expiringDays: settings().expiring_days,
    canReceive: has(user, 'retail.purchasing.receive'),
  };
}

function adjustBatch(user: UserRow, id: string, data: Data): ActionResult {
  guard(has(user, 'retail.stock.adjust'));
  const batch = findBatch(id);
  const change = int(data.change);
  const reason = ADJUST_REASONS.find((r) => r.value === data.reason);
  if (!change) throw invalid('change', 'Enter how many to add (5) or take off (−3).');
  if (batch.quantity + change < 0) throw invalid('change', `Only ${batch.quantity} on hand.`);
  if (!reason) throw invalid('reason', 'Choose why.');
  transaction(() => {
    run('UPDATE batches SET quantity = quantity + ? WHERE id = ?', change, batch.id);
    movement(batch.product_id, batch.id, 'adjustment', change, reason.label, text(data.note), null, user.id);
  });
  return done('Adjusted.');
}

function disposeBatch(user: UserRow, id: string, data: Data): ActionResult {
  guard(has(user, 'retail.stock.adjust'));
  const batch = findBatch(id);
  const quantity = int(data.quantity);
  const reason = DISPOSAL_REASONS.find((r) => r.value === data.reason);
  if (!quantity || quantity < 1 || quantity > batch.quantity) throw invalid('quantity', `From 1 to ${batch.quantity}.`);
  if (!reason) throw invalid('reason', 'Choose why.');
  transaction(() => {
    run('UPDATE batches SET quantity = quantity - ? WHERE id = ?', quantity, batch.id);
    movement(batch.product_id, batch.id, 'disposal', -quantity, reason.label, text(data.note), null, user.id);
  });
  return done(`Disposed of ${quantity}.`);
}

function holdBatch(user: UserRow, id: string, data: Data): ActionResult {
  guard(has(user, 'retail.stock.hold'));
  const batch = findBatch(id);
  const why = text(data.reason);
  if (data.status !== 'quarantined' && data.status !== 'recalled') throw invalid('status', 'Quarantined or recalled.');
  if (!why) throw invalid('reason', 'Say why.');
  run('UPDATE batches SET status = ?, hold_reason = ? WHERE id = ?', data.status, why, batch.id);
  return done(`Batch ${batch.batch_number} is held back: it is not sold until released.`);
}

function releaseBatch(user: UserRow, id: string): ActionResult {
  guard(has(user, 'retail.stock.hold'));
  const batch = findBatch(id);
  run("UPDATE batches SET status = 'available', hold_reason = NULL WHERE id = ?", batch.id);
  return done(`Batch ${batch.batch_number} is released.`);
}

// Products ------------------------------------------------------------------

function categories(): string[] {
  return all<{ category: string }>('SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category COLLATE NOCASE').map((r) => r.category);
}

function productsIndex(user: UserRow, query: Query) {
  guard(has(user, 'retail.products.view'));
  const search = query.get('search')?.trim() ?? '';
  const category = query.get('category') ?? '';
  const inactive = query.get('inactive') === '1';
  const where: string[] = [];
  const params: Param[] = [];
  if (!inactive) where.push('active = 1');
  if (category) {
    where.push('category = ?');
    params.push(category);
  }
  if (search) {
    const like = contains(search);
    where.push("(name LIKE ? ESCAPE '\\' OR category LIKE ? ESCAPE '\\' OR barcode = ?)");
    params.push(like, like, search);
  }
  const rows = all<ProductRecord>(`SELECT * FROM products ${where.length ? `WHERE ${where.join(' AND ')}` : ''} ORDER BY name COLLATE NOCASE LIMIT 1000`, ...params);
  return {
    products: rows.map((m) => ({
      id: m.id,
      name: m.name,
      unit: m.unit,
      barcode: m.barcode,
      category: m.category,
      tracksExpiry: Boolean(m.tracks_expiry),
      sellPriceMinor: m.price_minor,
      lastUnitCostMinor: m.last_unit_cost_minor,
      active: Boolean(m.active),
    })),
    filters: { search, category, inactive },
    categories: categories(),
    canManage: has(user, 'retail.products.manage'),
  };
}

function productForm(user: UserRow, id: string | null) {
  guard(has(user, 'retail.products.manage'));
  const m = id ? one<ProductRecord>('SELECT * FROM products WHERE id = ?', id) : null;
  if (id && !m) throw notFound();
  return {
    product: m
      ? {
          id: m.id,
          name: m.name,
          unit: m.unit,
          barcode: m.barcode,
          category: m.category,
          sellPrice: major(m.price_minor),
          lastUnitCostMinor: m.last_unit_cost_minor,
          reorderLevel: m.reorder_level,
          tracksExpiry: Boolean(m.tracks_expiry),
          active: Boolean(m.active),
          packs: all<{ name: string; units: number; price_minor: number }>('SELECT name, units, price_minor FROM packs WHERE product_id = ? ORDER BY position', m.id).map((p) => ({
            name: p.name,
            units: p.units,
            sellPrice: major(p.price_minor),
          })),
        }
      : null,
    categories: categories(),
  };
}

function saveProduct(user: UserRow, id: string | null, data: Data): ActionResult {
  guard(has(user, 'retail.products.manage'));
  const existing = id ? one<ProductRecord>('SELECT * FROM products WHERE id = ?', id) : null;
  if (id && !existing) throw notFound();

  const v = new Validation();
  const name = text(data.name);
  const unit = text(data.unit);
  const barcode = text(data.barcode);
  const price = minor(data.sell_price);
  const reorder = int(data.reorder_level) ?? 0;
  if (!name) v.add('name', 'Enter the name.');
  if (!unit) v.add('unit', 'Enter what it is sold by.');
  if (price === null) v.add('sell_price', 'Enter the price.');
  if (reorder < 0) v.add('reorder_level', 'Zero or more.');
  if (barcode) {
    const taken = one<{ id: string; name: string }>('SELECT id, name FROM products WHERE barcode = ?', barcode);
    if (taken && taken.id !== id) v.add('barcode', `${taken.name} has this barcode.`);
  }
  const packs = (Array.isArray(data.packs) ? data.packs : []) as Data[];
  const cleanPacks = packs.map((p, i) => {
    const packName = text(p.name);
    const units = int(p.units);
    const packPrice = text(p.sell_price) ? minor(p.sell_price) : units && price !== null ? units * price : null;
    if (!packName) v.add(`packs.${i}.name`, 'Name the pack.');
    if (!units || units < 2) v.add(`packs.${i}.units`, 'Two or more.');
    if (packPrice === null) v.add(`packs.${i}.sell_price`, 'Not an amount.');
    return { name: packName!, units: units!, price: packPrice! };
  });
  v.check();

  const at = now();
  const productId = id ?? uuid();
  transaction(() => {
    const values: Param[] = [
      name!,
      unit!,
      barcode,
      text(data.category),
      price!,
      reorder,
      // Off unless asked for: most of a shop's goods do not expire.
      flag(bool(data.tracks_expiry)),
      flag(data.active === undefined ? true : bool(data.active)),
    ];
    if (existing) {
      run(
        `UPDATE products SET name = ?, unit = ?, barcode = ?, category = ?, price_minor = ?, reorder_level = ?, tracks_expiry = ?, active = ?, updated_at = ? WHERE id = ?`,
        ...values,
        at,
        productId,
      );
    } else {
      run(
        `INSERT INTO products (name, unit, barcode, category, price_minor, reorder_level, tracks_expiry, active, created_at, updated_at, id)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        ...values,
        at,
        at,
        productId,
      );
    }
    // Packs keep their ids in order, so carts holding one still sell it.
    const current = all<{ id: string }>('SELECT id FROM packs WHERE product_id = ? ORDER BY position', productId);
    cleanPacks.forEach((p, i) => {
      if (current[i]) run('UPDATE packs SET name = ?, units = ?, price_minor = ?, position = ? WHERE id = ?', p.name, p.units, p.price, i, current[i].id);
      else run('INSERT INTO packs (id, product_id, name, units, price_minor, position) VALUES (?, ?, ?, ?, ?, ?)', uuid(), productId, p.name, p.units, p.price, i);
    });
    for (const gone of current.slice(cleanPacks.length)) run('DELETE FROM packs WHERE id = ?', gone.id);
  });
  return done(existing ? `${name} saved.` : `${name} added.`, 'products');
}

// Suppliers ------------------------------------------------------------------

function suppliersIndex(user: UserRow) {
  guard(has(user, 'retail.purchasing.view'));
  return {
    suppliers: all<{ id: string; name: string; phone: string | null; email: string | null; address: string | null; active: number }>(
      'SELECT * FROM suppliers ORDER BY active DESC, name COLLATE NOCASE',
    ).map((s) => ({ ...s, active: Boolean(s.active) })),
    canManage: has(user, 'retail.purchasing.manage'),
  };
}

function saveSupplier(user: UserRow, id: string | null, data: Data): ActionResult {
  guard(has(user, 'retail.purchasing.manage'));
  if (id && !one('SELECT id FROM suppliers WHERE id = ?', id)) throw notFound();
  const name = text(data.name);
  if (!name) throw invalid('name', 'Enter the name.');
  const email = text(data.email);
  if (email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) throw invalid('email', 'Not an email address.');
  if (id) {
    run('UPDATE suppliers SET name = ?, phone = ?, email = ?, address = ?, active = ? WHERE id = ?', name, text(data.phone), email, text(data.address), flag(data.active === undefined ? true : bool(data.active)), id);
  } else {
    run('INSERT INTO suppliers (id, name, phone, email, address, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', uuid(), name, text(data.phone), email, text(data.address), now());
  }
  return done(`${name} saved.`);
}

// Goods received -------------------------------------------------------------

function receiptsIndex(user: UserRow, query: Query) {
  guard(has(user, 'retail.purchasing.view'));
  const search = query.get('search')?.trim() ?? '';
  const like = contains(search);
  return {
    receipts: all<{ id: string; number: string; received_on: string; supplier: string | null; invoice_number: string | null; lines: number; total_minor: number; by: string }>(
      `SELECT r.id, r.number, r.received_on, s.name AS supplier, r.invoice_number, r.total_minor, u.name AS by,
         (SELECT COUNT(*) FROM receipt_lines l WHERE l.receipt_id = r.id) AS lines
       FROM receipts r LEFT JOIN suppliers s ON s.id = r.supplier_id JOIN users u ON u.id = r.user_id
       WHERE ? = '' OR r.number LIKE ? ESCAPE '\\' OR r.invoice_number LIKE ? ESCAPE '\\' OR s.name LIKE ? ESCAPE '\\'
       ORDER BY r.received_on DESC, r.created_at DESC LIMIT 300`,
      search,
      like,
      like,
      like,
    ),
    filters: { search },
    canReceive: has(user, 'retail.purchasing.receive'),
  };
}

function receiptForm(user: UserRow) {
  guard(has(user, 'retail.purchasing.receive'));
  return {
    suppliers: all<{ id: string; name: string }>('SELECT id, name FROM suppliers WHERE active = 1 ORDER BY name COLLATE NOCASE'),
    products: all<ProductRecord>('SELECT * FROM products WHERE active = 1 ORDER BY name COLLATE NOCASE').map((m) => {
      // As it was last bought: the same pack, currency and price.
      const last = one<{ buy_unit: string | null; buy_unit_size: number; buy_price_minor: number | null; buy_currency: string | null }>(
        'SELECT buy_unit, buy_unit_size, buy_price_minor, buy_currency FROM batches WHERE product_id = ? AND buy_price_minor IS NOT NULL ORDER BY received_at DESC LIMIT 1',
        m.id,
      );
      return {
        id: m.id,
        label: m.name,
        barcode: m.barcode,
        unit: m.unit,
        tracksExpiry: Boolean(m.tracks_expiry),
        packs: all<{ name: string; units: number }>('SELECT name, units FROM packs WHERE product_id = ? ORDER BY position', m.id),
        sellPrice: major(m.price_minor),
        last: last
          ? {
              buyUnit: last.buy_unit,
              buyUnitSize: last.buy_unit_size,
              buyCurrency: last.buy_currency ?? settings().currency,
              buyPrice: toMajor(last.buy_price_minor!, decimalsFor(last.buy_currency ?? settings().currency)),
            }
          : null,
      };
    }),
    pricing: pricingOptions(),
    today: today(),
  };
}

function receiptShow(user: UserRow, id: string) {
  guard(has(user, 'retail.purchasing.view'));
  const receipt = one<{ id: string; number: string; received_on: string; supplier: string | null; invoice_number: string | null; note: string | null; total_minor: number; by: string; created_at: string }>(
    `SELECT r.*, s.name AS supplier, u.name AS by FROM receipts r LEFT JOIN suppliers s ON s.id = r.supplier_id JOIN users u ON u.id = r.user_id WHERE r.id = ?`,
    id,
  );
  if (!receipt) throw notFound();
  return {
    receipt,
    lines: all<{
      id: string;
      product: string;
      batch_number: string;
      expires_on: string | null;
      quantity: number;
      unit_cost_minor: number;
      cost_minor: number | null;
      sell_price_minor: number | null;
      unit: string;
      buy_unit: string | null;
      buy_unit_size: number;
      buy_quantity: number | null;
      buy_price_minor: number | null;
      buy_currency: string | null;
      exchange_rate: number | null;
    }>(
      `SELECT l.id, m.name AS product, m.unit, b.batch_number, b.expires_on, l.quantity, l.unit_cost_minor, l.cost_minor, l.sell_price_minor,
         l.buy_unit, l.buy_unit_size, l.buy_quantity, l.buy_price_minor, l.buy_currency, l.exchange_rate
       FROM receipt_lines l JOIN products m ON m.id = l.product_id JOIN batches b ON b.id = l.batch_id WHERE l.receipt_id = ?`,
      id,
    ).map((l) => ({
      id: l.id,
      product: l.product,
      unit: l.unit,
      batch_number: l.batch_number,
      expires_on: l.expires_on,
      quantity: l.quantity,
      bought: l.buy_unit_size > 1 ? `${l.buy_quantity} × ${l.buy_unit ?? 'Pack'} of ${l.buy_unit_size}` : null,
      boughtAs: boughtAs(l, l.unit),
      unit_cost_minor: l.unit_cost_minor,
      sell_price_minor: l.sell_price_minor,
      total_minor: l.cost_minor ?? l.quantity * l.unit_cost_minor,
    })),
  };
}

function receive(user: UserRow, data: Data): ActionResult {
  guard(has(user, 'retail.purchasing.receive'));
  const v = new Validation();
  const supplierId = text(data.supplier_id);
  const receivedOn = text(data.received_on);
  if (supplierId && !one('SELECT id FROM suppliers WHERE id = ?', supplierId)) v.add('supplier_id', 'Not a supplier.');
  if (!receivedOn || !isDay(receivedOn)) v.add('received_on', 'Enter the day it came.');
  else if (receivedOn > today()) v.add('received_on', 'It cannot come in the future.');
  const lines = (Array.isArray(data.lines) ? data.lines : []) as Data[];
  if (lines.length === 0) v.add('lines', 'Add what came.');
  const rate = rateFor(data.exchange_rate);
  const checked = lines.map((line, i) => {
    const product = one<ProductRecord>('SELECT * FROM products WHERE id = ?', text(line.product_id) ?? '');
    const number = text(line.batch_number);
    const expires = text(line.expires_on);
    if (!product) v.add(`lines.${i}.product_id`, 'Choose the product.');
    if (expires ? !isDay(expires) : product?.tracks_expiry) v.add(`lines.${i}.expires_on`, 'Enter the expiry date.');
    else if (expires && expires < today()) v.add(`lines.${i}.expires_on`, 'This batch has already expired.');
    const pricing = checkPricing(v, `lines.${i}.`, line, product?.unit ?? 'unit', rate);
    return { product: product!, number, expires, pricing };
  });
  v.check();

  const id = uuid();
  const number = transaction(() => {
    const receiptNumber = nextNumber('receipt', 'GR', 6);
    const at = now();
    run(
      'INSERT INTO receipts (id, number, supplier_id, invoice_number, received_on, note, total_minor, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
      id,
      receiptNumber,
      supplierId,
      text(data.invoice_number),
      receivedOn!,
      text(data.note),
      checked.reduce((sum, l) => sum + l.pricing.costMinor, 0),
      user.id,
      at,
    );
    for (const { product, number: batchNumber, expires, pricing: p } of checked) {
      // Without a lot number from the supplier, the shop numbers it.
      const batchId = shelve(product, batchNumber ?? nextNumber('lot', 'LOT', 6), expires, p, id, at);
      run(
        `INSERT INTO receipt_lines (id, receipt_id, product_id, batch_id, quantity, unit_cost_minor, buy_unit, buy_unit_size, buy_quantity, buy_price_minor,
          buy_currency, exchange_rate, cost_minor, sell_price_minor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        uuid(),
        id,
        product.id,
        batchId,
        p.units,
        p.unitCostMinor,
        p.buyUnit,
        p.buyUnitSize,
        p.buyQuantity,
        p.buyPriceMinor,
        p.buyCurrency,
        p.rate,
        p.costMinor,
        p.sellPriceMinor,
      );
      movement(product.id, batchId, 'received', p.units, null, null, receiptNumber, user.id);
    }
    return receiptNumber;
  });
  return done(`Received ${number}: the stock can be sold now.`, `receipts/${id}`);
}

// Reports --------------------------------------------------------------------

function reports(user: UserRow, query: Query) {
  guard(has(user, 'retail.reports.view'));
  const s = settings();
  const to = isDay(query.get('to')) ? query.get('to')! : today();
  const from = isDay(query.get('from')) ? query.get('from')! : addDays(to, -6);
  const range = "date(s.sold_at, 'localtime') BETWEEN ? AND ?";
  const returned = 'COALESCE((SELECT SUM(r.total_minor) FROM sale_returns r WHERE r.sale_id = s.id), 0)';

  const totals = one<{ count: number; gross: number; discount: number; returns: number; voided: number }>(
    `SELECT
       COUNT(CASE WHEN s.status = 'completed' THEN 1 END) AS count,
       COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.total_minor END), 0) AS gross,
       COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.discount_minor END), 0) AS discount,
       COALESCE(SUM(CASE WHEN s.status = 'completed' THEN ${returned} END), 0) AS returns,
       COUNT(CASE WHEN s.status = 'voided' THEN 1 END) AS voided
     FROM sales s WHERE ${range}`,
    from,
    to,
  )!;
  const cost = one<{ cost: number }>(
    `SELECT COALESCE(SUM((l.quantity - l.returned) * l.unit_cost_minor), 0) AS cost
     FROM sale_lines l JOIN sales s ON s.id = l.sale_id WHERE s.status = 'completed' AND ${range}`,
    from,
    to,
  )!.cost;
  const net = totals.gross - totals.returns;
  const spent = expenseTotals(from, to);

  const grouped = (key: string, join = '') =>
    all<{ key: string; count: number; total: number }>(
      `SELECT ${key} AS key, COUNT(*) AS count, COALESCE(SUM(s.total_minor - ${returned}), 0) AS total
       FROM sales s ${join} WHERE s.status = 'completed' AND ${range} GROUP BY ${key} ORDER BY total DESC`,
      from,
      to,
    );

  const t = today();
  const stockValue = one<{ value: number; expired: number }>(
    `SELECT COALESCE(SUM(CASE WHEN expires_on IS NULL OR expires_on >= ? THEN quantity * unit_cost_minor END), 0) AS value,
            COALESCE(SUM(CASE WHEN expires_on < ? THEN quantity * unit_cost_minor END), 0) AS expired
     FROM batches WHERE quantity > 0`,
    t,
    t,
  )!;

  return {
    filters: { from, to },
    totals: {
      salesCount: totals.count,
      grossMinor: totals.gross,
      discountMinor: totals.discount,
      returnsMinor: totals.returns,
      netMinor: net,
      costMinor: cost,
      profitMinor: net - cost,
      expensesMinor: spent.totalMinor,
      netProfitMinor: net - cost - spent.totalMinor,
      voidedCount: totals.voided,
    },
    expensesByCategory: spent.byCategory,
    byDay: grouped("date(s.sold_at, 'localtime')").sort((a, b) => a.key.localeCompare(b.key)),
    byMethod: grouped('s.payment_method').map((r) => ({ ...r, key: methodLabel(r.key) })),
    credit: {
      ...owedFigures(),
      // Paid toward accounts in the period, by method.
      received: all<{ method: string; count: number; total: number }>(
        `SELECT method, COUNT(*) AS count, SUM(amount_minor) AS total FROM customer_payments
         WHERE voided_at IS NULL AND date(paid_at, 'localtime') BETWEEN ? AND ? GROUP BY method ORDER BY total DESC`,
        from,
        to,
      ).map((r) => ({ key: methodLabel(r.method), count: r.count, total: r.total })),
    },
    byStaff: grouped('u.name', 'JOIN users u ON u.id = s.user_id'),
    topProducts: all<{ name: string; unit: string; units: number; total: number }>(
      `SELECT m.name AS name, m.unit, SUM(l.quantity - l.returned) AS units,
         SUM(l.line_total_minor * (l.quantity - l.returned) / l.quantity) AS total
       FROM sale_lines l JOIN sales s ON s.id = l.sale_id JOIN products m ON m.id = l.product_id
       WHERE s.status = 'completed' AND ${range}
       GROUP BY l.product_id HAVING units > 0 ORDER BY total DESC LIMIT 20`,
      from,
      to,
    ),
    stock: {
      valueMinor: stockValue.value,
      expiredValueMinor: stockValue.expired,
      expiring: all<{ product_id: string; name: string; batch_number: string; expires_on: string; quantity: number; unit: string }>(
        `SELECT m.id AS product_id, m.name AS name, m.unit, b.batch_number, b.expires_on, b.quantity
         FROM batches b JOIN products m ON m.id = b.product_id
         WHERE b.quantity > 0 AND b.expires_on IS NOT NULL AND b.expires_on <= ? ORDER BY b.expires_on LIMIT 50`,
        addDays(t, s.expiring_days),
      ),
      low: stockRows('').filter((r) => r.low && r.reorderLevel > 0),
    },
    shifts: all<{ id: string; number: string; user: string; status: string; opened_at: string; closed_at: string | null; opening_float_minor: number; counted_cash_minor: number | null; closing_note: string | null }>(
      `SELECT t.*, u.name AS user FROM tills t JOIN users u ON u.id = t.user_id
       WHERE date(t.opened_at, 'localtime') BETWEEN ? AND ? ORDER BY t.opened_at DESC LIMIT 100`,
      from,
      to,
    ).map((t) => {
      const expected = tillFigures(t.id, t.opening_float_minor).expected;
      return {
        id: t.id,
        number: t.number,
        user: t.user,
        status: t.status,
        openedAt: t.opened_at,
        closedAt: t.closed_at,
        expectedMinor: expected,
        countedMinor: t.counted_cash_minor,
        differenceMinor: t.counted_cash_minor === null ? null : t.counted_cash_minor - expected,
        note: t.closing_note,
      };
    }),
    expiringDays: s.expiring_days,
  };
}

// Users ----------------------------------------------------------------------

function usersIndex(user: UserRow) {
  guard(has(user, 'retail.users.manage'));
  return {
    users: all<UserRow>('SELECT * FROM users ORDER BY active DESC, name COLLATE NOCASE').map((u) => ({
      id: u.id,
      name: u.name,
      username: u.username,
      role: u.role,
      roleLabel: ROLES.find((r) => r.value === u.role)?.label ?? u.role,
      active: Boolean(u.active),
      createdAt: u.created_at,
    })),
    roles: ROLES,
    meId: user.id,
  };
}

function saveUser(user: UserRow, id: string | null, data: Data): ActionResult {
  guard(has(user, 'retail.users.manage'));
  const existing = id ? one<UserRow>('SELECT * FROM users WHERE id = ?', id) : null;
  if (id && !existing) throw notFound();

  const v = new Validation();
  const name = text(data.name);
  const username = text(data.username);
  const role = ROLES.find((r) => r.value === data.role)?.value as Role | undefined;
  const active = data.active === undefined ? true : bool(data.active);
  const password = typeof data.password === 'string' ? data.password : '';
  if (!name) v.add('name', 'Enter their name.');
  checkUsername(v, username, id);
  if (!role) v.add('role', 'Choose a role.');
  if (!existing || password) {
    checkPassword(v, 'password', password);
    if (password !== data.password_confirmation) v.add('password_confirmation', 'The two passwords differ.');
  }
  if (existing && existing.id === user.id && !active) v.add('active', 'You cannot switch yourself off.');
  const losesManager = existing?.role === 'manager' && existing.active && (role !== 'manager' || !active);
  if (losesManager && activeManagers() <= 1) v.add('role', 'The shop needs at least one active manager.');
  v.check();

  if (existing) {
    run('UPDATE users SET name = ?, username = ?, role = ?, active = ? WHERE id = ?', name!, username!, role!, flag(active), existing.id);
    if (password) run('UPDATE users SET password_hash = ? WHERE id = ?', hashPassword(password), existing.id);
  } else {
    run('INSERT INTO users (id, name, username, role, password_hash, active, created_at) VALUES (?, ?, ?, ?, ?, 1, ?)', uuid(), name!, username!, role!, hashPassword(password), now());
  }
  return done(existing ? `${name} saved.` : `${name} can sign in now.`);
}

// Settings -------------------------------------------------------------------

function settingsPage(user: UserRow) {
  guard(has(user, 'retail.settings.manage'));
  const s = settings();
  return {
    settings: {
      ...s,
      second_currency: s.second_currency ?? '',
      exchange_rate: s.exchange_rate === null ? '' : String(s.exchange_rate),
      default_float: major(s.default_float_minor),
      require_customer_above: s.require_customer_above_minor === null ? '' : major(s.require_customer_above_minor),
    },
    paymentMethods: (Object.keys(PAYMENT_LABELS) as PaymentMethod[]).map((value) => ({ value, label: PAYMENT_LABELS[value] })),
  };
}

function updateSettings(user: UserRow, data: Data): ActionResult {
  guard(has(user, 'retail.settings.manage'));
  const current = settings();
  const v = new Validation();
  const name = text(data.name);
  const currency = text(data.currency)?.toUpperCase() ?? '';
  const methods = (Array.isArray(data.payment_methods) ? data.payment_methods : []).filter((m): m is PaymentMethod => typeof m === 'string' && m in PAYMENT_LABELS);
  const maxDiscount = Number(data.max_discount_percent);
  const float = minor(text(data.default_float) ?? '0');
  const above = text(data.require_customer_above);
  const aboveMinor = above ? minor(above) : null;
  const expiringDays = int(data.expiring_days);
  const creditDays = int(data.credit_days);
  const quoteDays = int(data.quote_days);
  if (!name) v.add('name', 'Enter the shop’s name.');
  if (!/^[A-Z]{3}$/.test(currency)) v.add('currency', 'Three letters, as SSP, KES or USD.');
  const second = text(data.second_currency)?.toUpperCase() ?? null;
  const rateText = text(data.exchange_rate)?.replace(/,/g, '') ?? null;
  const rate = rateText === null ? null : Number(rateText);
  if (second && !/^[A-Z]{3}$/.test(second)) v.add('second_currency', 'Three letters, as USD.');
  else if (second && second === currency) v.add('second_currency', 'A currency other than the shop’s own.');
  if (rate !== null && (!Number.isFinite(rate) || rate <= 0)) v.add('exchange_rate', 'A number above zero.');
  if (methods.length === 0) v.add('payment_methods', 'Take at least one way of paying.');
  if (!Number.isFinite(maxDiscount) || maxDiscount < 0 || maxDiscount > 100) v.add('max_discount_percent', 'From 0 to 100.');
  if (float === null) v.add('default_float', 'Not an amount.');
  if (above && aboveMinor === null) v.add('require_customer_above', 'Not an amount.');
  if (!expiringDays || expiringDays < 1 || expiringDays > 730) v.add('expiring_days', 'From 1 to 730 days.');
  if (creditDays === null || creditDays < 0 || creditDays > 365) v.add('credit_days', 'From 0 to 365 days.');
  if (!quoteDays || quoteDays < 1 || quoteDays > 365) v.add('quote_days', 'From 1 to 365 days.');
  if (data.paper_size !== '80mm' && data.paper_size !== '58mm') v.add('paper_size', 'Choose the paper.');
  v.check();

  const next: ShopSettings = {
    ...current,
    name: name!,
    address: text(data.address),
    contact: text(data.contact),
    currency,
    second_currency: second,
    exchange_rate: second ? rate : null,
    receipt_header: text(data.receipt_header),
    receipt_footer: text(data.receipt_footer),
    paper_size: data.paper_size as '80mm' | '58mm',
    show_customer: bool(data.show_customer),
    show_staff: bool(data.show_staff),
    payment_methods: methods,
    max_discount_percent: maxDiscount,
    shifts_required: bool(data.shifts_required),
    default_float_minor: float!,
    blind_close: bool(data.blind_close),
    require_customer_always: bool(data.require_customer_always),
    require_customer_above_minor: aboveMinor,
    expiring_days: expiringDays!,
    credit_days: creditDays!,
    quote_days: quoteDays!,
  };
  if (!next.shifts_required && one("SELECT id FROM tills WHERE status = 'open'")) {
    throw new LocalError('Close every open shift before selling without shifts.', 422, { shifts_required: ['Close every open shift first.'] });
  }
  saveSettings(next);
  return done('Settings saved.');
}

