import { all, nextNumber, one, run, transaction } from './db/database';
import type { UserRow } from './db/session';
import { formatMoney, settings } from './db/settings';
import { invalid, now, uuid } from './db/util';

/**
 * The offline restaurant's rules, as restaurant-app's actions (Orders,
 * Bills, Tills, Stock) have them, so a restaurant behaves the same on the
 * phone as online:
 *
 * - A table has one open order; opening one at a busy table gives that
 *   order. Lines are priced at the bill, at today's menu prices. A line not
 *   yet sent is removed; one the kitchen has is voided, with a reason, and
 *   never billed. A paid or cancelled order never changes.
 * - New lines go to the kitchen together on one ticket (KT-000001), marked
 *   ready by the kitchen and served by the waiter.
 * - A bill (B-000001) is paid at once into the cashier's open till, and
 *   voided whole with the cash given back from the voider's till. Drinks
 *   leave stock with the bill at their average cost and come back with its
 *   void; stock never goes below zero.
 * - A till is opened with a float and closed with a count; a count that
 *   differs needs a note.
 *
 * Finance is not here: there are no other applications on the phone.
 */

export const DINE_IN = 'dine_in';
export const TAKEAWAY = 'takeaway';

export const PAYMENT_METHODS = [
  { value: 'cash', label: 'Cash' },
  { value: 'mobile_money', label: 'Mobile money' },
  { value: 'card', label: 'Card' },
  { value: 'bank_transfer', label: 'Bank transfer' },
  { value: 'other', label: 'Other' },
];

export const paymentLabel = (method: string) => PAYMENT_METHODS.find((m) => m.value === method)?.label ?? method;

/** Why a count changed stock: counted corrects either way, the rest write off. */
export const STOCK_REASONS: Record<string, string> = {
  count: 'Counted',
  damaged: 'Damaged or spilt',
  expired: 'Expired',
  missing: 'Missing',
};

export interface OrderRow {
  id: string;
  number: string;
  service: string;
  dining_table_id: string | null;
  guests: number | null;
  customer_name: string | null;
  status: 'open' | 'paid' | 'cancelled';
  opened_by: string;
  opened_at: string;
  closed_by: string | null;
  closed_at: string | null;
  cancel_reason: string | null;
}

export interface LineRow {
  id: string;
  order_id: string;
  menu_item_id: string;
  quantity: number;
  note: string | null;
  kitchen_ticket_id: string | null;
  voided_by: string | null;
  voided_at: string | null;
  void_reason: string | null;
}

export interface ItemRow {
  id: string;
  kind: 'dish' | 'drink';
  name: string;
  section: string | null;
  unit: string;
  barcode: string | null;
  price_minor: number;
  reorder_level: number;
  active: number;
}

export interface TicketRow {
  id: string;
  order_id: string;
  number: string;
  status: 'preparing' | 'ready' | 'served';
  sent_by: string;
  sent_at: string;
  ready_at: string | null;
}

export interface TillRow {
  id: string;
  number: string;
  status: 'open' | 'closed';
  opening_float_minor: number;
  opened_by: string;
  opened_at: string;
  closed_by: string | null;
  closed_at: string | null;
  expected_cash_minor: number | null;
  counted_cash_minor: number | null;
  closing_note: string | null;
}

export interface BillRow {
  id: string;
  order_id: string;
  till_shift_id: string;
  number: string;
  currency: string;
  total_minor: number;
  cost_minor: number;
  payment_method: string;
  tendered_minor: number | null;
  change_minor: number;
  payment_reference: string | null;
  customer_name: string | null;
  status: 'paid' | 'voided';
  paid_by: string;
  paid_at: string;
  voided_by: string | null;
  voided_at: string | null;
  void_reason: string | null;
  refund_till_shift_id: string | null;
}

export interface TableRow {
  id: string;
  name: string;
  area: string | null;
  seats: number;
  active: number;
}

