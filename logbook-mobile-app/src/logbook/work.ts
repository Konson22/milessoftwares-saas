import type { NoticeRoute } from './notices';
import type { TransportError } from './sync';
import { backoff, classify } from './sync';
import type { LogbookStore } from './types';

/*
 * Tasks on the phone (ADR-0026 §2.4). The task list is fetched online and
 * kept (encrypted, per workspace and person) to read offline. Starting and
 * completing work go through a small outbox: each action carries the time
 * it was done and, for completion, the phone's key, so a resend after a
 * lost reply is the same completion. A task changed meanwhile (given to
 * someone else, cancelled, already finished) is refused with 409 and the
 * action is kept, marked, for the person to see. Evidence is uploaded
 * online only.
 */

export type WorkStatus = 'pending' | 'in_progress' | 'awaiting_verification' | 'returned' | 'completed' | 'cancelled';

export type Task = {
  id: string;
  reference: string;
  title: string;
  description: string | null;
  priority: 'low' | 'normal' | 'high' | 'urgent';
  status: WorkStatus;
  overdue: boolean;
  assignee: { id: string; name: string };
  creator: string;
  logbook: string | null;
  sheet: { id: string; name: string; code: string } | null;
  resource: string | null;
  startsAt: string | null;
  dueAt: string | null;
  completedAt: string | null;
  verificationRequired: boolean;
  evidenceRequired: boolean;
  revision: number;
  files: { id: string; kind: 'brief' | 'evidence'; name: string; size: number; mime: string | null }[];
  completion: { notes: string | null; returnComment: string | null };
  can: { start: boolean; complete: boolean };
};

export type Occurrence = {
  id: string;
  state: string;
  /** Absent from servers before ADR-0027 rows carried it. */
  opensAt?: string;
  dueAt: string;
  closesAt: string;
  sheet: { id: string; name: string; code: string };
  logbook: string;
};

export type Notice = {
  id: string;
  category: 'task' | 'entry' | 'schedule' | 'exception';
  event: string;
  title: string;
  body: string | null;
  link: string;
  /** Where it opens on the phone (ADR-0027); null when nowhere. */
  route?: NoticeRoute | null;
  read: boolean;
  at: string;
};

export type Action = {
  id: string;
  taskId: string;
  kind: 'start' | 'complete';
  /** When it was done on the phone. */
  at: string;
  notes?: string | null;
  attempts: number;
  nextAttemptAt: string | null;
  /** Set when the server refused it for good: shown, then dismissed. */
  refused: { code: string | null; message: string } | null;
};

export interface WorkTransport {
  tasks(): Promise<{ serverTime: string; tasks: Task[] }>;
  start(taskId: string): Promise<Task>;
  complete(taskId: string, body: { key: string; notes: string | null; completed_at: string }): Promise<Task>;
}

const OUTBOX = 'work.outbox';
const TASKS = 'work.tasks';

async function read<T>(store: LogbookStore, scope: string, key: string, fallback: T): Promise<T> {
  const raw = await store.meta(scope, key);
  if (!raw) return fallback;
  try {
    return JSON.parse(raw) as T;
  } catch {
    return fallback;
  }
}

export const outbox = (store: LogbookStore, scope: string) => read<Action[]>(store, scope, OUTBOX, []);
export const cachedTasks = (store: LogbookStore, scope: string) => read<Task[]>(store, scope, TASKS, []);
const saveOutbox = (store: LogbookStore, scope: string, actions: Action[]) => store.setMeta(scope, OUTBOX, JSON.stringify(actions));

/** Queues an action; a second identical one (a double tap) is not added. */
export async function queue(store: LogbookStore, scope: string, action: Pick<Action, 'id' | 'taskId' | 'kind' | 'notes'>, now: Date): Promise<Action[]> {
  const actions = await outbox(store, scope);
  if (actions.some((a) => a.taskId === action.taskId && a.kind === action.kind && a.refused === null)) return actions;
  const next = [...actions, { ...action, at: now.toISOString(), attempts: 0, nextAttemptAt: null, refused: null }];
  await saveOutbox(store, scope, next);
  return next;
}

