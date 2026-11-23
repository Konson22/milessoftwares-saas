/// <reference types="jest" />
import { MemoryStore } from './memory-store';
import { TransportError } from './sync';
import { cachedTasks, dismiss, flush, outbox, overlay, queue, refresh, sortTasks, type Task, type WorkTransport } from './work';

/*
 * The task outbox against a fake server that behaves like logbook-app's
 * WorkController: completion keyed by the phone's id (a repeat is the same
 * completion), 409 once the task is not the person's to do, replies that
 * can be lost after the server acted.
 */

const SCOPE = 'acme|u1';
const T0 = new Date('2026-11-20T08:00:00Z');

const task = (id: string, extra: Partial<Task> = {}): Task => ({
  id,
  reference: `TASK-2026-00000${id}`,
  title: `Task ${id}`,
  description: null,
  priority: 'normal',
  status: 'pending',
  overdue: false,
  assignee: { id: 'u1', name: 'Ada' },
  creator: 'Manager',
  logbook: null,
  sheet: null,
  resource: null,
  startsAt: null,
  dueAt: null,
  completedAt: null,
  verificationRequired: false,
  evidenceRequired: false,
  revision: 1,
  files: [],
  completion: { notes: null, returnComment: null },
  can: { start: true, complete: true },
  ...extra,
});

class FakeServer implements WorkTransport {
  tasksById = new Map<string, Task & { key?: string; completedAtSent?: string }>();
  online = true;
  loseNextReply = false;
  completions = 0;

  constructor(tasks: Task[]) {
    for (const t of tasks) this.tasksById.set(t.id, { ...t });
  }

  private gate() {
    if (!this.online) throw new TransportError('offline', 'Network request failed');
  }
  private reply<T>(value: T): T {
    if (this.loseNextReply) {
      this.loseNextReply = false;
      throw new TransportError('timeout', 'The request timed out');
    }
    return value;
  }

  async tasks() {
    this.gate();
    return { serverTime: T0.toISOString(), tasks: [...this.tasksById.values()] };
  }
  async start(id: string) {
    this.gate();
    const t = this.tasksById.get(id)!;
    if (t.assignee.id !== 'u1') throw new TransportError('http', 'This task was given to someone else.', 409, 'not_assignee');
    if (t.status === 'pending' || t.status === 'returned') t.status = 'in_progress';
    return this.reply({ ...t });
  }
  async complete(id: string, body: { key: string; notes: string | null; completed_at: string }) {
    this.gate();
    const t = this.tasksById.get(id)!;
    if (t.key === body.key) return this.reply({ ...t });
    if (t.assignee.id !== 'u1') throw new TransportError('http', 'This task was given to someone else.', 409, 'not_assignee');
    if (!['pending', 'in_progress', 'returned'].includes(t.status)) throw new TransportError('http', `This task is ${t.status}.`, 409, 'not_open');
    t.status = t.verificationRequired ? 'awaiting_verification' : 'completed';
    t.key = body.key;
    t.completedAtSent = body.completed_at;
    this.completions++;
    return this.reply({ ...t });
  }
}

describe('tasks on the phone', () => {
  it('keeps work done offline, then sends it with the time it was done', async () => {
    const store = new MemoryStore();
    const server = new FakeServer([task('1')]);
    await refresh(store, server, SCOPE);
    server.online = false;

    await queue(store, SCOPE, { id: 'k1', taskId: '1', kind: 'complete', notes: 'Oil topped up' }, T0);
    // The list read offline shows it done.
    const shown = overlay(await cachedTasks(store, SCOPE), await outbox(store, SCOPE));
    expect(shown[0]).toMatchObject({ status: 'completed', local: 'completing' });

    let result = await flush(store, server, SCOPE, () => T0);
    expect(result.sent).toBe(0);
    expect((await outbox(store, SCOPE))[0].nextAttemptAt).not.toBeNull();

    server.online = true;
    result = await flush(store, server, SCOPE, () => new Date(T0.getTime() + 60 * 60_000), () => 0.5);
    expect(result.sent).toBe(1);
    expect(await outbox(store, SCOPE)).toEqual([]);
    expect(server.tasksById.get('1')!.completedAtSent).toBe(T0.toISOString());
  });

  it('completes once when the reply is lost and the phone sends again', async () => {
    const store = new MemoryStore();
    const server = new FakeServer([task('1')]);
    await queue(store, SCOPE, { id: 'k1', taskId: '1', kind: 'complete', notes: null }, T0);
    server.loseNextReply = true;

    await flush(store, server, SCOPE, () => T0, () => 0.5);
    expect(await outbox(store, SCOPE)).toHaveLength(1);
    await flush(store, server, SCOPE, () => new Date(T0.getTime() + 3600_000), () => 0.5);

    expect(await outbox(store, SCOPE)).toEqual([]);
    expect(server.completions).toBe(1);
  });

  it('marks work refused when the task was given to someone else, until dismissed', async () => {
    const store = new MemoryStore();
    const server = new FakeServer([task('1')]);
    await refresh(store, server, SCOPE);
    await queue(store, SCOPE, { id: 'k1', taskId: '1', kind: 'complete', notes: null }, T0);
    server.tasksById.get('1')!.assignee = { id: 'u2', name: 'Bo' };

    const result = await flush(store, server, SCOPE, () => T0);
    expect(result.refused).toBe(1);
    const [action] = await outbox(store, SCOPE);
    expect(action.refused).toEqual({ code: 'not_assignee', message: 'This task was given to someone else.' });
    expect(overlay(await cachedTasks(store, SCOPE), [action])[0].local).toBe('refused');

    // Not sent again; gone once dismissed.
    await flush(store, server, SCOPE, () => new Date(T0.getTime() + 3600_000));
    expect(await dismiss(store, SCOPE, 'k1')).toEqual([]);
  });

  it('ignores a double tap, and stops on sign-out', async () => {
    const store = new MemoryStore();
    await queue(store, SCOPE, { id: 'k1', taskId: '1', kind: 'start', notes: null }, T0);
    await queue(store, SCOPE, { id: 'k2', taskId: '1', kind: 'start', notes: null }, T0);
    expect(await outbox(store, SCOPE)).toHaveLength(1);

    const signedOut: WorkTransport = {
      tasks: async () => ({ serverTime: '', tasks: [] }),
      start: async () => {
        throw new TransportError('http', 'Unauthenticated.', 401);
      },
      complete: async () => {
        throw new Error('not called');
      },
    };
    expect((await flush(store, signedOut, SCOPE, () => T0)).signIn).toBe(true);
    expect(await outbox(store, SCOPE)).toHaveLength(1);
  });

  it('keeps another person on the same phone apart', async () => {
    const store = new MemoryStore();
    await queue(store, SCOPE, { id: 'k1', taskId: '1', kind: 'start', notes: null }, T0);
    expect(await outbox(store, 'acme|u2')).toEqual([]);
  });

  it('lists open work first, the next due first', () => {
    const sorted = sortTasks([
      task('1', { status: 'completed' }),
      task('2', { dueAt: '2026-11-21T09:00:00Z' }),
      task('3', { status: 'awaiting_verification' }),
      task('4', { dueAt: '2026-11-20T09:00:00Z', status: 'in_progress' }),
    ]);
    expect(sorted.map((t) => t.id)).toEqual(['4', '2', '3', '1']);
  });
});
