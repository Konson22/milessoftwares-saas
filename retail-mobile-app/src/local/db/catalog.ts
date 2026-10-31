import type { PriceTier } from '@/lib/pricing';
import type { Item, Pack } from '@/lib/types';
import { all, one, type Param } from './database';
import { today } from './util';

/** Products as the counter sells them: with what can be sold today, the lot that expires first (then the oldest) first. */

export interface ProductRecord {
  id: string;
  name: string;
  unit: string;
  barcode: string | null;
  category: string | null;
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
  product_id: string;
  batch_number: string;
  expires_on: string | null;
  quantity: number;
  unit_cost_minor: number;
  status: 'available' | 'quarantined' | 'recalled';
  hold_reason: string | null;
  receipt_id: string | null;
  received_at: string;
  /** A unit's selling price; null: the product's. */
  sell_price_minor: number | null;
  buy_unit: string | null;
  buy_unit_size: number;
  buy_price_minor: number | null;
  buy_currency: string | null;
  exchange_rate: number | null;
}

/** A lot the counter may sell from: released, in stock, not expired. */
export const SELLABLE = "status = 'available' AND quantity > 0 AND (expires_on IS NULL OR expires_on >= ?)";

/** The soonest to expire first (FEFO), then the oldest (FIFO); lots without an expiry after those with one. */
export function sellableBatches(productId: string): BatchRow[] {
  return all<BatchRow>(`SELECT * FROM batches WHERE product_id = ? AND ${SELLABLE} ORDER BY expires_on IS NULL, expires_on, received_at`, productId, today());
}

export function availableUnits(productId: string): number {
  return one<{ n: number }>(`SELECT COALESCE(SUM(quantity), 0) AS n FROM batches WHERE product_id = ? AND ${SELLABLE}`, productId, today())!.n;
}

/** The units on the shelf at their prices, in the order they sell. */
export function priceTiers(productId: string, listPrice: number): PriceTier[] {
  return sellableBatches(productId).map((b) => ({ units: b.quantity, unit_price: b.sell_price_minor ?? listPrice }));
}

export function packsOf(productId: string): Pack[] {
  return all<Pack>('SELECT id, name, units, price_minor FROM packs WHERE product_id = ? ORDER BY position', productId);
}

type ItemRow = ProductRecord & { available: number; nearest_expiry: string | null };

function present(row: ItemRow): Item {
  return {
    id: row.id,
    name: row.name,
    unit: row.unit,
    barcode: row.barcode,
    category: row.category,
    price_minor: row.price_minor,
    available: row.available,
    nearest_expiry: row.nearest_expiry,
    packs: packsOf(row.id),
    price_tiers: priceTiers(row.id, row.price_minor),
  };
}

function itemsWhere(where: string, params: Param[], limit: number): Item[] {
  return all<ItemRow>(
    `SELECT m.*, COALESCE(SUM(b.quantity), 0) AS available, MIN(b.expires_on) AS nearest_expiry
     FROM products m
     LEFT JOIN batches b ON b.product_id = m.id AND b.status = 'available' AND b.quantity > 0 AND (b.expires_on IS NULL OR b.expires_on >= ?)
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

/** By name, category or barcode; in stock first. */
export function searchItems(search: string, limit = 80): Item[] {
  const q = search.trim();
  if (!q) return itemsWhere('m.active = 1', [], limit);
  const like = contains(q);
  return itemsWhere("m.active = 1 AND (m.name LIKE ? ESCAPE '\\' OR m.category LIKE ? ESCAPE '\\' OR m.barcode = ?)", [like, like, q], limit);
}

export function itemByCode(code: string): Item | null {
  const c = code.trim();
  return c ? (itemsWhere('m.active = 1 AND m.barcode = ?', [c], 1)[0] ?? null) : null;
}

export function itemById(id: string): Item | null {
  return itemsWhere('m.id = ?', [id], 1)[0] ?? null;
}