const nextSeq = (table: 'order_lines' | 'bill_lines', column: string, id: string) =>
  one<{ n: number }>(`SELECT COALESCE(MAX(seq), 0) + 1 AS n FROM ${table} WHERE ${column} = ?`, id)!.n;

// Orders

export function openAt(tableId: string): OrderRow | null {
  return one<OrderRow>("SELECT * FROM orders WHERE dining_table_id = ? AND status = 'open'", tableId);
}

function activeTable(tableId: string | null, field = 'dining_table_id'): TableRow {
  const table = tableId ? one<TableRow>('SELECT * FROM dining_tables WHERE id = ? AND active = 1', tableId) : null;
  if (!table) throw invalid(field, 'This table is not in use at this branch.');
  return table;
}

/** An order to change: still open, or refused on `field`. */
export function lockOpen(orderId: string, field: string): OrderRow {
  const order = one<OrderRow>('SELECT * FROM orders WHERE id = ?', orderId);
  if (!order) throw invalid(field, 'This order is not here.');
  if (order.status !== 'open') throw invalid(field, `Order ${order.number} is ${order.status}: it can no longer change.`);
  return order;
}

export function openOrder(by: UserRow, service: string, tableId: string | null, guests: number | null, customerName: string | null): OrderRow {
  if (service === DINE_IN && !tableId) throw invalid('dining_table_id', 'Choose the table.');

  return transaction(() => {
    let table: TableRow | null = null;
    if (service === DINE_IN) {
      table = activeTable(tableId);
      const open = openAt(table.id);
      if (open) return open;
    }

    const id = uuid();
    run(
      "INSERT INTO orders (id, number, service, dining_table_id, guests, customer_name, status, opened_by, opened_at) VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?)",
      id,
      nextNumber('order', 'ORD', 6),
      service,
      table?.id ?? null,
      guests,
      customerName?.trim() || null,
      by.id,
      now(),
    );
    return one<OrderRow>('SELECT * FROM orders WHERE id = ?', id)!;
  });
}

export function addLines(orderId: string, by: UserRow, lines: { item_id: string; quantity: number; note: string | null }[]) {
  if (lines.length === 0) throw invalid('lines', 'Add at least one dish or drink.');
  const ids = [...new Set(lines.map((l) => l.item_id))];
  const onMenu = one<{ n: number }>(`SELECT COUNT(*) AS n FROM menu_items WHERE active = 1 AND id IN (${ids.map(() => '?').join(',')})`, ...ids)!.n;
  if (onMenu !== ids.length) throw invalid('lines', 'Something chosen is no longer on the menu.');

  transaction(() => {
    lockOpen(orderId, 'lines');
    let seq = nextSeq('order_lines', 'order_id', orderId);
    for (const line of lines) {
      run(
        'INSERT INTO order_lines (id, seq, order_id, menu_item_id, quantity, note, added_by, added_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        uuid(),
        seq++,
        orderId,
        line.item_id,
        line.quantity,
        line.note?.trim() || null,
        by.id,
        now(),
      );
    }
  });
}

function lineOf(orderId: string, lineId: string): LineRow {
  const line = one<LineRow>('SELECT * FROM order_lines WHERE id = ? AND order_id = ?', lineId, orderId);
  if (!line) throw invalid('line', 'This line is not on the order.');
  return line;
}

export function removeLine(orderId: string, lineId: string) {
  transaction(() => {
    lockOpen(orderId, 'line');
    const line = lineOf(orderId, lineId);
    if (line.kitchen_ticket_id) throw invalid('line', 'The kitchen already has this: void it instead.');
    run('DELETE FROM order_lines WHERE id = ?', line.id);
  });
}

