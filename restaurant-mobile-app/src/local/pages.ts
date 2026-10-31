import { all, one, run, transaction } from './db/database';
import { activeManagers, can, checkPassword, checkUsername, currentUser, hashPassword, permissionsOf, ROLES, signOut, type Role, type UserRow } from './db/session';
import { amountToMinor, branch, formatMoney, saveSettings, settings, toMajor } from './db/settings';
import { bool, guard, int, LocalError, notFound, now, text, uuid, Validation } from './db/util';
import * as R from './restaurant';

/**
 * The offline restaurant's pages and actions, by the web's paths: what
 * restaurant-app's controllers render and do, answered from the database on
 * this phone. A page is { component, props, url }; an action is { message,
 * redirect }, redirect null meaning "stay, and reload". Permissions are the
 * signed-in person's role's (db/session.ts), checked as the web's
 * "branch.can" middleware checks them.
 */

type Query = Record<string, string>;
type Data = Record<string, unknown>;

interface Page {
  component: string;
  props: Record<string, unknown>;
  url: string;
}

interface Result {
  message: string | null;
  redirect: string | null;
}

let signedOutHandler: () => void = () => {};

/** Called when no one is signed in any more (the account was switched off, a restore). */
export function setSignedOutHandler(handler: () => void) {
  signedOutHandler = handler;
}

export function onSignedOut() {
  signedOutHandler();
}

// Requests

function parse(path: string): { parts: string[]; query: Query } {
  const [route, search = ''] = path.split('?');
  const query: Query = {};
  for (const pair of search.split('&')) {
    if (!pair) continue;
    const [key, value = ''] = pair.split('=');
    query[decodeURIComponent(key)] = decodeURIComponent(value.replace(/\+/g, ' '));
  }
  return { parts: route.split('/').filter(Boolean), query };
}

/** The signed-in person, who must hold one of the permissions. */
function member(...anyOf: string[]): UserRow {
  const user = currentUser();
  if (anyOf.length > 0) guard(anyOf.some((p) => can(user, p)));
  return user;
}

function shared(user: UserRow) {
  const permissions = permissionsOf(user.role);
  return {
    access: { isOwner: user.role === 'manager', permissions },
    branch: { id: branch().id, name: branch().name },
    branchPermissions: permissions,
    currency: settings().currency,
  };
}

const page = (user: UserRow, component: string, url: string, props: Record<string, unknown>): Page => ({
  component,
  props: { ...shared(user), ...props },
  url,
});

const done = (message: string | null, redirect: string | null = null): Result => ({ message, redirect });

/** A day asked for (YYYY-MM-DD), or today; with its start and end as stored times. */
function dayOf(query: Query) {
  const asked = query.day && /^\d{4}-\d{2}-\d{2}$/.test(query.day) ? query.day : null;
  const start = asked ? new Date(`${asked}T00:00:00`) : new Date(new Date().setHours(0, 0, 0, 0));
  const end = new Date(start);
  end.setDate(end.getDate() + 1);
  const day = `${start.getFullYear()}-${String(start.getMonth() + 1).padStart(2, '0')}-${String(start.getDate()).padStart(2, '0')}`;
  return { day, from: start.toISOString(), to: end.toISOString() };
}

const nameOf = (userId: string | null) => (userId ? (one<{ name: string }>('SELECT name FROM users WHERE id = ?', userId)?.name ?? '—') : null);

// Pages

export function getPage(path: string): Page {
  const { parts, query } = parse(path);
  const [section, id, sub] = parts;
  const url = path;

  switch (section ?? '') {
    case '':
      return home(url);
    case 'floor':
      return floor(url);
    case 'orders':
      return id ? orderPage(url, id) : ordersPage(url, query);
    case 'kitchen':
      return kitchen(url);
    case 'bills':
      return id ? billPage(url, id) : billsPage(url, query);
    case 'tills':
      return id ? tillPage(url, id) : tillsPage(url, query);
    case 'menu':
      if (id === 'create') return menuForm(url, null);
      if (id && sub === 'edit') return menuForm(url, id);
      return menuPage(url, query);
    case 'tables':
      return tablesPage(url);
    case 'stock':
      return id ? stockItemPage(url, id) : stockPage(url, query);
    case 'users':
      return usersPage(url);
    case 'settings':
      return settingsPage(url);
  }
  throw notFound();
}

