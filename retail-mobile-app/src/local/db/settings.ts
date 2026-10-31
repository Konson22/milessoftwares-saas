import { decimalsFor } from '@/lib/currencies';
import type { Money } from '@/lib/pricing';
import type { PaymentMethod } from '@/lib/types';
import { one, run } from './database';

/** The shop and how its counter works: set up on first run, changed under Settings. */
export interface ShopSettings {
  name: string;
  address: string | null;
  contact: string | null;
  currency: string;
  /** Minor-unit digits of the currency (2, or 0 for UGX, RWF…). */
  decimals: number;
  /** A second currency suppliers bill in and prices show in (USD), at `exchange_rate`. */
  second_currency: string | null;
  /** 1 of the second currency in the shop's own; null until set. */
  exchange_rate: number | null;

  receipt_header: string | null;
  receipt_footer: string | null;
  paper_size: '80mm' | '58mm';
  show_customer: boolean;
  show_staff: boolean;

  payment_methods: PaymentMethod[];
  /** The most a supervisor or manager may take off a line. */
  max_discount_percent: number;
  shifts_required: boolean;
  default_float_minor: number;
  /** Cashiers count the drawer without seeing what it should hold. */
  blind_close: boolean;
  require_customer_always: boolean;
  require_customer_above_minor: number | null;
  expiring_days: number;
  /** An invoice is due this many days after it is issued. */
  credit_days: number;
  /** A quotation holds its prices this many days. */
  quote_days: number;
}

export const PAYMENT_LABELS: Record<PaymentMethod, string> = {
  cash: 'Cash',
  mobile_money: 'Mobile money',
  card: 'Card',
  bank_transfer: 'Bank transfer',
  other: 'Other',
};

/** A sale's payment method, 'account' (an invoice) among them. */
export const methodLabel = (method: string): string => (method === 'account' ? 'On account' : (PAYMENT_LABELS[method as PaymentMethod] ?? method));

export const DEFAULT_SETTINGS: ShopSettings = {
  name: 'My Shop',
  address: null,
  contact: null,
  currency: 'SSP',
  decimals: 2,
  second_currency: 'USD',
  exchange_rate: null,
  receipt_header: null,
  receipt_footer: 'Thank you for shopping with us.',
  paper_size: '80mm',
  show_customer: true,
  show_staff: true,
  payment_methods: ['cash', 'mobile_money', 'card', 'bank_transfer'],
  max_discount_percent: 10,
  shifts_required: true,
  default_float_minor: 0,
  blind_close: true,
  require_customer_always: false,
  require_customer_above_minor: null,
  expiring_days: 30,
  credit_days: 30,
  quote_days: 14,
};

let cache: ShopSettings | null = null;

export function settings(): ShopSettings {
  if (!cache) {
    const row = one<{ value: string }>("SELECT value FROM settings WHERE key = 'shop'");
    cache = { ...DEFAULT_SETTINGS, ...(row ? (JSON.parse(row.value) as Partial<ShopSettings>) : {}) };
  }
  return cache;
}

export function saveSettings(next: ShopSettings) {
  run("INSERT INTO settings (key, value) VALUES ('shop', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value", JSON.stringify(next));
  cache = next;
}

/** Forgets what was read (after a restore). */
export function forgetSettings() {
  cache = null;
}

export function getValue(key: string): string | null {
  return one<{ value: string }>('SELECT value FROM settings WHERE key = ?', key)?.value ?? null;
}

export function setValue(key: string, value: string) {
  run('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', key, value);
}

/** "SSP 1,250.00", for messages. */
export function formatMoney(minor: number): string {
  const { currency, decimals } = settings();
  const amount = (minor / 10 ** decimals).toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
  return `${currency} ${amount}`;
}

/** The shop's currency, and the second one when its rate is set. */
export function money(): Money {
  const s = settings();
  const second = s.second_currency;
  return {
    base: { code: s.currency, decimals: s.decimals },
    foreign: second && second !== s.currency && s.exchange_rate && s.exchange_rate > 0 ? { code: second, decimals: decimalsFor(second), rate: s.exchange_rate } : null,
  };
}

/** The one branch: the shop itself. */
export function branch() {
  const s = settings();
  return { id: 'main', name: s.name, code: 'MAIN', city: s.address };
}
