import AsyncStorage from '@react-native-async-storage/async-storage';
import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

import { ApiError, request } from '@/lib/api/client';

/**
 * What was done while the station could not be reached, kept on the phone
 * and sent when it can (petrol-app Api\OutboxController): a fuel sale, cash
 * collected from a customer, an expense. Each keeps its submission id, so
 * one that reached the station before the connection dropped is never
 * recorded twice. Sent oldest first, by the person who did it at that
 * branch only. One the station refuses (the shift closed, a price missing)
 * waits for a person: tried again, or let go.
 */

export type Kind = 'sale.record' | 'cash.collect' | 'expense.record' | 'fuel.receive' | 'shift.open' | 'cash.remit';

export interface Entry {
  /** The submission id. */
  id: string;
  /** Whose, where (cache scope): only sent from there. */
  owner: string;
  kind: Kind;
  payload: Record<string, unknown>;
  /** For the person: "P1 · 20 L · UGX 100,000". */
  label: string;
  queued_at: string;
  /** Why the station refused it; null while it waits to be sent. */
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
  /** Refused: try it again (after opening the shift, say). */
  retry(id: string): void;
  /** Sends this owner's entries, oldest first, until the connection fails. */
  flush(owner: string): Promise<FlushResult>;
  /** The workspace changed its address: what waits there goes with it. */
  moveWorkspace(from: string, to: string): void;
}

export interface FlushResult {
  sent: number;
  refused: number;
  /** Stopped for want of a connection (or a sign-in). */
  stopped: boolean;
}

const KEEP_SENT = 50;
let flushing: Promise<FlushResult> | null = null;

/**
 * No connection, the sign-in lapsed, no branch chosen, too many calls, or
 * the station is down: stop and try later. Anything else is the station's
 * answer to this entry.
 */
const tryLater = (e: unknown) =>
  !(e instanceof ApiError) ||
  e.offline ||
  e.status === 401 ||
  (e.status === 409 && e.code === 'branch_required') ||
  e.status === 429 ||
  e.status >= 500;

/** Sends one entry; the station answers with what it recorded. */
export function send(entry: Pick<Entry, 'id' | 'kind' | 'payload'>) {
  return request<Record<string, unknown>>('POST', 'v1/outbox', { id: entry.id, kind: entry.kind, payload: entry.payload });
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
          const out: FlushResult = { sent: 0, refused: 0, stopped: false };

          for (const entry of get().entries.filter((e) => e.owner === owner && e.error === null)) {
            set((s) => ({ entries: s.entries.map((e) => (e.id === entry.id ? { ...e, attempts: e.attempts + 1 } : e)) }));
            try {
              const result = await send(entry);
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
      name: 'miles-petrol.outbox',
      version: 1,
      storage: createJSONStorage(() => AsyncStorage),
      partialize: (s) => ({ entries: s.entries, sent: s.sent }),
    },
  ),
);

/** This owner's queue: waiting to be sent, and refused. */
export function queuedFor(entries: Entry[], owner: string | null) {
  const mine = owner ? entries.filter((e) => e.owner === owner) : [];
  return { waiting: mine.filter((e) => e.error === null), refused: mine.filter((e) => e.error !== null), all: mine };
}