function home(url: string): Page {
  const user = member();
  const { from, to } = dayOf({});
  const takings = one<{ count: number; total: number }>(
    "SELECT COUNT(*) AS count, COALESCE(SUM(total_minor), 0) AS total FROM bills WHERE status = 'paid' AND paid_at >= ? AND paid_at < ?",
    from,
    to,
  )!;
  const lowStock = one<{ n: number }>(
    `SELECT COUNT(*) AS n FROM menu_items m LEFT JOIN stock_levels s ON s.menu_item_id = m.id
     WHERE m.kind = 'drink' AND m.active = 1 AND COALESCE(s.quantity, 0) <= m.reorder_level`,
  )!.n;

  return page(user, 'home', url, {
    openOrders: can(user, 'restaurant.orders.take') ? one<{ n: number }>("SELECT COUNT(*) AS n FROM orders WHERE status = 'open'")!.n : null,
    takings: can(user, 'restaurant.bills.view') ? { count: takings.count, totalMinor: takings.total } : null,
    lowStock: can(user, 'restaurant.stock.view') ? lowStock : null,
  });
}

function card(order: R.OrderRow) {
  return {
    id: order.id,
    number: order.number,
    guests: order.guests,
    customerName: order.customer_name,
    openedAt: order.opened_at,
    openedBy: nameOf(order.opened_by),
    totalMinor: R.billOf(order.id).total_minor,
    hasReady: one("SELECT id FROM kitchen_tickets WHERE order_id = ? AND status = 'ready'", order.id) !== null,
  };
}

function floor(url: string): Page {
  const user = member('restaurant.orders.take');
  const open = all<R.OrderRow>("SELECT * FROM orders WHERE status = 'open' ORDER BY opened_at");
  const byTable = new Map(open.filter((o) => o.dining_table_id).map((o) => [o.dining_table_id!, o]));
  const tables = all<R.TableRow>('SELECT * FROM dining_tables WHERE active = 1 ORDER BY area IS NULL, area, name');

  return page(user, 'floor', url, {
    tables: tables.map((table) => {
      const order = byTable.get(table.id);
      return { id: table.id, name: table.name, area: table.area, seats: table.seats, order: order ? card(order) : null };
    }),
    takeaways: open.filter((o) => o.service === R.TAKEAWAY).map(card),
  });
}

const billSummary = (orderId: string) => {
  const bill = one<R.BillRow>('SELECT * FROM bills WHERE order_id = ?', orderId);
  return bill ? { id: bill.id, number: bill.number, totalMinor: bill.total_minor, status: bill.status } : null;
};

const tableName = (tableId: string | null) => (tableId ? (one<{ name: string }>('SELECT name FROM dining_tables WHERE id = ?', tableId)?.name ?? null) : null);

function ordersPage(url: string, query: Query): Page {
  const user = member('restaurant.orders.take');
  const { day, from, to } = dayOf(query);
  const orders = all<R.OrderRow>('SELECT * FROM orders WHERE opened_at >= ? AND opened_at < ? ORDER BY opened_at DESC', from, to);

  return page(user, 'orders/index', url, {
    day,
    orders: orders.map((order) => ({
      id: order.id,
      number: order.number,
      service: order.service,
      table: tableName(order.dining_table_id),
      customerName: order.customer_name,
      status: order.status,
      openedAt: order.opened_at,
      openedBy: nameOf(order.opened_by),
      bill: billSummary(order.id),
    })),
  });
}

function menuForOrder() {
  return all<R.ItemRow>('SELECT * FROM menu_items WHERE active = 1 ORDER BY section IS NULL, section, name').map((item) => ({
    id: item.id,
    name: item.name,
    kind: item.kind,
    section: item.section,
    priceMinor: item.price_minor,
  }));
}

function orderPage(url: string, id: string): Page {
  const user = member('restaurant.orders.take');
  const order = one<R.OrderRow>('SELECT * FROM orders WHERE id = ?', id);
  if (!order) throw notFound();
  const isOpen = order.status === 'open';
  const lines = all<R.LineRow & { name: string; kind: string; ticket_number: string | null; ticket_status: string | null }>(
    `SELECT l.*, m.name, m.kind, t.number AS ticket_number, t.status AS ticket_status
     FROM order_lines l JOIN menu_items m ON m.id = l.menu_item_id LEFT JOIN kitchen_tickets t ON t.id = l.kitchen_ticket_id
     WHERE l.order_id = ? ORDER BY l.seq`,
    id,
  );
  const table = order.dining_table_id ? one<R.TableRow>('SELECT * FROM dining_tables WHERE id = ?', order.dining_table_id) : null;

  return page(user, 'orders/show', url, {
    order: {
      id: order.id,
      number: order.number,
      service: order.service,
      table: table ? { id: table.id, name: table.name } : null,
      guests: order.guests,
      customerName: order.customer_name,
      status: order.status,
      openedAt: order.opened_at,
      openedBy: nameOf(order.opened_by),
      cancelReason: order.cancel_reason,
      bill: billSummary(order.id),
      lines: lines.map((line) => ({
        id: line.id,
        name: line.name,
        kind: line.kind,
        quantity: line.quantity,
        note: line.note,
        status: line.voided_at ? 'voided' : (line.ticket_status ?? 'new'),
        ticket: line.ticket_number,
        voidReason: line.void_reason,
        voidedBy: nameOf(line.voided_by),
      })),
    },
    bill: R.billOf(order.id),
    menu: isOpen ? menuForOrder() : [],
    freeTables:
      isOpen && order.service === R.DINE_IN
        ? all<{ id: string; name: string }>(
            "SELECT id, name FROM dining_tables t WHERE active = 1 AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.dining_table_id = t.id AND o.status = 'open') ORDER BY name",
          )
        : [],
    paymentMethods: R.PAYMENT_METHODS,
    tillOpen: R.openTillOf(user) !== null,
    can: { void: can(user, 'restaurant.orders.void'), pay: can(user, 'restaurant.bills.take') },
  });
}

