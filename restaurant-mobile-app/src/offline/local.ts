import * as Crypto from 'expo-crypto';

import { ApiError } from '@/lib/api/client';
import { money, time, toMinor } from '@/lib/format';
import type { ActionResult, AppPage, Method, SharedProps } from '@/lib/pages';
import { useAuth } from '@/store/auth';

import { cachedKit, cachedPage, changePage, scope, storePage, type Kit } from './cache';
import { send, useOutbox, type Entry, type Kind } from './outbox';

/**
 * The floor's actions done on the phone: an order opened, lines added and
 * taken off, sent to the kitchen, paid. With the restaurant in reach and
 * nothing waiting, they go straight to it (as a submission, so a dropped
 * answer is never applied twice). Otherwise they wait in the outbox, and
 * the floor and the order as this phone keeps them change at once, so work
 * goes on: the restaurant hears of it when the connection is back.
 */

const OFFLINE_NUMBER = 'Not sent yet';
const SAVED = 'Saved on this phone: it goes to the restaurant when the connection is back.';

interface OrderLine {
  id: string;
  name: string;
  kind: 'dish' | 'drink';
  quantity: number;
  note: string | null;
  status: 'new' | 'preparing' | 'ready' | 'served' | 'voided';
  ticket: string | null;
  voidReason: string | null;
  voidedBy: string | null;
  /** Only on lines added on the phone: their menu item. */
  itemId?: string;
}

interface BillLine {
  item_id: string;
  name: string;
  quantity: number;
  unit_price_minor: number;
  total_minor: number;
}

interface OrderProps {
  order: {
    id: string;
    number: string;
    service: 'dine_in' | 'takeaway';
    table: { id: string; name: string } | null;
    guests: number | null;
    customerName: string | null;
    status: 'open' | 'paid' | 'cancelled';
    openedAt: string;
    openedBy: string;
    cancelReason: string | null;
    bill: { id: string; number: string; totalMinor: number; status: string } | null;
    lines: OrderLine[];
  };
  bill: { lines: BillLine[]; total_minor: number };
  menu: Kit['menu'];
  freeTables: { id: string; name: string }[];
  paymentMethods: Kit['paymentMethods'];
  tillOpen: boolean;
  can: { void: boolean; pay: boolean };
}

interface Card {
  id: string;
  number: string;
  guests: number | null;
  customerName: string | null;
  openedAt: string;
  openedBy: string;
  totalMinor: number;
  hasReady: boolean;
}

interface FloorProps {
  tables: { id: string; name: string; area: string | null; seats: number; order: Card | null }[];
  takeaways: Card[];
}

/** Which floor action a web form's call is, and its order. */
function classify(method: Method, path: string): { kind: Kind; orderId: string | null; lineId?: string } | null {
  const parts = path.split('?')[0].split('/');
  if (method === 'POST' && path === 'orders') return { kind: 'order.open', orderId: null };
  if (parts[0] !== 'orders' || !parts[1]) return null;
  if (method === 'POST' && parts[2] === 'lines' && parts.length === 3) return { kind: 'order.add', orderId: parts[1] };
  if (method === 'DELETE' && parts[2] === 'lines' && parts[3] && parts.length === 4) return { kind: 'order.remove', orderId: parts[1], lineId: parts[3] };
  if (method === 'POST' && parts[2] === 'send' && parts.length === 3) return { kind: 'order.send', orderId: parts[1] };
  if (method === 'POST' && parts[2] === 'pay' && parts.length === 3) return { kind: 'order.pay', orderId: parts[1] };
  return null;
}

const uuid = () => Crypto.randomUUID();
const userName = () => useAuth.getState().me?.user.name ?? '';

/**
 * The floor action as a submission, answered by the restaurant or kept on
 * the phone; null for any other action (it goes to the restaurant as a
 * web form would).
 */