export function send(orderId: string, by: UserRow): TicketRow {
  return transaction(() => {
    lockOpen(orderId, 'lines');
    const fresh = all<{ id: string }>('SELECT id FROM order_lines WHERE order_id = ? AND kitchen_ticket_id IS NULL AND voided_at IS NULL', orderId);
    if (fresh.length === 0) throw invalid('lines', 'Nothing new to send to the kitchen.');

    const id = uuid();
    run(
      "INSERT INTO kitchen_tickets (id, order_id, number, status, sent_by, sent_at) VALUES (?, ?, ?, 'preparing', ?, ?)",
      id,
      orderId,
      nextNumber('kitchen_ticket', 'KT', 6),
      by.id,
      now(),
    );
    run('UPDATE order_lines SET kitchen_ticket_id = ? WHERE order_id = ? AND kitchen_ticket_id IS NULL AND voided_at IS NULL', id, orderId);
    return one<TicketRow>('SELECT * FROM kitchen_tickets WHERE id = ?', id)!;
  });
}

export function voidLine(orderId: string, lineId: string, by: UserRow, reason: string) {
  transaction(() => {
    lockOpen(orderId, 'line');
    const line = lineOf(orderId, lineId);
    if (line.voided_at) throw invalid('line', 'This is already voided.');
    if (!line.kitchen_ticket_id) throw invalid('line', 'The kitchen does not have this yet: remove it instead.');
    run('UPDATE order_lines SET voided_by = ?, voided_at = ?, void_reason = ? WHERE id = ?', by.id, now(), reason.trim(), line.id);
  });
}

export function move(orderId: string, tableId: string): { order: OrderRow; table: TableRow } {
  return transaction(() => {
    const order = lockOpen(orderId, 'dining_table_id');
    if (order.service !== DINE_IN) throw invalid('dining_table_id', 'A takeaway has no table.');
    const table = activeTable(tableId);
    if (table.id !== order.dining_table_id) {
      const other = openAt(table.id);
      if (other) throw invalid('dining_table_id', `${table.name} is serving order ${other.number}.`);
      run('UPDATE orders SET dining_table_id = ? WHERE id = ?', table.id, order.id);
    }
    return { order, table };
  });
}

export interface PricedLine {
  item: ItemRow;
  quantity: number;
  unit_price_minor: number;
  total_minor: number;
}

/** What the lines come to at today's menu prices, one line per item whatever the notes. */
function price(orderId: string): { lines: PricedLine[]; total_minor: number } {
  const rows = all<{ menu_item_id: string; quantity: number }>(
    'SELECT menu_item_id, SUM(quantity) AS quantity FROM order_lines WHERE order_id = ? AND voided_at IS NULL GROUP BY menu_item_id ORDER BY MIN(seq)',
    orderId,
  );
  const lines: PricedLine[] = [];
  let total = 0;
  for (const row of rows) {
    const item = one<ItemRow>('SELECT * FROM menu_items WHERE id = ?', row.menu_item_id);
    if (!item) continue;
    lines.push({ item, quantity: row.quantity, unit_price_minor: item.price_minor, total_minor: row.quantity * item.price_minor });
    total += row.quantity * item.price_minor;
  }
  return { lines, total_minor: total };
}

/** The bill as it stands, as restaurant-app's Orders::bill() answers it. */
export function billOf(orderId: string) {
  const { lines, total_minor } = price(orderId);
  return {
    lines: lines.map((line) => ({
      item_id: line.item.id,
      name: line.item.name,
      quantity: line.quantity,
      unit_price_minor: line.unit_price_minor,
      total_minor: line.total_minor,
    })),
    total_minor,
  };
}

