import { one, run } from './database';

/** The restaurant on this phone: set up on first run, changed under Settings. */
export interface RestaurantSettings {
  name: string;
  address: string | null;
  phone: string | null;
  /** Fixed once set up: every amount is in it. */
  currency: string;
  /** Minor-unit digits of the currency (2, or 0 for UGX, RWF…). */
  decimals: number;
  /** Printed at the bottom of a bill. */
  bill_footer: string | null;
}

export const DEFAULT_SETTINGS: RestaurantSettings = {
  name: 'My Restaurant',
  address: null,
  phone: null,
  currency: 'SSP',
  decimals: 2,
  bill_footer: 'Thank you!',
};

let cache: RestaurantSettings | null = null;

export function settings(): RestaurantSettings {
  if (!cache) {
    const row = one<{ value: string }>("SELECT value FROM settings WHERE key = 'restaurant'");
    cache = { ...DEFAULT_SETTINGS, ...(row ? (JSON.parse(row.value) as Partial<RestaurantSettings>) : {}) };
  }
  return cache;
}

export function saveSettings(next: RestaurantSettings) {
  run("INSERT INTO settings (key, value) VALUES ('restaurant', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", JSON.stringify(next));
  cache = next;
}

/** Forgets what was read (after a restore). */
export function forgetSettings() {
  cache = null;
}

/** "SSP 1,250.00", for messages. */
export function formatMoney(minor: number): string {
  const { currency, decimals } = settings();
  const amount = (minor / 10 ** decimals).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  return `${currency} ${amount}`;
}

/**
 * An amount typed in major units, as restaurant-app's Money::toMinor takes
 * it: digits, and no more decimals than the currency has. Null when it is
 * not an amount.
 */
export function amountToMinor(input: unknown): number | null {
  if (typeof input !== 'string' && typeof input !== 'number') return null;
  const text = String(input).trim();
  const { decimals } = settings();
  const pattern = decimals === 0 ? /^\d{1,12}$/ : new RegExp(`^\\d{1,12}(\\.\\d{1,${decimals}})?$`);
  if (!pattern.test(text)) return null;
  const [whole, fraction = ''] = text.split('.');
  return Number(whole) * 10 ** decimals + Number(fraction.padEnd(decimals, '0') || 0);
}

/** 1250 → "12.50", for a form's input. */
export function toMajor(minor: number): string {
  const { decimals } = settings();
  return (minor / 10 ** decimals).toFixed(decimals);
}

/** The one branch: the restaurant itself. */
export function branch() {
  const s = settings();
  return { id: 'main', name: s.name, code: 'MAIN', city: s.address };
}