function kitchen(url: string): Page {
  const user = member('restaurant.kitchen.view');
  const tickets = all<R.TicketRow>("SELECT * FROM kitchen_tickets WHERE status IN ('preparing', 'ready') ORDER BY sent_at");

  return page(user, 'kitchen', url, {
    tickets: tickets.map((ticket) => {
      const order = one<R.OrderRow>('SELECT * FROM orders WHERE id = ?', ticket.order_id)!;
      return {
        id: ticket.id,
        number: ticket.number,
        status: ticket.status,
        sentAt: ticket.sent_at,
        readyAt: ticket.ready_at,
        sentBy: nameOf(ticket.sent_by),
        order: { id: order.id, number: order.number, service: order.service, table: tableName(order.dining_table_id), customerName: order.customer_name },
        lines: all<{ id: string; name: string; quantity: number; note: string | null; voided_at: string | null }>(
          'SELECT l.id, m.name, l.quantity, l.note, l.voided_at FROM order_lines l JOIN menu_items m ON m.id = l.menu_item_id WHERE l.kitchen_ticket_id = ? ORDER BY l.seq',
          ticket.id,
        ).map((line) => ({ id: line.id, name: line.name, quantity: line.quantity, note: line.note, voided: line.voided_at !== null })),
      };
    }),
  });
}

function billsPage(url: string, query: Query): Page {
  const user = member('restaurant.bills.view');
  const { day, from, to } = dayOf(query);
  const bills = all<R.BillRow>('SELECT * FROM bills WHERE paid_at >= ? AND paid_at < ? ORDER BY paid_at DESC', from, to);
  const kept = bills.filter((b) => b.status === 'paid');

  return page(user, 'bills/index', url, {
    day,
    bills: bills.map((bill) => ({
      id: bill.id,
      number: bill.number,
      paidAt: bill.paid_at,
      paidBy: nameOf(bill.paid_by),
      table: tableName(one<R.OrderRow>('SELECT * FROM orders WHERE id = ?', bill.order_id)?.dining_table_id ?? null),
      customerName: bill.customer_name,
      totalMinor: bill.total_minor,
      paymentMethod: R.paymentLabel(bill.payment_method),
      status: bill.status,
    })),
    takings: { count: kept.length, totalMinor: kept.reduce((sum, b) => sum + b.total_minor, 0) },
  });
}

function billPage(url: string, id: string): Page {
  const user = member('restaurant.bills.view');
  const bill = one<R.BillRow>('SELECT * FROM bills WHERE id = ?', id);
  if (!bill) throw notFound();
  const order = one<R.OrderRow>('SELECT * FROM orders WHERE id = ?', bill.order_id)!;

  return page(user, 'bills/show', url, {
    bill: {
      id: bill.id,
      number: bill.number,
      status: bill.status,
      paidAt: bill.paid_at,
      paidBy: nameOf(bill.paid_by),
      order: { id: order.id, number: order.number, table: tableName(order.dining_table_id) },
      customerName: bill.customer_name,
      currency: bill.currency,
      totalMinor: bill.total_minor,
      paymentMethod: R.paymentLabel(bill.payment_method),
      paymentReference: bill.payment_reference,
      tenderedMinor: bill.tendered_minor,
      changeMinor: bill.tendered_minor === null ? null : bill.change_minor,
      voidedAt: bill.voided_at,
      voidedBy: nameOf(bill.voided_by),
      voidReason: bill.void_reason,
      lines: all<{ id: string; name: string; quantity: number; unit_price_minor: number; total_minor: number }>(
        'SELECT * FROM bill_lines WHERE bill_id = ? ORDER BY seq',
        bill.id,
      ).map((line) => ({ id: line.id, name: line.name, quantity: line.quantity, unitPriceMinor: line.unit_price_minor, totalMinor: line.total_minor })),
    },
    canVoid: can(user, 'restaurant.bills.void'),
  });
}