export function pay(orderId: string, by: UserRow, method: string, tenderedMinor: number | null, reference: string | null): BillRow {
  return transaction(() => {
    const order = lockOpen(orderId, 'payment_method');
    const unsent = one('SELECT id FROM order_lines WHERE order_id = ? AND kitchen_ticket_id IS NULL AND voided_at IS NULL', orderId);
    if (unsent) throw invalid('payment_method', 'Send the new lines to the kitchen, or take them off, before the bill.');
    const { lines, total_minor: total } = price(orderId);
    if (lines.length === 0) throw invalid('payment_method', 'Nothing to bill: cancel the order instead.');

    const till = openTillOf(by);
    if (!till) throw invalid('payment_method', 'Open your till at this branch before taking a bill.');

    let change = 0;
    if (method === 'cash' && tenderedMinor !== null) {
      if (tenderedMinor < total) throw invalid('amount_tendered', `Less than the bill: ${formatMoney(total)}.`);
      change = tenderedMinor - total;
    }

    const billId = uuid();
    const at = now();
    run(
      `INSERT INTO bills (id, order_id, till_shift_id, number, currency, total_minor, cost_minor, payment_method, tendered_minor, change_minor,
         payment_reference, customer_name, status, paid_by, paid_at)
       VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 'paid', ?, ?)`,
      billId,
      order.id,
      till.id,
      nextNumber('bill', 'B', 6),
      settings().currency,
      total,
      method,
      method === 'cash' ? tenderedMinor : null,
      change,
      reference?.trim() || null,
      order.customer_name,
      by.id,
      at,
    );

    let costTotal = 0;
    let seq = 1;
    for (const line of lines) {
      const cost = line.item.kind === 'drink' ? takeStock(billId, line.item, line.quantity, by) : 0;
      costTotal += cost;
      run(
        'INSERT INTO bill_lines (id, seq, bill_id, menu_item_id, name, quantity, unit_price_minor, total_minor, cost_minor) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        uuid(),
        seq++,
        billId,
        line.item.id,
        line.item.name,
        line.quantity,
        line.unit_price_minor,
        line.total_minor,
        cost,
      );
    }
    run('UPDATE bills SET cost_minor = ? WHERE id = ?', costTotal, billId);
    run("UPDATE orders SET status = 'paid', closed_by = ?, closed_at = ? WHERE id = ?", by.id, at, order.id);

    return one<BillRow>('SELECT * FROM bills WHERE id = ?', billId)!;
  });
}

/** Whether the kitchen has lines of this order that are not voided: cancelling then voids them. */
export function hasLiveTickets(orderId: string): boolean {
  return one('SELECT id FROM order_lines WHERE order_id = ? AND kitchen_ticket_id IS NOT NULL AND voided_at IS NULL', orderId) !== null;
}

export function cancel(orderId: string, by: UserRow, reason: string): OrderRow {
  return transaction(() => {
    const order = lockOpen(orderId, 'reason');
    const at = now();
    run('DELETE FROM order_lines WHERE order_id = ? AND kitchen_ticket_id IS NULL', order.id);
    run('UPDATE order_lines SET voided_by = ?, voided_at = ?, void_reason = ? WHERE order_id = ? AND voided_at IS NULL', by.id, at, reason.trim(), order.id);
    run("UPDATE orders SET status = 'cancelled', cancel_reason = ?, closed_by = ?, closed_at = ? WHERE id = ?", reason.trim(), by.id, at, order.id);
    return order;
  });
}

// The kitchen

export function advance(ticketId: string, from: 'preparing' | 'ready', to: 'ready' | 'served'): TicketRow {
  return transaction(() => {
    const ticket = one<TicketRow>('SELECT * FROM kitchen_tickets WHERE id = ?', ticketId);
    if (!ticket) throw invalid('ticket', 'This ticket is not here.');
    if (ticket.status !== from) throw invalid('ticket', `Ticket ${ticket.number} is already ${ticket.status}.`);
    run(`UPDATE kitchen_tickets SET status = ?, ${to === 'ready' ? 'ready_at' : 'served_at'} = ? WHERE id = ?`, to, now(), ticket.id);
    return ticket;
  });
}

// Bills

