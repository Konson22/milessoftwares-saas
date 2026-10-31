/**
 * Prices come from the batches sold: each batch has its own selling price, so
 * units are priced batch by batch, soonest to expire first, as they leave the
 * shelf. The cart and the sale use this same sum, so the total shown is the
 * total charged.
 */

/** So many units at a price a unit (base currency, minor units; may carry fractions: a box of 3 at 100). */
export interface PriceTier {
  units: number;
  unit_price: number;
}

/**
 * The price of `units`, after `skip` units already priced (an earlier line of
 * the same product). Units beyond the tiers take the last tier's price, or
 * `fallback` when there are none.
 */
export function priceForUnits(tiers: PriceTier[], fallback: number, units: number, skip = 0): number {
  let left = units;
  let offset = skip;
  let total = 0;
  for (const tier of tiers) {
    let here = tier.units;
    if (offset > 0) {
      const skipped = Math.min(offset, here);
      here -= skipped;
      offset -= skipped;
    }
    const take = Math.min(here, left);
    total += take * tier.unit_price;
    left -= take;
    if (left === 0) break;
  }
  if (left > 0) total += left * (tiers.length ? tiers[tiers.length - 1].unit_price : fallback);
  return Math.round(total);
}

/** A currency the shop takes amounts in: its own, and one other at a rate (1 other = rate own). */
export interface Money {
  base: { code: string; decimals: number };
  foreign: { code: string; decimals: number; rate: number } | null;
}

/** An amount in minor units of `currency`, in the base currency's minor units (fractions kept). */
export function toBase(money: Money, amountMinor: number, currency: string | null | undefined): number {
  const f = money.foreign;
  if (!currency || currency === money.base.code || !f || currency !== f.code) return amountMinor;
  return (amountMinor / 10 ** f.decimals) * f.rate * 10 ** money.base.decimals;
}

/** An amount in base minor units, in `currency` (fractions kept). */
export function fromBase(money: Money, baseMinor: number, currency: string): number {
  const f = money.foreign;
  if (currency === money.base.code || !f || currency !== f.code || f.rate <= 0) return baseMinor;
  return (baseMinor / 10 ** money.base.decimals / f.rate) * 10 ** f.decimals;
}

export function decimalsOf(money: Money, currency: string): number {
  return money.foreign && currency === money.foreign.code ? money.foreign.decimals : money.base.decimals;
}

/**
 * A cart line's price before discount: `quantity` units, or packs of
 * `pack.units`, after `skip` units of the same product. A pack keeps its
 * saving over single units whatever the batch costs: a strip of 10 listed at
 * 900 when one is 100 sells at nine tenths of each batch's price.
 */
export function lineGross(tiers: PriceTier[], unitPrice: number, pack: { units: number; price_minor: number } | null, quantity: number, skip = 0): number {
  if (!pack) return priceForUnits(tiers, unitPrice, quantity, skip);
  if (unitPrice <= 0) return quantity * pack.price_minor;
  const factor = pack.price_minor / (pack.units * unitPrice);
  const scaled = tiers.map((t) => ({ units: t.units, unit_price: t.unit_price * factor }));
  return priceForUnits(scaled, unitPrice * factor, quantity * pack.units, skip);
}
