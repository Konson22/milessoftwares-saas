/// <reference types="jest" />
import { insightsOf, lastDays } from './insights';
import type { Assignment, Item, Kit } from './types';

/*
 * The Insights tab: totals from the server's progress, activity from the
 * kit or, from an older server, from the records on the phone.
 */

function assignment(over: Partial<Assignment> = {}): Assignment {
  return {
    id: over.id ?? 'a1',
    status: 'active',
    canStart: true,
    startsOn: null,
    endsOn: null,
    closesAt: null,
    form: { id: 'f1', name: 'Household', description: null, purpose: 'survey', status: 'active', programme: null, containsPersonalData: false },
    unit: null,
    version: null,
    ...over,
  };
}

function kit(assignments: Assignment[], activity?: Kit['activity']): Kit {
  return { serverTime: '2026-11-17T10:00:00Z', settings: { photoMaxKb: 500, keepOnDeviceDays: 30 }, assignments, activity };
}

it('lists the 30 days up to today, across a month end', () => {
  const days = lastDays('2026-12-02', 30);
  expect(days).toHaveLength(30);
  expect(days[0]).toBe('2026-11-03');
  expect(days[29]).toBe('2026-12-02');
});

it('adds up review outcomes across forms and works out the acceptance rate', () => {
  const k = kit([
    assignment({ id: 'a1', expected: 10, progress: { received: 6, pending: 2, accepted: 3, correction: 0, rejected: 1 } }),
    assignment({ id: 'a2', form: { ...assignment().form, id: 'f2', name: 'Water point' }, progress: { received: 2, pending: 0, accepted: 1, correction: 1, rejected: 0 } }),
  ]);
  const i = insightsOf(k, [], '2026-11-17');
  expect(i.totals).toEqual({ pending: 2, correction: 1, rejected: 1, accepted: 4, received: 8 });
  expect(i.acceptanceRate).toBe(67);
  expect(i.forms[0]).toMatchObject({ formName: 'Household', done: 5, expected: 10 });
  expect(i.forms[1].expected).toBeNull();
});

it('has no acceptance rate before anything is reviewed', () => {
  const i = insightsOf(kit([assignment({ progress: { received: 2, pending: 2, accepted: 0, correction: 0, rejected: 0 } })]), [], '2026-11-17');
  expect(i.acceptanceRate).toBeNull();
});

it('takes the activity from the kit and counts the streak', () => {
  const i = insightsOf(
    kit([], [
      { date: '2026-11-15', count: 1 },
      { date: '2026-11-16', count: 3 },
    ]),
    [],
    '2026-11-17',
  );
  expect(i.fromServer).toBe(true);
  expect(i.activity).toHaveLength(30);
  expect(i.activity[29]).toEqual({ date: '2026-11-17', count: 0 });
  expect(i.activityTotal).toBe(4);
  expect(i.activeDays).toBe(2);
  expect(i.streak).toBe(2);
});

it('falls back to the records on the phone for an older server', () => {
  const at = new Date(2026, 10, 17, 9).toISOString();
  const item = { id: 'x', completedAt: at, status: 'synced' } as Item;
  const draft = { id: 'y', completedAt: at, status: 'draft' } as Item;
  const i = insightsOf(kit([]), [item, draft], '2026-11-17');
  expect(i.fromServer).toBe(false);
  expect(i.activity[29].count).toBe(1);
  expect(i.streak).toBe(1);
});