export async function floorAction(method: Method, path: string, data: Record<string, any>): Promise<ActionResult | null> {
  const what = classify(method, path);
  const owner = scope();
  if (!what || !owner) return null;

  const order = what.orderId ? await cachedPage<OrderProps>(`orders/${what.orderId}`) : null;
  const where = order ? whereOf(order.props.order) : '';
  const entry = await build(what, data, order, where);
  if (!entry) return null;

  // Straight to the restaurant when it is in reach and nothing waits before this.
  const { entries } = useOutbox.getState();
  const waiting = entries.some((e) => e.owner === owner && e.error === null);
  if (useAuth.getState().reachable && !waiting) {
    try {
      const { result } = await send({ ...entry, queued_at: new Date().toISOString() });
      return answered(entry.kind, entry.data, result);
    } catch (e) {
      // Refused (a till not open, an item gone): the person is right here.
      if (!(e instanceof ApiError) || !(e.offline || e.status >= 500 || e.status === 429)) throw e;
    }
  }

  if (entry.kind === 'order.pay' && !(await canPayHere(order))) {
    throw new ApiError('No connection, and your shift is not open on this phone: start it when the connection is back.', 422, {
      payment_method: ['Start your shift to take the bill.'],
    });
  }
  // Checked and shown on the phone first: what it refuses is never queued.
  const { result, queue } = await applyHere(entry, order);
  if (queue) useOutbox.getState().enqueue({ ...entry, owner });
  return result;
}

/** The submission for an action; null when it cannot be done on the phone. */
async function build(
  what: NonNullable<ReturnType<typeof classify>>,
  data: Record<string, any>,
  order: AppPage<OrderProps> | null,
  where: string,
): Promise<Pick<Entry, 'id' | 'kind' | 'data' | 'label' | 'queued_at'> | null> {
  const base = { id: uuid(), kind: what.kind, queued_at: new Date().toISOString() };
  switch (what.kind) {
    case 'order.open': {
      const floor = await cachedPage<FloorProps>('floor');
      const table = data.service === 'dine_in' ? floor?.props.tables.find((t) => t.id === data.dining_table_id) : null;
      const label = table ? `Table ${table.name}` : `Takeaway${data.customer_name ? ` · ${data.customer_name}` : ''}`;
      return {
        ...base,
        label: `${label} · opened`,
        data: {
          order_id: uuid(),
          service: data.service,
          dining_table_id: data.service === 'dine_in' ? data.dining_table_id : null,
          guests: data.guests === null || data.guests === undefined || data.guests === '' ? null : Number(data.guests),
          customer_name: data.customer_name ?? null,
        },
      };
    }
    case 'order.add': {
      const lines = ((data.lines ?? []) as { item_id: string; quantity: number; note: string | null }[]).map((line) => ({ ...line, id: uuid() }));
      const count = lines.reduce((sum, line) => sum + Number(line.quantity), 0);
      return { ...base, label: `${where} · ${count} added`, data: { order_id: what.orderId!, lines } };
    }
    case 'order.remove': {
      const line = order?.props.order.lines.find((l) => l.id === what.lineId);
      return { ...base, label: `${where} · ${line ? `${line.quantity} × ${line.name}` : 'a line'} taken off`, data: { order_id: what.orderId!, line_id: what.lineId! } };
    }
    case 'order.send':
      return { ...base, label: `${where} · sent to the kitchen`, data: { order_id: what.orderId! } };
    case 'order.pay': {
      const tendered = typeof data.amount_tendered === 'string' && data.amount_tendered !== '' ? toMinor(data.amount_tendered) : null;
      const total = order?.props.bill.total_minor ?? 0;
      return {
        ...base,
        label: `${where} · paid ${money(total)}`,
        data: {
          order_id: what.orderId!,
          payment_method: data.payment_method,
          tendered_minor: tendered,
          payment_reference: data.payment_reference ?? null,
          local_bill_id: uuid(),
        },
      };
    }
  }
}

/** The restaurant's answer, as the web form's would have been. */
function answered(kind: Kind, data: Entry['data'], result: Record<string, any>): ActionResult {
  switch (kind) {
    case 'order.open':
      return { message: null, redirect: `orders/${result.order_id ?? data.order_id}` };
    case 'order.send':
      return { message: result.ticket ? `Sent to the kitchen on ticket ${result.ticket}.` : null, redirect: null };
    case 'order.pay':
      return {
        message: `Bill ${result.bill_number} paid${result.change_minor ? `, change ${money(result.change_minor)}` : ''}.`,
        redirect: null,
      };
    default:
      return { message: null, redirect: null };
  }
}

/** Offline, the cashier's shift as the phone last knew it. */
async function canPayHere(order: AppPage<OrderProps> | null): Promise<boolean> {
  if (order?.props.tillOpen) return true;
  return (await cachedKit())?.tillOpen ?? false;
}

