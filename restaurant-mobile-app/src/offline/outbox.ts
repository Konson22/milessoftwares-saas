import AsyncStorage from '@react-native-async-storage/async-storage';
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

import { ApiError, request } from '@/lib/api/client';

/**
 * What was done on the floor while the restaurant could not be reached,
 * kept on the phone and sent when it can (restaurant-app
 * OutboxController): opening an order, adding and taking off lines,
 * sending to the kitchen, taking payment. Each keeps its submission id, so
 * one that reached the restaurant before the connection dropped is never
 * recorded twice. Sent oldest first, by the person who did them at that
 * branch only. One the restaurant refuses waits for a person: tried again,
 * or let go; what came after it on the same order waits with it.
 */

export type Kind = 'order.open' | 'order.add' | 'order.remove' | 'order.send' | 'order.pay';

export interface Entry {
  /** The submission id. */
  id: string;
  /** Whose, where (cache scope): only sent from there. */
  owner: string;
  kind: Kind;
  data: Record<string, unknown> & { order_id: string };
  /** For the person: "Table T1 · 3 items added". */
  label: string;
  queued_at: string;
  /** Why the restaurant refused it; null while it waits to be sent. */
  error: string | null;
  attempts: number;
}

export interface Sent {
  id: string;
  owner: string;
  label: string;
  result: Record<string, unknown>;
  sent_at: string;
}

interface OutboxState {
  entries: Entry[];
  /** Recently sent, newest first. */
  sent: Sent[];
  enqueue(entry: Omit<Entry, 'queued_at' | 'error' | 'attempts'>): void;
  /** Let go: it will not be sent. */
  remove(id: string): void;
  /** Refused: try it again (after opening the till, say). */
  retry(id: string): void;
  /** Sends this owner's entries, oldest first, until the connection fails. */
  flush(owner: string): Promise<FlushResult>;
  /** The workspace changed its address: what waits there goes with it. */
  moveWorkspace(from: string, to: string): void;
}

export interface FlushResult {
  sent: number;
  refused: number;
  /** Order ids made offline that the restaurant knows by another (a table already served). */
  renamed: Record<string, string>;
  /** Orders the sent entries touched (their ids at the restaurant). */
  touched: string[];
  /** Stopped for want of a connection (or a sign-in). */
  stopped: boolean;
}

const KEEP_SENT = 50;
let flushing: Promise<FlushResult> | null = null;

/** No connection, the sign-in lapsed, no branch, too many calls, or the restaurant is down: stop and try later. */
const tryLater = (e: unknown) =>
  !(e instanceof ApiError) || e.offline || e.status === 401 || e.status === 409 || e.status === 429 || e.status >= 500;

/** Sends one entry; the restaurant's answer. */
export function send(entry: Pick<Entry, 'id' | 'kind' | 'data' | 'queued_at'>) {
  return request<{ result: Record<string, unknown>; replayed: boolean }>('POST', 'v1/outbox', {
    id: entry.id,
    kind: entry.kind,
    data: entry.data,
    queued_at: entry.queued_at,
  });
}

