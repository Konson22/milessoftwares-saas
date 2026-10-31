import { decimalsFor } from '../../../shared/currencies';
import { fromBase, type Money } from '../../../shared/pricing';
import type { Organization } from '../../../shared/types';

// The pharmacy's currency, set at sign-in (GET me online). Amounts travel in minor units.
let currency = 'SSP';
let decimals = 2;

let second: NonNullable<Organization['second_currency']> | null = null;

export function setCurrency(org: Pick<Organization, 'currency' | 'decimals' | 'second_currency'>) {
  currency = org.currency;
  decimals = org.decimals;
  second = org.second_currency ?? null;
}

/** The pharmacy's currency and the second one at its rate, for converting. */
export const moneyConfig = (): Money => ({ base: { code: currency, decimals }, foreign: second });
export const secondCurrency = () => second;

export const currencyCode = () => currency;
export const currencyDecimals = () => decimals;

/** 125000 → "SSP 1,250.00" */
export function money(minor: number | null | undefined, withCode = true): string {
  const value = ((minor ?? 0) / 10 ** decimals).toLocaleString('en-US', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
  return withCode ? `${currency} ${value}` : value;
}

/** An amount in minor units of `code` ("USD 12.50"); fractions are rounded. */
export function moneyIn(minor: number, code: string): string {
  const digits = decimalsFor(code);
  const value = (Math.round(minor) / 10 ** digits).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
  return `${code} ${value}`;
}

/** An amount in the pharmacy's currency, in the second one ("≈ USD 0.77"); null without a rate. */
export function inSecond(minor: number | null | undefined): string | null {
  if (!second || minor === null || minor === undefined) return null;
  return `≈ ${moneyIn(fromBase(moneyConfig(), minor, second.code), second.code)}`;
}

/** What someone typed, as minor units of a currency with so many decimals; null when it is not an amount. */
export function toMinorIn(text: string, digits: number): number | null {
  const clean = text.replace(/[,\s]/g, '');
  if (!/^\d{1,12}(\.\d*)?$/.test(clean)) return null;
  return Math.round(Number(clean) * 10 ** digits);
}

/** What someone typed ("12.5") → minor units (1250); null when it is not an amount. */
export function toMinor(text: string): number | null {
  const clean = text.replace(/[,\s]/g, '');
  if (!/^\d{1,12}(\.\d*)?$/.test(clean)) return null;
  return Math.round(Number(clean) * 10 ** decimals);
}

/** Minor units → what goes back in an input ("12.50"). */
export function toMajorInput(minor: number): string {
  return (minor / 10 ** decimals).toFixed(decimals);
}

/** A discount as the server computes it: rounded half up. */
export function percentOf(minor: number, percent: number): number {
  return Math.floor((minor * percent + 50) / 100);
}

export function time(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

export function dateTime(iso: string): string {
  return new Date(iso).toLocaleString('en-US', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

export function isoDate(d = new Date()): string {
  const local = new Date(d.getTime() - d.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 10);
}

export function shiftDate(iso: string, days: number): string {
  const d = new Date(`${iso}T12:00:00`);
  d.setDate(d.getDate() + days);
  return isoDate(d);
}

export function prettyDate(iso: string): string {
  if (iso === isoDate()) return 'Today';
  return new Date(`${iso}T12:00:00`).toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short' });
}

/** Days until a YYYY-MM-DD date; negative when past. */
export function daysUntil(date: string): number {
  return Math.round((new Date(`${date}T00:00:00`).getTime() - new Date(`${isoDate()}T00:00:00`).getTime()) / 86_400_000);
}

export function initials(name: string) {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('');
}
