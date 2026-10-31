import { create } from 'zustand';
import { persist } from 'zustand/middleware';

import type { Product } from '../../../shared/ipc';
import { percentOf } from '@/lib/format';
import { diskStorage } from '@/lib/storage';
import type { CartLine, HeldCart, Item, Pack } from '@/lib/types';

import { lineGross } from '../../../shared/pricing';

interface CartState {
  lines: CartLine[];
  customerName: string;
  customerPhone: string;
  /**
   * The sale's idempotency key: kept until it goes through, so a retry after a
   * dropped connection never sells twice.
   */
  submissionId: string;
  /** Carts parked on this device, by whoever parked them where. */
  held: (HeldCart & { owner: string })[];

  add(item: Item, quantity?: number): 'added' | 'blocked' | 'restricted';
  setQuantity(itemId: string, quantity: number): void;
  setPack(itemId: string, packId: string | null): void;
  setDiscount(itemId: string, percent: number): void;
  /** The serial of each unit, in order. */
  setSerials(itemId: string, serials: string[]): void;
  remove(itemId: string): void;
  setCustomer(patch: { name?: string; phone?: string }): void;
  /** Swaps the item's details (price, stock) for fresher ones. */
  refreshItem(item: Item): void;
  hold(owner: string, label: string | null): void;
  resume(id: string): void;
  discardHeld(id: string): void;
  /** Puts a sale the pharmacy refused back in the cart; the cart in progress is parked. */
  putBack(owner: string, lines: CartLine[], customerName: string, customerPhone: string): void;
  /** After a sale: an empty cart and a new key. */
  clear(): void;
}

export function packOf(line: CartLine): Pack | null {
  return line.pack_id ? (line.item.packs.find((p) => p.id === line.pack_id) ?? null) : null;
}

/** Units this line takes from stock. */
export function unitsOf(line: CartLine) {
  return line.quantity * (packOf(line)?.units ?? 1);
}

/** The percent taken off: the line's own discount, or the promotion running when it is bigger. */
export function percentOff(line: CartLine) {
  return Math.max(line.discount_percent, line.item.promotion?.percent_off ?? 0);
}

/**
 * As the pharmacy (or shop) prices it, less what is taken off: the pack's
 * price (or the unit's), or offline each batch's price as it leaves the shelf.
 */
export function linePrice(line: CartLine) {
  const tiers = line.item.price_tiers;
  const gross = tiers ? lineGross(tiers, line.item.price_minor, packOf(line), line.quantity) : line.quantity * (packOf(line)?.price_minor ?? line.item.price_minor);
  const discount = percentOf(gross, percentOff(line));
  return { gross, discount, total: gross - discount };
}

/** The serials typed for this line's units, one per unit; [] when it does not track them. */
export function serialsOf(line: CartLine): string[] {
  if (!line.item.tracks_serials) return [];
  return Array.from({ length: unitsOf(line) }, (_, i) => (line.serials?.[i] ?? '').trim());
}

/** A line that tracks serials and has a unit without one: it cannot be sold yet. */
export const needsSerials = (line: CartLine) => serialsOf(line).some((s) => s === '');

export function cartTotals(lines: CartLine[]) {
  return lines.reduce(
    (sum, line) => {
      const { gross, discount, total } = linePrice(line);
      return { gross: sum.gross + gross, discount: sum.discount + discount, total: sum.total + total, count: sum.count + line.quantity };
    },
    { gross: 0, discount: 0, total: 0, count: 0 },
  );
}

/** Most of this line that stock allows, in its own measure (packs or units). */
export function maxQuantity(line: CartLine) {
  return Math.floor(line.item.available / (packOf(line)?.units ?? 1));
}

const emptyCart = () => ({ lines: [], customerName: '', customerPhone: '', submissionId: crypto.randomUUID() });

