import type { PriceTier } from '@/lib/pricing';
import type { Item, Pack } from '@/lib/types';
import { all, one, type Param } from './database';
import { today } from './util';

/** Medicines as the counter sells them: with what can be sold today, soonest to expire first. */

export interface MedicineRow {
  id: string;
  name: string;
  generic_name: string;
  strength: string | null;
  form: string;
  unit: string;
  barcode: string | null;
  category: string | null;
  storage: string;
  prescription_only: number;
  controlled: number;
  price_minor: number;
  last_unit_cost_minor: number | null;
  reorder_level: number;
  tracks_expiry: number;
  active: number;
  created_at: string;
  updated_at: string;
}

export interface BatchRow {
  id: string;
  medicine_id: string;
  batch_number: string;
  expires_on: string | null;
  quantity: number;
  unit_cost_minor: number;
  status: 'available' | 'quarantined' | 'recalled';
  hold_reason: string | null;
  receipt_id: string | null;
  received_at: string;
  /** A unit's selling price; null: the medicine's. */
  sell_price_minor: number | null;
  buy_unit: string | null;
  buy_unit_size: number;
  buy_price_minor: number | null;
  buy_currency: string | null;
  exchange_rate: number | null;
}

export const FORMS = [
  ['tablet', 'Tablet'],
  ['capsule', 'Capsule'],
  ['syrup', 'Syrup'],
  ['suspension', 'Suspension'],
  ['injection', 'Injection'],
  ['infusion', 'Infusion'],
  ['cream', 'Cream'],
  ['ointment', 'Ointment'],
  ['gel', 'Gel'],
  ['drops', 'Drops'],
  ['inhaler', 'Inhaler'],
  ['powder', 'Powder'],
  ['effervescent', 'Effervescent'],
  ['suppository', 'Suppository'],
  ['pessary', 'Pessary'],
  ['device', 'Device'],
  ['other', 'Other'],
].map(([value, label]) => ({ value, label }));

export const STORAGE = [
  ['room', 'Room temperature'],
  ['below_30', 'Below 30°C'],
  ['below_25', 'Below 25°C'],
  ['cool', 'Cool, 8–15°C'],
  ['fridge', 'Refrigerate 2–8°C'],
  ['freezer', 'Frozen'],
].map(([value, label]) => ({ value, label }));

export const formLabel = (value: string) => FORMS.find((f) => f.value === value)?.label ?? value;
export const storageLabel = (value: string) => STORAGE.find((s) => s.value === value)?.label ?? value;

/** "Panadol Extra 500mg" */
export const medicineLabel = (m: Pick<MedicineRow, 'name' | 'strength'>) => [m.name, m.strength].filter(Boolean).join(' ');

/** A batch the counter may sell from: released, in stock, not expired. */
export const SELLABLE = "status = 'available' AND quantity > 0 AND (expires_on IS NULL OR expires_on >= ?)";

/** FEFO: the soonest to expire first; batches without an expiry last. */
export function sellableBatches(medicineId: string): BatchRow[] {
  return all<BatchRow>(`SELECT * FROM batches WHERE medicine_id = ? AND ${SELLABLE} ORDER BY expires_on IS NULL, expires_on, received_at`, medicineId, today());
}

export function availableUnits(medicineId: string): number {
  return one<{ n: number }>(`SELECT COALESCE(SUM(quantity), 0) AS n FROM batches WHERE medicine_id = ? AND ${SELLABLE}`, medicineId, today())!.n;
}

/** The units on the shelf at their prices, in the order they sell (FEFO). */
export function priceTiers(medicineId: string, listPrice: number): PriceTier[] {
  return sellableBatches(medicineId).map((b) => ({ units: b.quantity, unit_price: b.sell_price_minor ?? listPrice }));
}

export function packsOf(medicineId: string): Pack[] {
  return all<Pack>('SELECT id, name, units, price_minor FROM packs WHERE medicine_id = ? ORDER BY position', medicineId);
}

type ItemRow = MedicineRow & { available: number; nearest_expiry: string | null };

function present(row: ItemRow): Item {
  return {
    id: row.id,
    name: row.name,
    generic_name: row.generic_name,
    strength: row.strength,
    form: formLabel(row.form),
    unit: row.unit,
    barcode: row.barcode,
    category: row.category,
    price_minor: row.price_minor,
    available: row.available,
    nearest_expiry: row.nearest_expiry,
    restricted: Boolean(row.prescription_only || row.controlled),
    controlled: Boolean(row.controlled),
    storage: row.storage === 'room' ? null : storageLabel(row.storage),
    packs: packsOf(row.id),
    price_tiers: priceTiers(row.id, row.price_minor),
  };
}

function itemsWhere(where: string, params: Param[], limit: number): Item[] {
  return all<ItemRow>(
    `SELECT m.*, COALESCE(SUM(b.quantity), 0) AS available, MIN(b.expires_on) AS nearest_expiry
     FROM medicines m
     LEFT JOIN batches b ON b.medicine_id = m.id AND b.status = 'available' AND b.quantity > 0 AND (b.expires_on IS NULL OR b.expires_on >= ?)
     WHERE ${where}
     GROUP BY m.id
     ORDER BY available > 0 DESC, m.name COLLATE NOCASE
     LIMIT ${limit}`,
    today(),
    ...params,
  ).map(present);
}

/** LIKE pattern for text typed by someone: % and _ match themselves. */
export const contains = (q: string) => `%${q.replace(/[\\%_]/g, (c) => `\\${c}`)}%`;

/** By name, generic name or barcode; in stock first. */
export function searchItems(search: string, limit = 80): Item[] {
  const q = search.trim();
  if (!q) return itemsWhere('m.active = 1', [], limit);
  const like = contains(q);
  return itemsWhere("m.active = 1 AND (m.name LIKE ? ESCAPE '\\' OR m.generic_name LIKE ? ESCAPE '\\' OR m.barcode = ?)", [like, like, q], limit);
}

export function itemByCode(code: string): Item | null {
  const c = code.trim();
  return c ? (itemsWhere('m.active = 1 AND m.barcode = ?', [c], 1)[0] ?? null) : null;
}

export function itemById(id: string): Item | null {
  return itemsWhere('m.id = ?', [id], 1)[0] ?? null;
}
