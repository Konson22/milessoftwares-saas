/// <reference types="jest" />
import type { Definition } from './definition';
import { MemoryStore } from './memory-store';
import { buckets, filterTasks, newerVersion, pagesOf, progressOf, syncSummary, taskRows, verificationOf, visiblePages } from './overview';
import { evaluate } from './rules';
import { anythingDue, retryNow } from './sync';
import type { Assignment, Item, Kit } from './types';

/*
 * What the tabs show: upload and review kept apart, tasks from
 * assignments, pages and progress of a long form, retrying on demand.
 */

const SCOPE = 'acme|user-1';

function item(over: Partial<Item> = {}): Item {
  return {
    id: over.id ?? Math.random().toString(36).slice(2),
    scope: SCOPE,
    formId: 'f1',
    formName: 'Household',
    formVersionId: 'v1',
    unitId: null,
    answers: {},
    consentGiven: null,
    startedAt: '2026-11-17T08:00:00Z',
    completedAt: null,
    status: 'draft',
    attempts: 0,
    nextAttemptAt: null,
    error: null,
    photos: {},
    serverStatus: null,
    serverRevision: 0,
    correction: null,
    pendingCorrection: null,
    syncedAt: null,
    updatedAt: '2026-11-17T08:00:00Z',
    ...over,
  };
}

function assignment(over: Partial<Assignment> = {}, form: Partial<Assignment['form']> = {}): Assignment {
  return {
    id: over.id ?? 'a1',
    status: 'active',
    canStart: true,
    startsOn: null,
    endsOn: null,
    closesAt: null,
    unit: null,
    version: { id: 'v1', number: 1, sha256: 'h1', schemaVersion: 1 },
    ...over,
    form: { id: 'f1', name: 'Household', description: null, purpose: 'survey', status: 'published', programme: 'WASH', containsPersonalData: false, ...form },
  };
}

const kit = (assignments: Assignment[]): Kit => ({ serverTime: '2026-11-17T08:00:00Z', settings: { photoMaxKb: 800, keepOnDeviceDays: 30 }, assignments });

describe('upload and review are separate', () => {
  it('never reads an upload as approval', () => {
    expect(verificationOf(item({ status: 'synced', serverStatus: 'submitted' }))).toBe('pending');
    expect(verificationOf(item({ status: 'synced', serverStatus: 'under_review' }))).toBe('pending');
    expect(verificationOf(item({ status: 'synced', serverStatus: 'accepted' }))).toBe('approved');
    expect(verificationOf(item({ status: 'synced', serverStatus: 'rejected' }))).toBe('rejected');
    expect(verificationOf(item({ status: 'action_required', serverStatus: 'correction_requested', correction: { comment: 'x', notes: {}, baseRevision: 1 } }))).toBe('correction');
    expect(verificationOf(item({ status: 'ready' }))).toBe('none');
  });

  it('counts the sync tab without mixing the two', () => {
    const items = [
      item({ status: 'ready' }),
      item({ status: 'failed' }),
      item({ status: 'action_required', error: { code: 'assignment_revoked', message: '' } }),
      item({ status: 'synced', serverStatus: 'submitted' }),
      item({ status: 'synced', serverStatus: 'accepted' }),
      item({ status: 'action_required', serverStatus: 'correction_requested', correction: { comment: null, notes: {}, baseRevision: 1 } }),
    ];
    const s = syncSummary(items, kit([assignment()]), new Set(['v1']));
    expect(s).toMatchObject({ pending: 1, failed: 1, refused: 1, uploaded: 2, formsOnPhone: 1, formsAssigned: 1 });
    expect(s.review).toEqual({ pending: 1, approved: 1, rejected: 0, correction: 1 });
    expect(buckets(items).corrections).toHaveLength(1);
  });
});