export const useOutbox = create<OutboxState>()(
  persist(
    (set, get) => ({
      entries: [],
      sent: [],

      enqueue(entry) {
        if (get().entries.some((e) => e.id === entry.id)) return;
        set((s) => ({ entries: [...s.entries, { ...entry, queued_at: new Date().toISOString(), error: null, attempts: 0 }] }));
      },

      remove(id) {
        set((s) => ({ entries: s.entries.filter((e) => e.id !== id) }));
      },

      retry(id) {
        set((s) => ({ entries: s.entries.map((e) => (e.id === id ? { ...e, error: null } : e)) }));
      },

      moveWorkspace(from, to) {
        const move = (owner: string) => (owner.startsWith(`${from}|`) ? `${to}|${owner.slice(from.length + 1)}` : owner);
        set((s) => ({
          entries: s.entries.map((e) => ({ ...e, owner: move(e.owner) })),
          sent: s.sent.map((e) => ({ ...e, owner: move(e.owner) })),
        }));
      },

      flush(owner) {
        // Cleared in a .finally, which runs after the assignment even when there is nothing to send.
        flushing ??= (async (): Promise<FlushResult> => {
          const out: FlushResult = { sent: 0, refused: 0, renamed: {}, touched: [], stopped: false };
          // Orders with a refused entry: what follows on them waits too.
          const held = new Set(get().entries.filter((e) => e.owner === owner && e.error !== null).map((e) => e.data.order_id));

          for (const queued of get().entries.filter((e) => e.owner === owner && e.error === null)) {
            // Re-read: an earlier entry may have renamed its order.
            const entry = get().entries.find((e) => e.id === queued.id);
            if (!entry || held.has(entry.data.order_id)) continue;

            set((s) => ({ entries: s.entries.map((e) => (e.id === entry.id ? { ...e, attempts: e.attempts + 1 } : e)) }));
            try {
              const { result } = await send(entry);
              const orderId = typeof result.order_id === 'string' ? result.order_id : entry.data.order_id;
              if (entry.kind === 'order.open' && orderId !== entry.data.order_id) {
                out.renamed[entry.data.order_id] = orderId;
                renameOrder(set, entry.data.order_id, orderId);
              }
              // The receipt shown on the phone becomes the restaurant's bill.
              if (entry.kind === 'order.pay' && typeof entry.data.local_bill_id === 'string' && typeof result.bill_id === 'string') {
                out.renamed[entry.data.local_bill_id] = result.bill_id;
              }
              if (!out.touched.includes(orderId)) out.touched.push(orderId);
              set((s) => ({
                entries: s.entries.filter((e) => e.id !== entry.id),
                sent: [{ id: entry.id, owner, label: entry.label, result, sent_at: new Date().toISOString() }, ...s.sent.filter((x) => x.id !== entry.id)].slice(
                  0,
                  KEEP_SENT,
                ),
              }));
              out.sent++;
            } catch (e) {
              if (tryLater(e)) {
                out.stopped = true;
                break;
              }
              held.add(entry.data.order_id);
              set((s) => ({ entries: s.entries.map((x) => (x.id === entry.id ? { ...x, error: (e as Error).message } : x)) }));
              out.refused++;
            }
          }
          return out;
        })().finally(() => {
          flushing = null;
        });
        return flushing;
      },
    }),
    {
      name: 'miles-restaurant.outbox',
      version: 1,
      storage: createJSONStorage(() => AsyncStorage),
      partialize: (s) => ({ entries: s.entries, sent: s.sent }),
    },
  ),
);

/** The restaurant knows an order made offline by another id: what waits on it follows. */
function renameOrder(set: (fn: (s: OutboxState) => Partial<OutboxState>) => void, from: string, to: string) {
  set((s) => ({ entries: s.entries.map((e) => (e.data.order_id === from ? { ...e, data: { ...e.data, order_id: to } } : e)) }));
}

/** This owner's queue: waiting to be sent, and refused. */
export function queuedFor(entries: Entry[], owner: string | null) {
  const mine = owner ? entries.filter((e) => e.owner === owner) : [];
  return { waiting: mine.filter((e) => e.error === null), refused: mine.filter((e) => e.error !== null), all: mine };
}

/** Orders (and bills) this phone made that the restaurant does not know yet: their pages are the phone's. */
export function localIds(entries: Entry[], owner: string | null): Set<string> {
  const ids = new Set<string>();
  for (const entry of entries) {
    if (entry.owner !== owner) continue;
    if (entry.kind === 'order.open') ids.add(entry.data.order_id);
    if (entry.kind === 'order.pay' && typeof entry.data.local_bill_id === 'string') ids.add(entry.data.local_bill_id);
  }
  return ids;
}

/** Orders with something waiting to be sent: their pages are the phone's until it is. */
export function pendingOrders(entries: Entry[], owner: string | null): Set<string> {
  return new Set(entries.filter((e) => e.owner === owner).map((e) => e.data.order_id));
}