function tillSummary(till: R.TillRow) {
  return {
    id: till.id,
    number: till.number,
    status: till.status,
    openedBy: nameOf(till.opened_by),
    openedAt: till.opened_at,
    openingFloatMinor: till.opening_float_minor,
    closedBy: nameOf(till.closed_by),
    closedAt: till.closed_at,
    expectedCashMinor: till.expected_cash_minor,
    countedCashMinor: till.counted_cash_minor,
    differenceMinor: till.counted_cash_minor === null || till.expected_cash_minor === null ? null : till.counted_cash_minor - till.expected_cash_minor,
    closingNote: till.closing_note,
  };
}

function tillsPage(url: string, query: Query): Page {
  const user = member('restaurant.bills.take', 'restaurant.tills.manage');
  const managing = can(user, 'restaurant.tills.manage');
  const { day, from, to } = dayOf(query);
  const tills = all<R.TillRow>(
    `SELECT * FROM till_shifts WHERE opened_at >= ? AND opened_at < ? ${managing ? '' : 'AND opened_by = ?'} ORDER BY opened_at DESC`,
    ...(managing ? [from, to] : [from, to, user.id]),
  );
  const mine = R.openTillOf(user);

  return page(user, 'tills/index', url, { day, managing, mine: mine ? tillSummary(mine) : null, tills: tills.map(tillSummary) });
}

/** A cashier sees only their own shifts; managers every shift here. */
function tillFor(user: UserRow, id: string): R.TillRow {
  const till = one<R.TillRow>('SELECT * FROM till_shifts WHERE id = ?', id);
  if (!till || (till.opened_by !== user.id && !can(user, 'restaurant.tills.manage'))) throw notFound();
  return till;
}

function tillPage(url: string, id: string): Page {
  const user = member('restaurant.bills.take', 'restaurant.tills.manage');
  const till = tillFor(user, id);
  return page(user, 'tills/show', url, { till: tillSummary(till), summary: R.tillSummary(till), methods: R.PAYMENT_METHODS });
}

const sections = () => all<{ section: string }>('SELECT DISTINCT section FROM menu_items WHERE section IS NOT NULL ORDER BY section').map((r) => r.section);

function itemSummary(item: R.ItemRow) {
  return {
    id: item.id,
    kind: item.kind,
    name: item.name,
    section: item.section,
    unit: item.unit,
    barcode: item.barcode,
    priceMinor: item.price_minor,
    reorderLevel: item.reorder_level,
    active: item.active === 1,
  };
}

/** Name, section or barcode containing the text (MenuItem::scopeSearch); `on` is the table's alias. */
function searchClause(search: string, on = ''): [string, string[]] {
  const textValue = search.trim();
  if (!textValue) return ['', []];
  const like = `%${textValue.toLowerCase()}%`;
  return [` AND (LOWER(${on}name) LIKE ? OR LOWER(${on}section) LIKE ? OR ${on}barcode = ?)`, [like, like, textValue]];
}

function menuPage(url: string, query: Query): Page {
  const user = member('restaurant.menu.view');
  const search = query.search ?? '';
  const section = query.section ?? '';
  const showInactive = bool(query.inactive);
  const [where, params] = searchClause(search);
  const items = all<R.ItemRow>(
    `SELECT * FROM menu_items WHERE 1 = 1${where}${section ? ' AND section = ?' : ''}${showInactive ? '' : ' AND active = 1'} ORDER BY section IS NULL, section, name LIMIT 300`,
    ...params,
    ...(section ? [section] : []),
  );

  return page(user, 'menu/index', url, {
    items: items.map(itemSummary),
    filters: { search, section, inactive: showInactive },
    sections: sections(),
    canManage: can(user, 'restaurant.menu.manage'),
  });
}

const hasStock = (itemId: string) => one('SELECT id FROM stock_movements WHERE menu_item_id = ?', itemId) !== null;

function menuForm(url: string, id: string | null): Page {
  const user = member('restaurant.menu.manage');
  const item = id ? one<R.ItemRow>('SELECT * FROM menu_items WHERE id = ?', id) : null;
  if (id && !item) throw notFound();

  return page(user, 'menu/form', url, {
    item: item ? { ...itemSummary(item), price: toMajor(item.price_minor), hasStock: hasStock(item.id) } : null,
    sections: sections(),
  });
}

function tablesPage(url: string): Page {
  const user = member('restaurant.tables.manage');
  const tables = all<R.TableRow & { in_use: number }>(
    "SELECT t.*, EXISTS (SELECT 1 FROM orders o WHERE o.dining_table_id = t.id AND o.status = 'open') AS in_use FROM dining_tables t ORDER BY area IS NULL, area, name",
  );

  return page(user, 'tables/index', url, {
    tables: tables.map((t) => ({ id: t.id, name: t.name, area: t.area, seats: t.seats, active: t.active === 1, inUse: t.in_use === 1 })),
    areas: [...new Set(tables.map((t) => t.area).filter((a): a is string => !!a))].sort(),
  });
}