describe('tasks from assignments', () => {
  const today = '2026-11-17';

  it('derives the state, the days left and the work done', () => {
    const rows = taskRows(
      kit([
        assignment({ id: 'open', endsOn: '2026-11-20' }),
        assignment({ id: 'later', startsOn: '2026-12-01' }, { id: 'f2', name: 'Baseline' }),
        assignment({ id: 'closing', status: 'closing', canStart: false }, { id: 'f3', name: 'Visit' }),
        assignment({ id: 'missing', version: { id: 'v9', number: 2, sha256: 'h9', schemaVersion: 1 } }, { id: 'f4', name: 'Inspection' }),
      ]),
      [item({ status: 'draft' }), item({ status: 'synced', serverStatus: 'accepted' }), item({ status: 'synced', serverStatus: 'submitted' })],
      new Set(['v1']),
      today,
    );
    const by = Object.fromEntries(rows.map((r) => [r.assignment.id, r]));
    expect(by.open).toMatchObject({ state: 'open', daysLeft: 3, drafts: 1, sent: 2, approved: 1 });
    expect(by.later.state).toBe('not_started');
    expect(by.closing.state).toBe('closing');
    expect(by.missing.state).toBe('not_downloaded');
  });

  it('prefers the server\'s counts, which outlive the phone\'s cleanup', () => {
    const [row] = taskRows(
      kit([assignment({ progress: { received: 40, pending: 10, accepted: 28, correction: 1, rejected: 1 } })]),
      [item({ status: 'synced', serverStatus: 'accepted' })],
      new Set(['v1']),
      today,
    );
    expect(row).toMatchObject({ sent: 40, approved: 28 });
  });

  it('filters and searches, corrections first', () => {
    const rows = taskRows(
      kit([assignment({ id: 'a', endsOn: '2026-11-30' }, { id: 'fa', name: 'Water points' }), assignment({ id: 'b', endsOn: '2026-11-18' }, { id: 'fb', name: 'Schools', programme: 'Education' })]),
      [item({ formId: 'fa', status: 'action_required', correction: { comment: null, notes: {}, baseRevision: 1 } })],
      new Set(['v1']),
      today,
    );
    expect(filterTasks(rows, 'all', '').map((r) => r.assignment.id)).toEqual(['a', 'b']);
    expect(filterTasks(rows, 'due_soon', '').map((r) => r.assignment.id)).toEqual(['b']);
    expect(filterTasks(rows, 'all', 'education').map((r) => r.assignment.id)).toEqual(['b']);
    expect(filterTasks(rows, 'attention', '').map((r) => r.assignment.id)).toEqual(['a']);
  });

  it('notices a newer form version without moving the draft to it', () => {
    const k = kit([assignment({ version: { id: 'v2', number: 2, sha256: 'h2', schemaVersion: 1 } })]);
    const draft = item({ formVersionId: 'v1' });
    expect(newerVersion(draft, k)?.version?.number).toBe(2);
    expect(draft.formVersionId).toBe('v1');
    expect(newerVersion(item({ formVersionId: 'v2' }), k)).toBeNull();
  });
});

describe('pages and progress of a long form', () => {
  const definition: Definition = {
    schema_version: 1,
    settings: { title: 'Visit' },
    items: [
      { type: 'text', name: 'visitor', label: 'Visitor', required: true },
      {
        type: 'group',
        name: 'household',
        label: 'Household',
        items: [
          { type: 'yes_no', name: 'safe_water', label: 'Safe water?', required: true },
          { type: 'text', name: 'source', label: 'Source', relevant: { q: 'safe_water', op: 'eq', value: 'no' } },
        ],
      },
      { type: 'group', name: 'school', label: 'School', relevant: { q: 'visitor', op: 'eq', value: 'teacher' }, items: [{ type: 'integer', name: 'pupils', label: 'Pupils' }] },
    ],
    choices: {},
  };

  it('makes one page per section and one for loose questions', () => {
    expect(pagesOf(definition).map((p) => p.title)).toEqual([null, 'Household', 'School']);
  });

  it('drops a hidden section and counts only visible questions', () => {
    const answers = { visitor: 'nurse', safe_water: 'no' };
    const e = evaluate(definition, answers, '2026-11-17');
    expect(visiblePages(pagesOf(definition), e).map((p) => p.title)).toEqual([null, 'Household']);
    expect(progressOf(definition, answers, e)).toEqual({ answered: 2, total: 3, problems: 0 });
  });

  it('keeps answers of hidden questions while filling in', () => {
    const answers = { visitor: 'nurse', safe_water: 'yes', source: 'river' };
    const e = evaluate(definition, answers, '2026-11-17');
    // Hidden now, so neither counted nor kept in the cleaned answers…
    expect(progressOf(definition, answers, e).total).toBe(2);
    expect(e.answers).not.toHaveProperty('source');
    // …but the draft's own answers are untouched until it is finished.
    expect(answers.source).toBe('river');
  });
});

describe('retrying on demand', () => {
  it('makes failed items due now, keeps their attempts, leaves refusals alone', async () => {
    const store = new MemoryStore();
    const now = new Date('2026-11-17T10:00:00Z');
    const later = '2026-11-17T10:30:00Z';
    await store.saveItem(item({ id: 'f', status: 'failed', attempts: 3, nextAttemptAt: later }));
    await store.saveItem(item({ id: 'r', status: 'action_required', error: { code: 'assignment_revoked', message: '' } }));
    expect(anythingDue(await store.items(SCOPE), now)).toBe(false);

    expect(await retryNow(store, SCOPE, now)).toBe(1);
    const failed = await store.item(SCOPE, 'f');
    expect(failed).toMatchObject({ status: 'failed', attempts: 3, nextAttemptAt: null });
    expect((await store.item(SCOPE, 'r'))?.status).toBe('action_required');
    expect(anythingDue(await store.items(SCOPE), now)).toBe(true);
  });

  it('lets an idle phone skip the timer', () => {
    const now = new Date('2026-11-17T10:00:00Z');
    expect(anythingDue([item({ status: 'draft' }), item({ status: 'synced' })], now)).toBe(false);
    expect(anythingDue([item({ status: 'ready' })], now)).toBe(true);
  });
});
