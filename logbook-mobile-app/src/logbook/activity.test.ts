/// <reference types="jest" />
import { activityBody, estimate, phoneCalculation, rateAt, readingAt, readingChoices, shiftAt } from './activity';
import { MemoryStore } from './memory-store';
import { newItem, syncOne, type ServerEntry, type Transport } from './sync';
import type { ActivitySettings, FuelRate, Item, KitResource, KitSheet, KitShift, StoredVersion } from './types';
import type { Occurrence } from './work';

/*
 * ADR-0030 on the phone: the offline fuel estimate, the shift suggestion,
 * the expected readings an entry can be for, and what an entry sends.
 */

const rate = (id: string, value: number, from: string, method: FuelRate['method'] = 'distance'): FuelRate => ({
  id,
  value,
  unit: method === 'distance' ? 'L/100 km' : 'L/h',
  method,
  fuel_type: 'diesel',
  effective_from: from,
});
const truck: KitResource = { id: 'r1', code: 'VEH-01', name: 'Land Cruiser', type: 'Vehicle', location: null, fuel: [rate('f1', 10, '2026-01-01T00:00:00Z'), rate('f2', 12, '2026-11-01T00:00:00Z')], meters: { odometer: 1000 } };
const odometer: ActivitySettings = { resource_field: 'unit', measure: 'odometer', start_field: 'start', end_field: 'end', actual_field: 'used', refill_field: 'refill' };

describe('fuel estimate', () => {
  it('uses the rate in force when the activity started', () => {
    expect(rateAt(truck.fuel, '2026-10-31T23:00:00Z')?.id).toBe('f1');
    expect(rateAt(truck.fuel, '2026-11-02T08:00:00Z')?.id).toBe('f2');
    expect(rateAt(truck.fuel, '2025-12-31T00:00:00Z')).toBeNull();
    expect(rateAt(undefined, '2026-11-02T08:00:00Z')).toBeNull();
  });

  it('works out km × rate ÷ 100 and the difference from what was measured', () => {
    const e = estimate(odometer, [truck], { unit: 'r1', start: '1000', end: '1150', used: '20', refill: '40' }, '2026-11-20T08:00:00Z', null);
    expect(e).toMatchObject({ quantity: 150, unit: 'km', estimated: 18, actual: 20, difference: 2, refill: 40, endBelowStart: false, belowLast: null, mismatch: false });
    expect(phoneCalculation(e)).toEqual({ estimated_l: 18, rate_id: 'f2', rate: 12, quantity: 150 });
  });

  it('gives no estimate before a resource is chosen, without a rate, or with a rate for another measure', () => {
    expect(estimate(odometer, [truck], { start: '1', end: '2' }, '2026-11-20T08:00:00Z', null)).toBeNull();
    const bare = estimate(odometer, [{ ...truck, fuel: [] }], { unit: 'r1', start: '1000', end: '1100' }, '2026-11-20T08:00:00Z', null);
    expect(bare).toMatchObject({ quantity: 100, rate: null, estimated: null });
    expect(phoneCalculation(bare)).toBeNull();
    const hours = estimate(odometer, [{ ...truck, fuel: [rate('h1', 3, '2026-01-01T00:00:00Z', 'hours')] }], { unit: 'r1', start: '1000', end: '1100' }, '2026-11-20T08:00:00Z', null);
    expect(hours).toMatchObject({ mismatch: true, estimated: null });
  });

  it('flags an end below the start, and a start below the last reading on record', () => {
    const e = estimate(odometer, [truck], { unit: 'r1', start: '900', end: '850' }, '2026-11-20T08:00:00Z', null);
    expect(e).toMatchObject({ endBelowStart: true, quantity: null, estimated: null, belowLast: 1000 });
  });

  it('estimates by duration from the activity times', () => {
    const generator: KitResource = { ...truck, fuel: [rate('g1', 4, '2026-01-01T00:00:00Z', 'hours')] };
    const byTime: ActivitySettings = { resource_field: 'unit', measure: 'duration', start_field: null, end_field: null, actual_field: null, refill_field: null };
    expect(estimate(byTime, [generator], { unit: 'r1' }, '2026-11-20T08:00:00Z', '2026-11-20T10:30:00Z')).toMatchObject({ quantity: 2.5, unit: 'h', estimated: 10 });
    expect(estimate(byTime, [generator], { unit: 'r1' }, '2026-11-20T08:00:00Z', null)).toMatchObject({ quantity: null, estimated: null });
  });
});

describe('shifts', () => {
  const at = (h: number, m = 0) => new Date(2026, 10, 20, h, m).toISOString();
  const shifts: KitShift[] = [
    { id: 'day', name: 'Day', starts: '06:00', ends: '18:00', logbookId: null, members: ['u1'] },
    { id: 'night', name: 'Night', starts: '18:00', ends: '06:00', logbookId: 'l1', members: [] },
    { id: 'other', name: 'Other plant', starts: '00:00', ends: '23:59', logbookId: 'l2', members: [] },
  ];

  it('suggests the shift whose hours hold the time, across midnight, in this logbook only', () => {
    expect(shiftAt(shifts, 'l1', at(9))).toBe('day');
    expect(shiftAt(shifts, 'l1', at(22))).toBe('night');
    expect(shiftAt(shifts, 'l1', at(3, 30))).toBe('night');
    expect(shiftAt(shifts, 'l3', at(22))).toBeNull();
  });

  it('falls back to the one shift the person belongs to', () => {
    expect(shiftAt([shifts[0]], 'l1', at(20), 'u1')).toBe('day');
    expect(shiftAt([shifts[0]], 'l1', at(20), 'u2')).toBeNull();
  });
});