function stockPage(url: string, query: Query): Page {
  const user = member('restaurant.stock.view');
  const search = query.search ?? '';
  const filter = query.filter === 'low' ? 'low' : 'all';
  const [where, params] = searchClause(search, 'm.');
  const rows = all<R.ItemRow & { on_hand: number | null; average_cost_minor: number | null }>(
    `SELECT m.*, s.quantity AS on_hand, s.average_cost_minor FROM menu_items m LEFT JOIN stock_levels s ON s.menu_item_id = m.id
     WHERE m.kind = 'drink' AND m.active = 1${where} ORDER BY m.name LIMIT 300`,
    ...params,
  ).map((item) => ({
    id: item.id,
    name: item.name,
    unit: item.unit,
    onHand: item.on_hand ?? 0,
    averageCostMinor: item.average_cost_minor ?? 0,
    reorderLevel: item.reorder_level,
    low: (item.on_hand ?? 0) <= item.reorder_level,
  }));

  return page(user, 'stock/index', url, { rows: filter === 'low' ? rows.filter((r) => r.low) : rows, filters: { search, filter } });
}

function drink(id: string): R.ItemRow {
  const item = one<R.ItemRow>('SELECT * FROM menu_items WHERE id = ?', id);
  if (!item || item.kind !== 'drink') throw notFound();
  return item;
}

function stockItemPage(url: string, id: string): Page {
  const user = member('restaurant.stock.view');
  const item = drink(id);
  const level = one<{ quantity: number; average_cost_minor: number }>('SELECT * FROM stock_levels WHERE menu_item_id = ?', id);

  return page(user, 'stock/show', url, {
    item: { id: item.id, name: item.name, unit: item.unit, reorderLevel: item.reorder_level, onHand: level?.quantity ?? 0, averageCostMinor: level?.average_cost_minor ?? 0 },
    movements: all<{ id: string; created_at: string; type: string; quantity: number; reason: string | null; note: string | null; created_by: string }>(
      'SELECT * FROM stock_movements WHERE menu_item_id = ? ORDER BY created_at DESC LIMIT 50',
      id,
    ).map((m) => ({
      id: m.id,
      at: m.created_at,
      type: m.type,
      quantity: m.quantity,
      reason: m.reason ? (R.STOCK_REASONS[m.reason] ?? m.reason) : null,
      note: m.note,
      by: nameOf(m.created_by),
    })),
    reasons: Object.entries(R.STOCK_REASONS).map(([value, label]) => ({ value, label })),
    canAdjust: can(user, 'restaurant.stock.adjust'),
  });
}

function usersPage(url: string): Page {
  const user = member('restaurant.users.manage');
  return page(user, 'users/index', url, {
    users: all<UserRow>('SELECT * FROM users ORDER BY active DESC, name').map((u) => ({
      id: u.id,
      name: u.name,
      username: u.username,
      role: u.role,
      active: u.active === 1,
      isYou: u.id === user.id,
    })),
    roles: ROLES,
  });
}

function settingsPage(url: string): Page {
  const user = member('restaurant.settings.manage');
  const s = settings();
  return page(user, 'settings', url, { settings: { name: s.name, address: s.address, phone: s.phone, currency: s.currency, billFooter: s.bill_footer } });
}

// Actions

