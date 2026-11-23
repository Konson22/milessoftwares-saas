import { create } from 'zustand';

import { operations, reviews, work } from '@/logbook/transport';
import { uuidv7 } from '@/logbook/uuid';
import * as Work from '@/logbook/work';
import type { Action, Occurrence, Task } from '@/logbook/work';
import * as Crypto from 'expo-crypto';

import { useAuth } from './auth';
import { store, useLogbook } from './logbook';

/*
 * Tasks and due inspections for the screens. Tasks and the outbox live in
 * the encrypted store (logbook/work.ts); due inspections and notifications
 * are fetched online and only held while the app runs.
 */

type Shown = ReturnType<typeof Work.overlay>[number];

interface WorkState {
  tasks: Shown[];
  actions: Action[];
  due: Occurrence[];
  /** Expected entries of the last week that nobody wrote. */
  missed: Occurrence[];
  /** Entries waiting on this person's review; null without the role. */
  toReview: number | null;
  unread: number;
  loading: boolean;
  error: string | null;
  lastAt: string | null;

  reload(): Promise<void>;
  /** Sends what waits, then fetches tasks and due inspections. */
  refresh(): Promise<void>;
  flush(): Promise<void>;
  start(task: Task): Promise<void>;
  complete(task: Task, notes: string | null): Promise<void>;
  dismiss(actionId: string): Promise<void>;
}

const scope = () => useLogbook.getState().scope;
let flushing: Promise<void> | null = null;

export const useWork = create<WorkState>((set, get) => ({
  tasks: [],
  actions: [],
  due: [],
  missed: [],
  toReview: null,
  unread: 0,
  loading: false,
  error: null,
  lastAt: null,

  async reload() {
    const s = scope();
    if (!s) return set({ tasks: [], actions: [], due: [], missed: [], toReview: null, unread: 0 });
    const [tasks, actions, lastAt] = await Promise.all([Work.cachedTasks(store, s), Work.outbox(store, s), store.meta(s, 'work.at')]);
    if (scope() === s) set({ tasks: Work.sortTasks(Work.overlay(tasks, actions)), actions, lastAt });
  },

  async refresh() {
    const s = scope();
    if (!s) return;
    set({ loading: true });
    try {
      await get().flush();
      await Work.refresh(store, work, s);
      const at = new Date().toISOString();
      await store.setMeta(s, 'work.at', at);
      const canReview = useAuth.getState().me?.can.review === true;
      const [due, notices, waiting] = await Promise.all([
        operations.due().catch(() => null),
        operations.notifications().catch(() => null),
        canReview ? reviews.list().catch(() => null) : Promise.resolve(null),
      ]);
      if (scope() === s) set({ due: due?.occurrences ?? get().due, missed: due?.missed ?? get().missed, toReview: canReview ? (waiting?.entries.length ?? get().toReview) : null, unread: notices?.unread ?? get().unread, error: null });
    } catch (error) {
      // Offline: the tasks last fetched stay readable, and work done is kept to send.
      if (scope() === s) set({ error: error instanceof Error ? error.message : 'Could not load the tasks.' });
    } finally {
      set({ loading: false });
      await get().reload();
    }
  },

  flush() {
    flushing ??= (async () => {
      const s = scope();
      if (!s) return;
      const pending = (await Work.outbox(store, s)).some((a) => a.refused === null);
      if (pending) await Work.flush(store, work, s);
      await get().reload();
    })().finally(() => {
      flushing = null;
    });
    return flushing;
  },

  async start(task) {
    const s = scope();
    if (!s) return;
    await Work.queue(store, s, { id: uuidv7(Date.now(), (n) => Crypto.getRandomBytes(n)), taskId: task.id, kind: 'start', notes: null }, new Date());
    await get().reload();
    void get().flush();
  },

  async complete(task, notes) {
    const s = scope();
    if (!s) return;
    await Work.queue(store, s, { id: uuidv7(Date.now(), (n) => Crypto.getRandomBytes(n)), taskId: task.id, kind: 'complete', notes }, new Date());
    await get().reload();
    void get().flush();
  },

  async dismiss(actionId) {
    const s = scope();
    if (!s) return;
    await Work.dismiss(store, s, actionId);
    await get().refresh();
  },
}));
