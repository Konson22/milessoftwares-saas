import { activityBody } from '@/logbook/activity';
import { assetProblem, offered } from '@/logbook/assets';
import type { FieldDef, Item, Kit, KitResource } from '@/logbook/types';
import { assignedOn, legs, stopProblems, tripsToDo, type Dispatch, type Stop } from '@/logbook/vehicle-trips';

const car = (id: string): KitResource => ({ id, code: id.toUpperCase(), name: id, type: 'Car', location: null });
const vehicle: FieldDef = { name: 'vehicle', label: 'Vehicle', type: 'resource', required: true, resource_type: 'Car' };

const trip = (id: string, resourceId: string, day: string, status: Dispatch['status'] = 'planned'): Dispatch => ({
  id, status, day, time: '08:00', destination: 'Juba', purpose: null, sheetId: 's1', sheet: 'Vehicle movement', resourceId, resource: resourceId.toUpperCase(), entryId: null,
});

const kit = (extra: Partial<Kit> = {}, assignedOnly = true): Kit => ({
  serverTime: '2026-11-24T08:00:00Z',
  can: { write: true, review: false },
  limits: { fileMaxKb: 10240, fileTypes: [], filesPerField: 5 },
  members: [],
  resources: [car('a'), car('b'), car('c')],
  sheets: [
    {
      id: 's1', name: 'Vehicle movement', code: 'VEH', description: null, instructions: null, reviewRequired: false,
      logbook: { id: 'l1', name: 'Fleet', icon: null }, version: { id: 'v1', version: 1 }, subject: 'vehicle', assets: [], assignedOnly,
      recording: { mode: 'event', shifts: 'off', stops: 'optional' },
    },
  ],
  ...extra,
});

const stop = (place: string, arrived: string, departed: string | null, odometer: number | null = null): Stop => ({
  place, arrived_at: arrived, departed_at: departed, odometer, purpose: null, note: null, pin: null,
});

describe('assigned vehicles on the phone (ADR-0032)', () => {
  it('counts standing assignments over their days and planned trips on their day', () => {
    const k = kit({
      assignments: [{ resourceId: 'a', from: '2026-11-20', to: null }, { resourceId: 'b', from: '2026-11-25', to: '2026-11-26' }],
      dispatches: [trip('d1', 'c', '2026-11-24'), trip('d2', 'b', '2026-11-24', 'cancelled')],
    });
    expect(assignedOn(k, '2026-11-24').sort()).toEqual(['a', 'c']);
    expect(assignedOn(k, '2026-11-26').sort()).toEqual(['a', 'b']);
    expect(assignedOn(k, '2026-11-27')).toEqual(['a']);
    expect(assignedOn(k, '2026-11-19')).toEqual([]);
  });

  it('offers only the assigned vehicles on an assigned-only sheet, for the trip day', () => {
    const k = kit({ assignments: [{ resourceId: 'a', from: '2026-11-20', to: null }], dispatches: [trip('d1', 'c', '2026-11-24')] });
    expect(offered(k, 's1', vehicle, '2026-11-24').map((r) => r.id)).toEqual(['a', 'c']);
    expect(offered(k, 's1', vehicle, '2026-11-25').map((r) => r.id)).toEqual(['a']);
    // Without a day (and on other sheets) nothing is narrowed.
    expect(offered(k, 's1', vehicle).map((r) => r.id)).toEqual(['a', 'b', 'c']);
    expect(offered(kit({}, false), 's1', vehicle, '2026-11-24').map((r) => r.id)).toEqual(['a', 'b', 'c']);
  });

  it('flags a vehicle not assigned on the trip day', () => {
    const k = kit({ assignments: [{ resourceId: 'a', from: '2026-11-20', to: '2026-11-24' }] });
    expect(assetProblem(k, 's1', [vehicle], { vehicle: 'a' }, '2026-11-24')).toBeNull();
    expect(assetProblem(k, 's1', [vehicle], { vehicle: 'a' }, '2026-11-25')).toMatch(/not assigned to you/);
    expect(assetProblem(k, 's1', [vehicle], { vehicle: 'b' }, '2026-11-24')).toMatch(/not assigned to you/);
  });
});

describe('planned trips on the phone', () => {
  it('lists planned trips not begun on this phone', () => {
    const k = kit({ dispatches: [trip('d1', 'a', '2026-11-24'), trip('d2', 'b', '2026-11-24'), trip('d3', 'c', '2026-11-24', 'started')] });
    const begun = { dispatchId: 'd2' } as Item;
    expect(tripsToDo(k, [begun]).map((d) => d.id)).toEqual(['d1']);
  });

  it('sends the planned trip only when the entry is made, and the stops when there are', () => {
    const item = { serverId: null, dispatchId: 'd1', stops: [stop('Depot', '2026-11-24T08:10:00.000Z', null)] } as unknown as Item;
    expect(activityBody(item)).toEqual({ dispatch_id: 'd1', stops: item.stops });
    expect(activityBody({ ...item, serverId: 'e1' })).toEqual({ stops: item.stops });
    expect(activityBody({ serverId: null } as Item)).toEqual({});
  });
});

describe('stops on the phone (ADR-0032 §5)', () => {
  const span = { startedAt: '2026-11-24T08:00:00.000Z', endedAt: '2026-11-24T12:00:00.000Z', odometerStart: 10000, odometerEnd: 10120 };

  it('accepts stops in order within the trip, whatever the ISO spelling', () => {
    const stops = [stop('Depot', '2026-11-24T08:30:00Z', '2026-11-24T09:00:00+00:00', 10030), stop('Market', '2026-11-24T10:00:00.000Z', null, 10080)];
    expect(stopProblems(stops, span, 'optional')).toEqual({});
  });

  it('asks for a stop when the sheet requires one, and nothing when stops are off', () => {
    expect(stopProblems([], span, 'required')).toEqual({ stops: 'Record at least one stop of this trip.' });
    expect(stopProblems([stop('', '2026-11-24T07:00:00Z', null)], span, 'off')).toEqual({});
  });

  it('refuses stops outside the trip, out of order, unnamed or with odometers out of range', () => {
    const problems = stopProblems(
      [
        stop('', '2026-11-24T07:00:00Z', null),
        stop('B', '2026-11-24T10:00:00Z', '2026-11-24T09:00:00Z', 9990),
        stop('C', '2026-11-24T10:30:00Z', null, 10200),
        stop('C2', '2026-11-24T10:15:00Z', null),
        stop('D', '2026-11-24T13:00:00Z', null),
      ],
      span,
      'optional',
    );
    expect(Object.keys(problems).sort()).toEqual(['stops.0.arrived_at', 'stops.0.place', 'stops.1.departed_at', 'stops.1.odometer', 'stops.2.odometer', 'stops.3.arrived_at', 'stops.4.arrived_at']);
  });

  it('gives each leg from the previous reading', () => {
    expect(legs([stop('A', 'x', null, 10030), stop('B', 'x', null, null), stop('C', 'x', null, 10080.5)], 10000)).toEqual([30, null, 50.5]);
    expect(legs([stop('A', 'x', null, 10030)], null)).toEqual([null]);
  });
});