export function act(method: string, path: string, data: Data): Result {
  const { parts } = parse(path);
  const [section, id, sub, subId, subAction] = parts;
  const route = `${method} ${[section, id ? ':id' : null, sub, subId ? ':sub' : null, subAction].filter(Boolean).join('/')}`;

  switch (route) {
    case 'POST orders':
      return storeOrder(data);
    case 'POST orders/:id/lines':
      return addLines(id, data);
    case 'DELETE orders/:id/lines/:sub':
      member('restaurant.orders.take');
      R.removeLine(id, subId);
      return done(null);
    case 'POST orders/:id/send': {
      const ticket = R.send(id, member('restaurant.orders.take'));
      return done(`Sent to the kitchen on ticket ${ticket.number}.`);
    }
    case 'POST orders/:id/lines/:sub/void': {
      const user = member('restaurant.orders.void');
      R.voidLine(id, subId, user, reasonOf(data));
      return done('Voided: it will not be billed.');
    }
    case 'POST orders/:id/move':
      return moveOrder(id, data);
    case 'POST orders/:id/pay':
      return payOrder(id, data);
    case 'POST orders/:id/cancel':
      return cancelOrder(id, data);
    case 'POST kitchen/:id/ready': {
      member('restaurant.kitchen.view');
      const ticket = R.advance(id, 'preparing', 'ready');
      return done(`Ticket ${ticket.number} is ready.`);
    }
    case 'POST kitchen/:id/served': {
      member('restaurant.kitchen.view', 'restaurant.orders.take');
      const ticket = R.advance(id, 'ready', 'served');
      return done(`Ticket ${ticket.number} served.`);
    }
    case 'POST bills/:id/void': {
      const bill = R.voidBill(id, member('restaurant.bills.void'), reasonOf(data));
      return done(`Bill ${bill.number} voided and refunded.`, `bills/${bill.id}`);
    }
    case 'POST tills':
      return startTill(data);
    case 'POST tills/:id/close':
      return closeTill(id, data);
    case 'POST menu':
      return saveItem(null, data);
    case 'PUT menu/:id':
      return saveItem(id, data);
    case 'POST tables':
      return saveTable(null, data);
    case 'PUT tables/:id':
      return saveTable(id, data);
    case 'POST stock/:id/opening':
      return openingStock(id, data);
    case 'POST stock/:id/adjustments':
      return adjustStock(id, data);
    case 'POST users':
      return saveUser(null, data);
    case 'PUT users/:id':
      return saveUser(id, data);
    case 'PUT settings':
      return saveRestaurant(data);
  }
  throw notFound();
}

function reasonOf(data: Data): string {
  const reason = text(data.reason);
  const v = new Validation();
  if (!reason) v.add('reason', 'Enter the reason.');
  else if (reason.length > 255) v.add('reason', 'At most 255 characters.');
  v.check();
  return reason!;
}

function storeOrder(data: Data): Result {
  const user = member('restaurant.orders.take');
  const v = new Validation();
  const service = data.service === R.DINE_IN || data.service === R.TAKEAWAY ? data.service : null;
  const guests = data.guests === null || data.guests === undefined || data.guests === '' ? null : int(data.guests);
  if (!service) v.add('service', 'Choose a table or a takeaway.');
  if (service === R.DINE_IN && !text(data.dining_table_id)) v.add('dining_table_id', 'Choose the table.');
  if (guests !== null && (guests < 1 || guests > 100)) v.add('guests', 'From 1 to 100 guests.');
  if (data.guests !== null && data.guests !== undefined && data.guests !== '' && guests === null) v.add('guests', 'A whole number of guests.');
  v.check();

  const order = R.openOrder(user, service!, service === R.DINE_IN ? text(data.dining_table_id) : null, guests, text(data.customer_name));
  return done(null, `orders/${order.id}`);
}

function addLines(orderId: string, data: Data): Result {
  const user = member('restaurant.orders.take');
  const v = new Validation();
  const raw = Array.isArray(data.lines) ? (data.lines as Data[]) : [];
  if (raw.length === 0) v.add('lines', 'Add at least one dish or drink.');
  if (raw.length > 50) v.add('lines', 'At most 50 lines at a time.');
  const lines = raw.map((line, i) => {
    const quantity = int(line.quantity);
    if (quantity === null || quantity < 1 || quantity > 1000) v.add(`lines.${i}.quantity`, 'From 1 to 1000.');
    const note = text(line.note);
    if (note && note.length > 160) v.add(`lines.${i}.note`, 'At most 160 characters.');
    return { item_id: String(line.item_id ?? ''), quantity: quantity ?? 0, note };
  });
  v.check();

  R.addLines(orderId, user, lines);
  return done(null);
}

function moveOrder(orderId: string, data: Data): Result {
  member('restaurant.orders.take');
  const tableId = text(data.dining_table_id);
  if (!tableId) throw new LocalError('Choose the table.', 422, { dining_table_id: ['Choose the table.'] });
  const { order, table } = R.move(orderId, tableId);
  return done(`Order ${order.number} moved to ${table.name}.`);
}

function payOrder(orderId: string, data: Data): Result {
  const user = member('restaurant.bills.take');
  const v = new Validation();
  const method = R.PAYMENT_METHODS.some((m) => m.value === data.payment_method) ? String(data.payment_method) : null;
  if (!method) v.add('payment_method', 'Choose how the bill is paid.');
  const reference = text(data.payment_reference);
  if (reference && reference.length > 64) v.add('payment_reference', 'At most 64 characters.');
  const tenderedText = text(data.amount_tendered);
  const tendered = tenderedText === null ? null : amountToMinor(tenderedText);
  if (tenderedText !== null && tendered === null) v.add('amount_tendered', 'Enter an amount, as 1250 or 1250.50.');
  v.check();

  const bill = R.pay(orderId, user, method!, tendered, reference);
  return done(`Bill ${bill.number} paid${bill.change_minor ? `, change ${formatMoney(bill.change_minor)}` : ''}.`, `orders/${orderId}`);
}

