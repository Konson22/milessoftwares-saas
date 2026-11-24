import { assetProblem, offered, readingsForAsset } from '@/logbook/assets';
import type { FieldDef, Kit, KitResource } from '@/logbook/types';
import type { Occurrence } from '@/logbook/work';

const room = (id: string, type = 'Cold storage room'): KitResource => ({ id, code: id.toUpperCase(), name: id, type, location: null });
const unit: FieldDef = { name: 'unit', label: 'Room', type: 'resource', required: true, resource_type: 'Cold storage room' };
const temperature: FieldDef = { name: 'temperature', label: 'Temperature', type: 'decimal', required: true };

const kit = (assets: string[], resources: KitResource[]): Kit => ({
  serverTime: '2026-11-23T08:00:00Z',
  can: { write: true, review: false },
  limits: { fileMaxKb: 10240, fileTypes: [], filesPerField: 5 },
  members: [],
  resources,
  sheets: [
    {
      id: 's1', name: 'Temperature log', code: 'TMP', description: null, instructions: null, reviewRequired: false,
      logbook: { id: 'l1', name: 'Stores', icon: null }, version: { id: 'v1', version: 1 }, subject: 'unit', assets,
    },
  ],
});

const reading = (id: string, resourceId: string | null): Occurrence => ({
  id, state: 'due', dueAt: '2026-11-23T08:00:00Z', closesAt: '2026-11-23T09:00:00Z', sheet: { id: 's1', name: 'Temperature log', code: 'TMP' }, logbook: 'Stores', resourceId,
});

describe('assets on the phone (ADR-0031)', () => {
  it('offers every asset of a category when the field asks for the category by name', () => {
    const car: KitResource = { ...room('car', 'Car'), category: 'Vehicles' };
    const pickup: KitResource = { ...room('pickup', 'Pickup'), category: 'Vehicles' };
    const gen: KitResource = { ...room('gen', 'Diesel generator'), category: 'Generators' };
    const field: FieldDef = { ...unit, resource_type: 'vehicles' };
    expect(offered(kit([], [car, pickup, gen]), 's1', field).map((r) => r.id)).toEqual(['car', 'pickup']);
    expect(offered(kit([], [car, pickup, gen]), 's1', { ...unit, resource_type: 'Car' }).map((r) => r.id)).toEqual(['car']);
  });

  it('offers any asset of the category until the sheet has links, then only the linked ones', () => {
    const all = [room('a'), room('b'), room('p', 'Water pump')];
    expect(offered(kit([], all), 's1', unit).map((r) => r.id)).toEqual(['a', 'b']);
    expect(offered(kit(['b'], all), 's1', unit).map((r) => r.id)).toEqual(['b']);
  });

  it('says why a draft asset can no longer be sent, and never picks another', () => {
    const values = { unit: 'a', temperature: '4' };
    expect(assetProblem(kit([], [room('a')]), 's1', [unit, temperature], values)).toBeNull();
    expect(assetProblem(kit([], [room('b')]), 's1', [unit, temperature], values)).toMatch(/no longer active/);
    expect(assetProblem(kit(['b'], [room('a'), room('b')]), 's1', [unit, temperature], values)).toMatch(/no longer linked/);
    expect(values.unit).toBe('a');
    // Nothing chosen yet, or no kit: no warning.
    expect(assetProblem(kit(['b'], [room('b')]), 's1', [unit], {})).toBeNull();
    expect(assetProblem(null, 's1', [unit], values)).toBeNull();
  });

  it('offers the chosen asset its own readings and the whole-sheet ones', () => {
    const choices = [reading('r1', 'a'), reading('r2', 'b'), reading('r3', null)];
    expect(readingsForAsset(choices, 'a').map((o) => o.id)).toEqual(['r1', 'r3']);
    expect(readingsForAsset(choices, null).map((o) => o.id)).toEqual(['r1', 'r2', 'r3']);
    // A reading already chosen stays, so a change is visible rather than silent.
    expect(readingsForAsset(choices, 'a', 'r2').map((o) => o.id)).toEqual(['r1', 'r2', 'r3']);
  });
});