/** The cart in progress: persisted so a dropped connection or a restart never loses a sale. */
export const useCart = create<CartState>()(
  persist(
    (set, get) => ({
      ...emptyCart(),
      held: [],

      add(item, quantity = 1) {
        if (item.restricted) return 'restricted';
        const existing = get().lines.find((l) => l.item.id === item.id);
        const units = (existing ? unitsOf(existing) : 0) + quantity * (existing ? (packOf(existing)?.units ?? 1) : 1);
        if (item.available <= 0 || units > item.available) return 'blocked';

        set((s) => ({
          lines: existing
            ? s.lines.map((l) => (l.item.id === item.id ? { ...l, item, quantity: l.quantity + quantity } : l))
            : [...s.lines, { item, pack_id: null, quantity, discount_percent: 0 }],
        }));
        return 'added';
      },

      setQuantity(itemId, quantity) {
        if (quantity <= 0) return get().remove(itemId);
        set((s) => ({
          lines: s.lines.map((l) => (l.item.id === itemId ? { ...l, quantity: Math.min(quantity, Math.max(1, maxQuantity(l))) } : l)),
        }));
      },

      setPack(itemId, packId) {
        set((s) => ({
          lines: s.lines.map((l) => {
            if (l.item.id !== itemId) return l;
            const next = { ...l, pack_id: packId };
            return { ...next, quantity: Math.max(1, Math.min(l.quantity, maxQuantity(next))) };
          }),
        }));
      },

      setSerials(itemId, serials) {
        set((s) => ({ lines: s.lines.map((l) => (l.item.id === itemId ? { ...l, serials } : l)) }));
      },

      setDiscount(itemId, percent) {
        set((s) => ({ lines: s.lines.map((l) => (l.item.id === itemId ? { ...l, discount_percent: percent } : l)) }));
      },

      remove(itemId) {
        set((s) => ({ lines: s.lines.filter((l) => l.item.id !== itemId) }));
      },

      setCustomer({ name, phone }) {
        set((s) => ({ customerName: name ?? s.customerName, customerPhone: phone ?? s.customerPhone }));
      },

      refreshItem(item) {
        set((s) => ({ lines: s.lines.map((l) => (l.item.id === item.id ? { ...l, item } : l)) }));
      },

      hold(owner, label) {
        const { lines, customerName } = get();
        if (lines.length === 0) return;
        set((s) => ({
          held: [
            { id: crypto.randomUUID(), owner, label, customer_name: customerName, lines, held_at: new Date().toISOString() },
            ...s.held,
          ],
          ...emptyCart(),
        }));
      },

      resume(id) {
        const cart = get().held.find((h) => h.id === id);
        if (!cart) return;
        // Whatever is in the cart now is parked in its place.
        const { lines, customerName } = get();
        const parked = lines.length
          ? [{ id: crypto.randomUUID(), owner: cart.owner, label: null, customer_name: customerName, lines, held_at: new Date().toISOString() }]
          : [];
        set((s) => ({
          held: [...parked, ...s.held.filter((h) => h.id !== id)],
          ...emptyCart(),
          lines: cart.lines,
          customerName: cart.customer_name,
        }));
      },

      discardHeld(id) {
        set((s) => ({ held: s.held.filter((h) => h.id !== id) }));
      },

      putBack(owner, lines, customerName, customerPhone) {
        const current = get();
        const parked = current.lines.length
          ? [{ id: crypto.randomUUID(), owner, label: null, customer_name: current.customerName, lines: current.lines, held_at: new Date().toISOString() }]
          : [];
        // A new key: the refused sale was never recorded.
        set((s) => ({ held: [...parked, ...s.held], ...emptyCart(), lines, customerName, customerPhone }));
      },

      clear() {
        set(emptyCart());
      },
    }),
    {
      name: 'cart',
      version: 3,
      storage: diskStorage,
      // Version 2 is the online counter's, 3 the offline app's: both fit. Older carts do not.
      migrate: (saved, version) => (version === 2 ? (saved as CartState) : { ...emptyCart(), held: [] }),
    },
  ),
);

/**
 * Who parks carts and queues sales: a person at a branch (offline, "main").
 * Whose, at which branch, for which product: a branch can be both a pharmacy
 * and a shop, and what was queued for one is never sent to the other. The
 * pharmacy's keeps the key it had before there was a shop.
 */
export const holdOwner = (userId: string | undefined, branchId: string | null, product: Product = 'pharmacy') =>
  `${product === 'pharmacy' ? '' : `${product}:`}${userId ?? ''}:${branchId ?? ''}`;