function cancelOrder(orderId: string, data: Data): Result {
  const user = member('restaurant.orders.take');
  const reason = reasonOf(data);
  // What the kitchen has is voided by cancelling: the same permission.
  if (R.hasLiveTickets(orderId)) guard(can(user, 'restaurant.orders.void'));
  const order = R.cancel(orderId, user, reason);
  return done(`Order ${order.number} cancelled.`, 'floor');
}

function startTill(data: Data): Result {
  const user = member('restaurant.bills.take');
  const typed = text(data.opening_float);
  const float = typed === null ? 0 : amountToMinor(typed);
  if (float === null) throw new LocalError('Enter an amount, as 1250 or 1250.50.', 422, { opening_float: ['Enter an amount, as 1250 or 1250.50.'] });
  const till = R.openTill(user, float);
  return done(`Shift ${till.number} started.`, 'tills');
}

function closeTill(tillId: string, data: Data): Result {
  const user = member('restaurant.bills.take', 'restaurant.tills.manage');
  const till = tillFor(user, tillId);
  const v = new Validation();
  const counted = amountToMinor(text(data.counted_cash) ?? '');
  if (counted === null) v.add('counted_cash', 'Enter the cash counted, as 1250 or 1250.50.');
  const note = text(data.closing_note);
  if (note && note.length > 255) v.add('closing_note', 'At most 255 characters.');
  v.check();

  const closed = R.closeTill(till.id, user, counted!, note);
  return done(`Shift ${closed.number} closed.`, `tills/${closed.id}`);
}

function saveItem(id: string | null, data: Data): Result {
  member('restaurant.menu.manage');
  const item = id ? one<R.ItemRow>('SELECT * FROM menu_items WHERE id = ?', id) : null;
  if (id && !item) throw notFound();

  const v = new Validation();
  const kind = data.kind === 'dish' || data.kind === 'drink' ? data.kind : null;
  const name = text(data.name);
  const sectionTyped = text(data.section);
  const unitTyped = text(data.unit);
  const barcode = text(data.barcode);
  const price = amountToMinor(text(data.price) ?? '');
  const reorder = data.reorder_level === null || data.reorder_level === undefined || data.reorder_level === '' ? 0 : int(data.reorder_level);
  if (!kind) v.add('kind', 'A dish or a drink.');
  if (!name) v.add('name', 'Enter the name.');
  else if (name.length > 255) v.add('name', 'At most 255 characters.');
  if (sectionTyped && sectionTyped.length > 64) v.add('section', 'At most 64 characters.');
  if (unitTyped && unitTyped.length > 32) v.add('unit', 'At most 32 characters.');
  if (price === null) v.add('price', 'Enter the price, as 1250 or 1250.50.');
  if (reorder === null || reorder < 0 || reorder > 1_000_000) v.add('reorder_level', 'A whole number, 0 or more.');
  const drinkItem = kind === 'drink';
  if (drinkItem && barcode) {
    const taken = one<{ id: string }>('SELECT id FROM menu_items WHERE barcode = ?', barcode);
    if (taken && taken.id !== id) v.add('barcode', 'Another item has this barcode.');
  }
  if (item && kind && kind !== item.kind && hasStock(item.id)) v.add('kind', `${item.name} has been stocked: it stays a drink.`);
  v.check();

  // A typed section, spelt as the one already in use.
  const section = sectionTyped ? (sections().find((s) => s.toLowerCase() === sectionTyped.toLowerCase()) ?? sectionTyped) : null;
  const values = [
    kind,
    name,
    section,
    unitTyped ?? (drinkItem ? 'bottle' : 'plate'),
    drinkItem ? barcode : null,
    price,
    drinkItem ? reorder : 0,
    data.active === undefined ? 1 : bool(data.active) ? 1 : 0,
  ];
  if (item) {
    run('UPDATE menu_items SET kind = ?, name = ?, section = ?, unit = ?, barcode = ?, price_minor = ?, reorder_level = ?, active = ? WHERE id = ?', ...values, item.id);
  } else {
    run(
      'INSERT INTO menu_items (kind, name, section, unit, barcode, price_minor, reorder_level, active, id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      ...values,
      uuid(),
      now(),
    );
  }
  return done(item ? `${name} saved.` : `${name} added to the menu.`, 'menu');
}