describe('expected readings', () => {
  const o = (id: string, due: string, state = 'due', sheetId = 's1'): Occurrence => ({
    id,
    state,
    opensAt: new Date(new Date(due).getTime() - 3600_000).toISOString(),
    dueAt: due,
    closesAt: new Date(new Date(due).getTime() + 3600_000).toISOString(),
    sheet: { id: sheetId, name: 'Readings', code: 'MTR' },
    logbook: 'Plant',
  });

  it('offers the sheet\'s readings still to take, soonest first, and the one already named', () => {
    const list = [o('b', '2026-11-20T14:00:00Z'), o('a', '2026-11-20T08:00:00Z', 'missed'), o('c', '2026-11-20T10:00:00Z', 'awaiting_review'), o('d', '2026-11-20T09:00:00Z', 'due', 's2'), o('a', '2026-11-20T08:00:00Z', 'missed')];
    expect(readingChoices(list, 's1').map((x) => x.id)).toEqual(['a', 'b']);
    expect(readingChoices(list, 's1', 'c').map((x) => x.id)).toEqual(['a', 'c', 'b']);
  });

  it('picks the reading whose window holds the time, only when exactly one does', () => {
    const list = [o('a', '2026-11-20T08:00:00Z'), o('b', '2026-11-20T14:00:00Z')];
    expect(readingAt(list, '2026-11-20T08:20:00Z')).toBe('a');
    expect(readingAt(list, '2026-11-20T11:00:00Z')).toBeNull();
    expect(readingAt([...list, o('c', '2026-11-20T08:30:00Z')], '2026-11-20T08:20:00Z')).toBeNull();
  });
});

describe('what an entry sends', () => {
  const version: StoredVersion = { id: 'v1', sheetId: 's1', version: 1, fields: [{ name: 'reading', label: 'Reading', type: 'integer', required: true }] };
  const sheet: KitSheet = { id: 's1', name: 'Readings', code: 'MTR', description: null, instructions: null, reviewRequired: false, logbook: { id: 'l1', name: 'Plant', icon: null }, version: { id: 'v1', version: 1 } };

  function capture() {
    const bodies: Record<string, unknown>[] = [];
    const entry = (id: string, revision: number): ServerEntry => ({
      id,
      key: null,
      reference: 'R-1',
      status: revision > 0 ? 'submitted' : 'draft',
      revision,
      reviewRequired: false,
      sheet: { id: 's1', name: 'Readings', code: 'MTR' },
      logbook: 'Plant',
      versionId: 'v1',
      occurredAt: null,
      submittedAt: null,
      updatedAt: null,
      values: {},
      files: [],
      lastReview: null,
    });
    const transport: Transport = {
      kit: async () => {
        throw new Error('unused');
      },
      version: async () => version,
      create: async (body) => {
        bodies.push(body);
        return entry('e1', 0);
      },
      update: async (_id, body) => {
        bodies.push(body);
        return entry('e1', 0);
      },
      upload: async () => ({ id: 'a1' }),
      removeFile: async () => undefined,
      submit: async (_id, revision) => entry('e1', revision),
      changed: async () => ({ serverTime: '', complete: true, entries: [] }),
    };
    return { bodies, transport };
  }
  const send = async (item: Item) => {
    const { bodies, transport } = capture();
    const after = await syncOne(new MemoryStore(), transport, { remove: async () => undefined }, { ...item, status: 'ready' });
    return { body: bodies[0], after };
  };

  it('sends nothing new for an item saved before recording modes', async () => {
    const item = newItem('i1', 'acme|u1', sheet, version, new Date('2026-11-20T08:00:00Z'), 'occ1');
    expect(activityBody(item)).toEqual({});
    const { body, after } = await send({ ...item, values: { reading: 4 } });
    expect(body).toMatchObject({ occurrence_id: 'occ1' });
    expect(Object.keys(body)).not.toEqual(expect.arrayContaining(['schedule_link']));
    expect(after.status).toBe('synced');
  });

  it('names the reading, the shift, the activity and the phone\'s figure', async () => {
    const item: Item = {
      ...newItem('i2', 'acme|u1', sheet, version, new Date('2026-11-20T08:00:00Z'), 'occ1'),
      values: { reading: 4 },
      scheduleLink: 'named',
      shiftId: 'day',
      activityStartedAt: '2026-11-20T08:00:00.000Z',
      activityEndedAt: '2026-11-20T17:00:00.000Z',
      calculation: { estimated_l: 18, rate_id: 'f2', rate: 12, quantity: 150 },
    };
    const { body } = await send(item);
    expect(body).toMatchObject({
      occurrence_id: 'occ1',
      schedule_link: 'named',
      shift_id: 'day',
      activity_started_at: '2026-11-20T08:00:00.000Z',
      activity_ended_at: '2026-11-20T17:00:00.000Z',
      calculation: { estimated_l: 18, rate_id: 'f2', rate: 12, quantity: 150 },
    });
  });

  it('never names a reading for an extra one', async () => {
    const item: Item = { ...newItem('i3', 'acme|u1', sheet, version, new Date('2026-11-20T08:00:00Z'), 'occ1'), values: { reading: 4 }, scheduleLink: 'extra' };
    const { body } = await send(item);
    expect(body).toMatchObject({ occurrence_id: null, schedule_link: 'extra' });
  });

  it('sends the activity fields again with a correction', async () => {
    const item: Item = { ...newItem('i4', 'acme|u1', sheet, version, new Date('2026-11-20T08:00:00Z')), values: { reading: 4 }, serverId: 'e1', revision: 1, shiftId: null, activityEndedAt: '2026-11-20T18:00:00.000Z' };
    const { body } = await send(item);
    expect(body).toMatchObject({ base_revision: 1, shift_id: null, activity_ended_at: '2026-11-20T18:00:00.000Z' });
  });
});