export function voidBill(billId: string, by: UserRow, reason: string): BillRow {
  return transaction(() => {
    const bill = one<BillRow>('SELECT * FROM bills WHERE id = ?', billId);
    if (!bill) throw invalid('reason', 'This bill is not here.');
    if (bill.status !== 'paid') throw invalid('reason', `Bill ${bill.number} is already voided.`);

    let refundTill: TillRow | null = null;
    if (bill.payment_method === 'cash' && bill.total_minor > 0) {
      refundTill = openTillOf(by);
      if (!refundTill) throw invalid('reason', 'Open your till at this branch: the cash is given back from it.');
    }

    for (const line of all<{ menu_item_id: string; quantity: number; cost_minor: number }>('SELECT * FROM bill_lines WHERE bill_id = ?', bill.id)) {
      const item = one<ItemRow>('SELECT * FROM menu_items WHERE id = ?', line.menu_item_id);
      if (item?.kind === 'drink') putBack(bill.id, item, line.quantity, Math.floor(line.cost_minor / Math.max(line.quantity, 1)), by);
    }

    run(
      "UPDATE bills SET status = 'voided', voided_by = ?, voided_at = ?, void_reason = ?, refund_till_shift_id = ? WHERE id = ?",
      by.id,
      now(),
      reason.trim(),
      refundTill?.id ?? null,
      bill.id,
    );
    return bill;
  });
}

// Tills

export function openTillOf(user: UserRow): TillRow | null {
  return one<TillRow>("SELECT * FROM till_shifts WHERE opened_by = ? AND status = 'open'", user.id);
}

export function openTill(by: UserRow, floatMinor: number): TillRow {
  return transaction(() => {
    if (openTillOf(by)) throw invalid('opening_float', 'Your till is already open at this branch.');
    const id = uuid();
    run(
      "INSERT INTO till_shifts (id, number, status, opening_float_minor, opened_by, opened_at) VALUES (?, ?, 'open', ?, ?, ?)",
      id,
      nextNumber('till_shift', 'SHIFT', 5),
      floatMinor,
      by.id,
      now(),
    );
    return one<TillRow>('SELECT * FROM till_shifts WHERE id = ?', id)!;
  });
}

/** What went through a till: bills per payment method, cash given back for voids, the cash it should hold. */
export function tillSummary(till: TillRow) {
  // Voided bills count too: the money was taken, and given back as a refund.
  const taken = all<{ payment_method: string; bills: number; total: number }>(
    'SELECT payment_method, COUNT(*) AS bills, SUM(total_minor) AS total FROM bills WHERE till_shift_id = ? GROUP BY payment_method',
    till.id,
  );
  const takenMinor: Record<string, number> = Object.fromEntries(PAYMENT_METHODS.map((m) => [m.value, 0]));
  let billsCount = 0;
  for (const row of taken) {
    takenMinor[row.payment_method] = row.total;
    billsCount += row.bills;
  }
  const refunds = one<{ bills: number; total: number }>(
    "SELECT COUNT(*) AS bills, COALESCE(SUM(total_minor), 0) AS total FROM bills WHERE refund_till_shift_id = ? AND payment_method = 'cash'",
    till.id,
  )!;

  return {
    billsCount,
    takenMinor,
    cashRefundsMinor: refunds.total,
    refundsCount: refunds.bills,
    expectedCashMinor: till.opening_float_minor + (takenMinor.cash ?? 0) - refunds.total,
  };
}

export function closeTill(tillId: string, by: UserRow, countedMinor: number, note: string | null): TillRow {
  return transaction(() => {
    const till = one<TillRow>('SELECT * FROM till_shifts WHERE id = ?', tillId);
    if (!till) throw invalid('counted_cash', 'This shift is not here.');
    if (till.status !== 'open') throw invalid('counted_cash', `Shift ${till.number} is already closed.`);

    const expected = tillSummary(till).expectedCashMinor;
    if (countedMinor !== expected && !note?.trim()) {
      throw invalid('closing_note', 'Say why the count differs from what the drawer should hold.');
    }
    run(
      "UPDATE till_shifts SET status = 'closed', closed_by = ?, closed_at = ?, expected_cash_minor = ?, counted_cash_minor = ?, closing_note = ? WHERE id = ?",
      by.id,
      now(),
      expected,
      countedMinor,
      note?.trim() || null,
      till.id,
    );
    return till;
  });
}

// Drinks stock

interface LevelRow {
  menu_item_id: string;
  quantity: number;
  average_cost_minor: number;
}