function saveTable(id: string | null, data: Data): Result {
  member('restaurant.tables.manage');
  const table = id ? one<R.TableRow>('SELECT * FROM dining_tables WHERE id = ?', id) : null;
  if (id && !table) throw notFound();

  const v = new Validation();
  const name = text(data.name);
  const area = text(data.area);
  const seats = int(data.seats);
  const active = data.active === undefined ? true : bool(data.active);
  if (!name) v.add('name', 'Enter the table’s name.');
  else if (name.length > 32) v.add('name', 'At most 32 characters.');
  else {
    const taken = one<{ id: string }>('SELECT id FROM dining_tables WHERE name = ?', name);
    if (taken && taken.id !== id) v.add('name', 'Another table has this name.');
  }
  if (area && area.length > 32) v.add('area', 'At most 32 characters.');
  if (seats === null || seats < 1 || seats > 100) v.add('seats', 'From 1 to 100 seats.');
  if (!active && table && R.openAt(table.id)) v.add('active', `Table ${table.name} is being served: close its order first.`);
  v.check();

  if (table) run('UPDATE dining_tables SET name = ?, area = ?, seats = ?, active = ? WHERE id = ?', name, area, seats, active ? 1 : 0, table.id);
  else run('INSERT INTO dining_tables (id, name, area, seats, active) VALUES (?, ?, ?, ?, ?)', uuid(), name, area, seats, active ? 1 : 0);
  return done(table ? `Table ${name} saved.` : `Table ${name} added.`);
}

function openingStock(id: string, data: Data): Result {
  const user = member('restaurant.stock.adjust');
  const item = drink(id);
  const v = new Validation();
  const quantity = int(data.quantity);
  const cost = amountToMinor(text(data.unit_cost) ?? '');
  const note = text(data.note);
  if (quantity === null || quantity < 1 || quantity > 1_000_000) v.add('quantity', 'A whole number, 1 or more.');
  if (cost === null) v.add('unit_cost', 'Enter what one cost, as 12 or 12.50.');
  if (note && note.length > 255) v.add('note', 'At most 255 characters.');
  v.check();

  R.openingStock(item, quantity!, cost!, user, note);
  return done(`${quantity} ${item.unit} of ${item.name} added.`);
}

function adjustStock(id: string, data: Data): Result {
  const user = member('restaurant.stock.adjust');
  const item = drink(id);
  const v = new Validation();
  const change = int(data.change);
  const reason = text(data.reason);
  const note = text(data.note);
  if (change === null || Math.abs(change) > 1_000_000) v.add('change', 'A whole number: more (12) or fewer (-3).');
  if (!reason) v.add('reason', 'Choose why the stock changed.');
  if (note && note.length > 255) v.add('note', 'At most 255 characters.');
  v.check();

  R.adjustStock(item, change!, reason!, user, note);
  return done(`Stock of ${item.name} corrected.`);
}

function saveUser(id: string | null, data: Data): Result {
  const me = member('restaurant.users.manage');
  const user = id ? one<UserRow>('SELECT * FROM users WHERE id = ?', id) : null;
  if (id && !user) throw notFound();

  const v = new Validation();
  const name = text(data.name);
  const username = text(data.username);
  const role = ROLES.some((r) => r.value === data.role) ? (data.role as Role) : null;
  const active = data.active === undefined ? true : bool(data.active);
  const password = typeof data.password === 'string' && data.password !== '' ? data.password : null;
  if (!name) v.add('name', 'Enter their name.');
  checkUsername(v, username, id);
  if (!role) v.add('role', 'Choose their role.');
  if (!user || password !== null) checkPassword(v, 'password', password ?? '');
  // The restaurant always keeps a manager who can sign in.
  if (user?.role === 'manager' && user.active && (role !== 'manager' || !active) && activeManagers() <= 1) {
    v.add('role', 'This is the only manager: make someone else a manager first.');
  }
  if (user && user.id === me.id && !active) v.add('active', 'You cannot switch yourself off.');
  v.check();

  transaction(() => {
    if (user) {
      run('UPDATE users SET name = ?, username = ?, role = ?, active = ? WHERE id = ?', name, username, role, active ? 1 : 0, user.id);
      if (password) run('UPDATE users SET password_hash = ? WHERE id = ?', hashPassword(password), user.id);
    } else {
      run(
        'INSERT INTO users (id, name, username, role, password_hash, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        uuid(),
        name,
        username,
        role,
        hashPassword(password!),
        active ? 1 : 0,
        now(),
      );
    }
  });
  if (user?.id === me.id && role !== me.role) signOut();
  return done(user ? `${name} saved.` : `${name} can now sign in as ${username}.`);
}

function saveRestaurant(data: Data): Result {
  member('restaurant.settings.manage');
  const v = new Validation();
  const name = text(data.name);
  const footer = text(data.bill_footer);
  if (!name) v.add('name', 'Enter the restaurant’s name.');
  if (footer && footer.length > 255) v.add('bill_footer', 'At most 255 characters.');
  v.check();

  saveSettings({ ...settings(), name: name!, address: text(data.address), phone: text(data.phone), bill_footer: footer });
  return done('Saved.');
}