export async function dismiss(store: LogbookStore, scope: string, id: string): Promise<Action[]> {
  const next = (await outbox(store, scope)).filter((a) => a.id !== id);
  await saveOutbox(store, scope, next);
  return next;
}

/**
 * Sends what is due, oldest first. Offline or a server error: kept, with a
 * backoff. Refused (409, 404, 422): kept and marked. Signed out: stops.
 */
export async function flush(
  store: LogbookStore,
  transport: WorkTransport,
  scope: string,
  now: () => Date = () => new Date(),
  random?: () => number,
): Promise<{ sent: number; refused: number; signIn: boolean }> {
  let actions = await outbox(store, scope);
  const result = { sent: 0, refused: 0, signIn: false };
  for (const action of [...actions]) {
    if (action.refused !== null || (action.nextAttemptAt !== null && new Date(action.nextAttemptAt) > now())) continue;
    let update: Action | null;
    try {
      if (action.kind === 'start') await transport.start(action.taskId);
      else await transport.complete(action.taskId, { key: action.id, notes: action.notes ?? null, completed_at: action.at });
      update = null;
      result.sent++;
    } catch (error) {
      const kind = classify(error);
      if (kind === 'signIn') {
        result.signIn = true;
        break;
      }
      const attempts = action.attempts + 1;
      if (kind === 'action') {
        const e = error as TransportError;
        update = { ...action, attempts, refused: { code: e.code ?? null, message: e.message } };
        result.refused++;
      } else {
        update = { ...action, attempts, nextAttemptAt: new Date(now().getTime() + backoff(attempts, random)).toISOString() };
      }
    }
    actions = actions.flatMap((a) => (a.id !== action.id ? [a] : update ? [update] : []));
    await saveOutbox(store, scope, actions);
  }
  return result;
}

/** Fetches the task list and keeps it to read offline. */
export async function refresh(store: LogbookStore, transport: WorkTransport, scope: string): Promise<Task[]> {
  const { tasks } = await transport.tasks();
  await store.setMeta(scope, TASKS, JSON.stringify(tasks));
  return tasks;
}

/**
 * The tasks as the person should see them: the server's state with what
 * the phone still has to send laid over it.
 */
export function overlay(tasks: Task[], actions: Action[]): (Task & { local: 'starting' | 'completing' | 'refused' | null; localMessage: string | null })[] {
  return tasks.map((task) => {
    const mine = actions.filter((a) => a.taskId === task.id);
    const refused = mine.find((a) => a.refused !== null);
    if (refused) return { ...task, local: 'refused', localMessage: refused.refused!.message };
    if (mine.some((a) => a.kind === 'complete')) {
      return { ...task, status: task.verificationRequired ? 'awaiting_verification' : 'completed', can: { start: false, complete: false }, local: 'completing', localMessage: null };
    }
    if (mine.some((a) => a.kind === 'start')) {
      return { ...task, status: 'in_progress', can: { ...task.can, start: false }, local: 'starting', localMessage: null };
    }
    return { ...task, local: null, localMessage: null };
  });
}

const OPEN: WorkStatus[] = ['pending', 'in_progress', 'returned'];

/** Open work first, the next due first; then what waits on someone else; then done. */
export function sortTasks<T extends Task>(tasks: T[]): T[] {
  const rank = (t: T) => (OPEN.includes(t.status) ? 0 : t.status === 'awaiting_verification' ? 1 : 2);
  const due = (t: T) => (t.dueAt ? new Date(t.dueAt).getTime() : Number.MAX_SAFE_INTEGER);
  return [...tasks].sort((a, b) => rank(a) - rank(b) || due(a) - due(b));
}

export const isOpen = (task: Task) => OPEN.includes(task.status);
