import { create } from 'zustand';
import { persist } from 'zustand/middleware';

import { api, ApiError } from '@/lib/api';
import { diskStorage } from '@/lib/storage';
import type { CartLine, CashCounterpart, CashDirection, PaymentMethod, SalePayload } from '@/lib/types';
import { unitsOf } from '@/store/cart';

/**
 * What was done at the counter while the pharmacy could not be reached, kept
 * on this PC and sent when it can: sales (the customer paid and left) and
 * cash in and out of the drawer. Each keeps its submission id, so one that
 * did reach the pharmacy before the connection dropped is never recorded
 * twice. Sent oldest first, from the same person at the same branch only.
 */

interface Base {
  /** The submission id. */
  id: string;
  /** Whose, at which branch (holdOwner): only sent from there. */
  owner: string;
  queued_at: string;
  /** Why the pharmacy refused it; null while it waits to be sent. */
  error: string | null;
  attempts: number;
}

export interface QueuedSale extends Base {
  kind: 'sale';
  payload: SalePayload;
  /** The cart it came from: for the receipt, and to put back if refused. */
  lines: CartLine[];
  total_minor: number;
  discount_minor: number;
  payment_method: PaymentMethod;
  payment_label: string;
  sold_by: string;
}

export interface QueuedCash extends Base {
  kind: 'cash';
  direction: CashDirection;
  counterpart: CashCounterpart;
  amount_minor: number;
  reason: string;
}

export type QueuedEntry = QueuedSale | QueuedCash;

export interface SentEntry {
  id: string;
  owner: string;
  kind: QueuedEntry['kind'];
  /** The sale's receipt number, once the pharmacy recorded it. */
  number: string | null;
  sale_id: string | null;
  total_minor: number;
  queued_at: string;
  sent_at: string;
}

type New<T extends QueuedEntry> = Omit<T, 'queued_at' | 'error' | 'attempts'>;

interface OutboxState {
  entries: QueuedEntry[];
  /** Recently sent, newest first: what went through after the connection came back. */
  sent: SentEntry[];
  enqueue(entry: New<QueuedSale> | New<QueuedCash>): void;
  remove(id: string): void;
  /** A refused one waits to be sent again (after opening a shift, say). */
  retry(id: string): void;
  /** Sends this owner's waiting entries, oldest first, until the connection fails. */
  flush(owner: string): Promise<{ sent: number; refused: number }>;
}

const KEEP_SENT = 50;
let flushing: Promise<{ sent: number; refused: number }> | null = null;

/** Still no connection, the sign-in lapsed, or the pharmacy is down: stop, and try again later. */
const tryLater = (e: unknown) =>
  !(e instanceof ApiError) || e.offline || e.status === 401 || e.status === 409 || e.status === 429 || e.status >= 500;

export const useOutbox = create<OutboxState>()(
  persist(
    (set, get) => ({
      entries: [],
      sent: [],

      enqueue(entry) {
        if (get().entries.some((e) => e.id === entry.id)) return;
        set((s) => ({ entries: [...s.entries, { ...entry, queued_at: new Date().toISOString(), error: null, attempts: 0 } as QueuedEntry] }));
      },

      remove(id) {
        set((s) => ({ entries: s.entries.filter((e) => e.id !== id) }));
      },

      retry(id) {
        set((s) => ({ entries: s.entries.map((e) => (e.id === id ? { ...e, error: null } : e)) }));
      },

      flush(owner) {
        // Cleared in a .finally, which always runs after the assignment: a run
        // with nothing to send finishes synchronously and would otherwise
        // leave its settled promise here for good.
        flushing ??= (async () => {
          let sent = 0;
          let refused = 0;
          for (const entry of get().entries.filter((e) => e.owner === owner && e.error === null)) {
            set((s) => ({ entries: s.entries.map((e) => (e.id === entry.id ? { ...e, attempts: e.attempts + 1 } : e)) }));
            try {
              const record = await send(entry);
              set((s) => ({
                entries: s.entries.filter((e) => e.id !== entry.id),
                sent: [record, ...s.sent.filter((x) => x.id !== entry.id)].slice(0, KEEP_SENT),
              }));
              sent++;
            } catch (e) {
              if (tryLater(e)) break;
              // Refused (stock gone, the shift closed, a discount): it needs a person.
              set((s) => ({ entries: s.entries.map((x) => (x.id === entry.id ? { ...x, error: (e as Error).message } : x)) }));
              refused++;
            }
          }
          return { sent, refused };
        })().finally(() => {
          flushing = null;
        });
        return flushing;
      },
    }),
    {
      name: 'outbox',
      version: 1,
      storage: diskStorage,
      partialize: (s) => ({ entries: s.entries, sent: s.sent }),
    },
  ),
);

async function send(entry: QueuedEntry): Promise<SentEntry> {
  const base = { id: entry.id, owner: entry.owner, kind: entry.kind, queued_at: entry.queued_at, sent_at: new Date().toISOString() };
  if (entry.kind === 'sale') {
    const { sale } = await api.sell(entry.payload);
    return { ...base, number: sale.number, sale_id: sale.id, total_minor: sale.total_minor };
  }
  await api.moveCash(entry.direction, entry.counterpart, entry.amount_minor, entry.reason, entry.id);
  return { ...base, number: null, sale_id: null, total_minor: entry.amount_minor };
}

/** This owner's queue: waiting, and refused. */
export function queuedFor(entries: QueuedEntry[], owner: string) {
  const mine = entries.filter((e) => e.owner === owner);
  return { waiting: mine.filter((e) => e.error === null), refused: mine.filter((e) => e.error !== null), all: mine };
}

/** Units of each item sold here but not yet known to the pharmacy: what the catalog's stock does not show yet. */
export function pendingUnits(entries: QueuedEntry[], owner: string): Record<string, number> {
  const units: Record<string, number> = {};
  for (const entry of entries) {
    if (entry.kind !== 'sale' || entry.owner !== owner) continue;
    for (const line of entry.lines) units[line.item.id] = (units[line.item.id] ?? 0) + unitsOf(line);
  }
  return units;
}