function level(itemId: string): LevelRow {
  run('INSERT INTO stock_levels (menu_item_id, quantity, average_cost_minor) VALUES (?, 0, 0) ON CONFLICT(menu_item_id) DO NOTHING', itemId);
  return one<LevelRow>('SELECT * FROM stock_levels WHERE menu_item_id = ?', itemId)!;
}

/** More of a drink at a cost: the average moves towards it. */
function receive(current: LevelRow, quantity: number, unitCostMinor: number) {
  const before = Math.max(current.quantity, 0);
  const average = Math.floor((before * current.average_cost_minor + quantity * unitCostMinor + Math.floor((before + quantity) / 2)) / (before + quantity));
  run('UPDATE stock_levels SET quantity = ?, average_cost_minor = ? WHERE menu_item_id = ?', current.quantity + quantity, average, current.menu_item_id);
}

function record(itemId: string, type: string, quantity: number, unitCostMinor: number, by: UserRow, extra: { reason?: string | null; note?: string | null; billId?: string | null } = {}) {
  run(
    'INSERT INTO stock_movements (id, menu_item_id, type, quantity, unit_cost_minor, reason, note, bill_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
    uuid(),
    itemId,
    type,
    quantity,
    unitCostMinor,
    extra.reason ?? null,
    extra.note?.trim() || null,
    extra.billId ?? null,
    by.id,
    now(),
  );
}

function ensureStocked(item: ItemRow, field: string) {
  if (item.kind !== 'drink') throw invalid(field, `${item.name} is made to order: it keeps no stock.`);
}

/** Drinks already here when the restaurant starts, or found: added at the cost they were bought at. */
export function openingStock(item: ItemRow, quantity: number, unitCostMinor: number, by: UserRow, note: string | null) {
  ensureStocked(item, 'quantity');
  if (quantity < 1) throw invalid('quantity', 'Add at least one.');
  transaction(() => {
    receive(level(item.id), quantity, unitCostMinor);
    record(item.id, 'opening', quantity, unitCostMinor, by, { note });
  });
}

/** A count or a write-off at the average cost: counted corrects either way, the rest only go down. */
export function adjustStock(item: ItemRow, change: number, reason: string, by: UserRow, note: string | null) {
  ensureStocked(item, 'change');
  if (!(reason in STOCK_REASONS)) throw invalid('reason', 'Choose why the stock changed.');
  if (change === 0) throw invalid('change', 'Enter how many more or fewer there are.');
  if (reason !== 'count' && change > 0) throw invalid('change', `${STOCK_REASONS[reason]} drinks only go down: enter a negative number.`);

  transaction(() => {
    const current = level(item.id);
    if (current.quantity + change < 0) throw invalid('change', `Only ${current.quantity} ${item.unit} of ${item.name} on hand.`);
    run('UPDATE stock_levels SET quantity = ? WHERE menu_item_id = ?', current.quantity + change, item.id);
    record(item.id, reason === 'count' ? 'count' : 'write_off', change, current.average_cost_minor, by, { reason, note });
  });
}

/** Takes a bill's drinks, inside the bill's transaction; returns what they cost. */
function takeStock(billId: string, item: ItemRow, quantity: number, by: UserRow): number {
  const current = level(item.id);
  if (current.quantity < quantity) {
    throw invalid('payment_method', `Only ${current.quantity} ${item.unit} of ${item.name} in stock here: count the drinks in before the bill.`);
  }
  run('UPDATE stock_levels SET quantity = ? WHERE menu_item_id = ?', current.quantity - quantity, item.id);
  record(item.id, 'sale', -quantity, current.average_cost_minor, by, { billId });
  return quantity * current.average_cost_minor;
}

/** Puts a voided bill's drinks back at what they cost it. */
function putBack(billId: string, item: ItemRow, quantity: number, unitCostMinor: number, by: UserRow) {
  receive(level(item.id), quantity, unitCostMinor);
  record(item.id, 'bill_void', quantity, unitCostMinor, by, { billId });
}