function whereOf(order: OrderProps['order']): string {
  return order.table ? `Table ${order.table.name}` : `Takeaway${order.customerName ? ` · ${order.customerName}` : ''}`;
}

/** The bill as it stands, by item, at the menu's prices (as the restaurant prices it). */
function billOf(lines: OrderLine[], menu: Kit['menu'], current: BillLine[]): { lines: BillLine[]; total_minor: number } {
  const price = (itemId: string) => menu.find((m) => m.id === itemId)?.priceMinor ?? current.find((b) => b.item_id === itemId)?.unit_price_minor ?? 0;
  const itemOf = (line: OrderLine) => line.itemId ?? menu.find((m) => m.name === line.name)?.id ?? current.find((b) => b.name === line.name)?.item_id ?? line.name;
  const byItem = new Map<string, BillLine>();
  for (const line of lines) {
    if (line.status === 'voided') continue;
    const itemId = itemOf(line);
    const unit = price(itemId);
    const was = byItem.get(itemId);
    const quantity = (was?.quantity ?? 0) + line.quantity;
    byItem.set(itemId, { item_id: itemId, name: line.name, quantity, unit_price_minor: unit, total_minor: quantity * unit });
  }
  const billLines = [...byItem.values()];
  return { lines: billLines, total_minor: billLines.reduce((sum, line) => sum + line.total_minor, 0) };
}

/** The floor's card of an order, as it now stands. */
async function updateCard(orderId: string, change: (card: Card) => Card | null): Promise<void> {
  await changePage<FloorProps>('floor', (floor) => ({
    ...floor,
    props: {
      ...floor.props,
      tables: floor.props.tables.map((t) => (t.order?.id === orderId ? { ...t, order: change(t.order) } : t)),
      takeaways: floor.props.takeaways.map((c) => (c.id === orderId ? change(c) : c)).filter((c): c is Card => c !== null),
    },
  }));
}

/** Does it on the phone's pages: the floor and the order show it at once. */
async function applyHere(
  entry: Pick<Entry, 'id' | 'kind' | 'data'>,
  order: AppPage<OrderProps> | null,
): Promise<{ result: ActionResult; queue: boolean }> {
  const data = entry.data as Record<string, any>;
  const orderPath = `orders/${data.order_id}`;

  if (entry.kind === 'order.open') {
    const kit = await cachedKit();
    const floor = await cachedPage<FloorProps>('floor');
    if (!kit || !floor) throw new ApiError('No connection, and this phone has not loaded the floor and menu yet. Try again with the connection.', 0, {}, true);
    const table = data.dining_table_id ? (floor.props.tables.find((t) => t.id === data.dining_table_id) ?? null) : null;
    // A table already being served on this phone: its order.
    if (table?.order) return { result: { message: null, redirect: `orders/${table.order.id}` }, queue: false };
    const now = new Date().toISOString();
    const shared: SharedProps = { access: floor.props.access, branch: floor.props.branch, branchPermissions: floor.props.branchPermissions, currency: floor.props.currency };
    const page: AppPage<OrderProps> = {
      component: 'orders/show',
      url: orderPath,
      props: {
        ...shared,
        order: {
          id: data.order_id,
          number: OFFLINE_NUMBER,
          service: data.service,
          table: table ? { id: table.id, name: table.name } : null,
          guests: data.guests,
          customerName: data.customer_name,
          status: 'open',
          openedAt: now,
          openedBy: userName(),
          cancelReason: null,
          bill: null,
          lines: [],
        },
        bill: { lines: [], total_minor: 0 },
        menu: kit.menu,
        freeTables: [],
        paymentMethods: kit.paymentMethods,
        tillOpen: kit.tillOpen,
        can: { void: kit.can.void, pay: kit.can.pay },
      },
    };
    await storePage(orderPath, page as AppPage);
    const card: Card = { id: data.order_id, number: OFFLINE_NUMBER, guests: data.guests, customerName: data.customer_name, openedAt: now, openedBy: userName(), totalMinor: 0, hasReady: false };
    await changePage<FloorProps>('floor', (f) => ({
      ...f,
      props: {
        ...f.props,
        tables: f.props.tables.map((t) => (t.id === table?.id ? { ...t, order: card } : t)),
        takeaways: table ? f.props.takeaways : [...f.props.takeaways, card],
      },
    }));
    return { result: { message: SAVED, redirect: orderPath }, queue: true };
  }

  if (!order) throw new ApiError('No connection, and this phone has not loaded this order yet.', 0, {}, true);
  const props = order.props;
  let lines = props.order.lines;
  let message: string | null = SAVED;
  let paidBill: OrderProps['order']['bill'] = props.order.bill;
  let status = props.order.status;

  switch (entry.kind) {
    case 'order.add': {
      const added = (data.lines as { id: string; item_id: string; quantity: number; note: string | null }[]).map((line): OrderLine => {
        const item = props.menu.find((m) => m.id === line.item_id);
        return {
          id: line.id,
          name: item?.name ?? 'Item',
          kind: item?.kind ?? 'dish',
          quantity: Number(line.quantity),
          note: line.note ?? null,
          status: 'new',
          ticket: null,
          voidReason: null,
          voidedBy: null,
          itemId: line.item_id,
        };
      });
      lines = [...lines, ...added];
      message = null;
      break;
    }
    case 'order.remove':
      lines = lines.filter((line) => !(line.id === data.line_id && line.status === 'new'));
      message = null;
      break;
    case 'order.send': {
      const ticket = `Offline ${time(new Date().toISOString())}`;
      if (!lines.some((line) => line.status === 'new')) throw new ApiError('Nothing new to send to the kitchen.', 422, { lines: ['Nothing new to send to the kitchen.'] });
      lines = lines.map((line) => (line.status === 'new' ? { ...line, status: 'preparing', ticket } : line));
      message = 'Kept for the kitchen on this phone: the kitchen screen gets it when the connection is back.';
      break;
    }
    case 'order.pay': {
      if (lines.some((line) => line.status === 'new')) {
        throw new ApiError('Send the new lines to the kitchen, or take them off, before the bill.', 422, { payment_method: ['Send the new lines first.'] });
      }
      const total = props.bill.total_minor;
      const tendered = data.tendered_minor as number | null;
      if (data.payment_method === 'cash' && tendered !== null && tendered < total) {
        throw new ApiError(`Less than the bill: ${money(total)}.`, 422, { amount_tendered: [`Less than the bill: ${money(total)}.`] });
      }
      const billId = data.local_bill_id as string;
      paidBill = { id: billId, number: OFFLINE_NUMBER, totalMinor: total, status: 'paid' };
      status = 'paid';
      const method = props.paymentMethods.find((m) => m.value === data.payment_method)?.label ?? data.payment_method;
      await storePage(`bills/${billId}`, {
        component: 'bills/show',
        url: `bills/${billId}`,
        props: {
          access: props.access,
          branch: props.branch,
          branchPermissions: props.branchPermissions,
          currency: props.currency,
          bill: {
            id: billId,
            number: OFFLINE_NUMBER,
            status: 'paid',
            paidAt: new Date().toISOString(),
            paidBy: userName(),
            order: { id: props.order.id, number: props.order.number, table: props.order.table?.name ?? null },
            customerName: props.order.customerName,
            totalMinor: total,
            paymentMethod: method,
            paymentReference: data.payment_reference,
            tenderedMinor: data.payment_method === 'cash' ? tendered : null,
            changeMinor: data.payment_method === 'cash' && tendered !== null ? tendered - total : null,
            voidedAt: null,
            voidedBy: null,
            voidReason: null,
            lines: props.bill.lines.map((line) => ({ id: line.item_id, name: line.name, quantity: line.quantity, unitPriceMinor: line.unit_price_minor, totalMinor: line.total_minor })),
          },
          canVoid: false,
        },
      });
      const change = data.payment_method === 'cash' && tendered !== null ? tendered - total : 0;
      message = `Paid on this phone${change ? `, change ${money(change)}` : ''}: the bill goes to the restaurant when the connection is back.`;
      break;
    }
  }

  const bill = billOf(lines, props.menu, props.bill.lines);
  await storePage(order.url, { ...order, props: { ...props, order: { ...props.order, lines, status, bill: paidBill }, bill } } as AppPage);
  await updateCard(props.order.id, (card) => (status === 'paid' ? null : { ...card, totalMinor: bill.total_minor }));
  return { result: { message, redirect: null }, queue: true };
}
