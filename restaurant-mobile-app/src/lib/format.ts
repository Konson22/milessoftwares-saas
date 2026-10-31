// The restaurant's currency, set at sign-in. Amounts travel in minor units.
let currency = 'SSP';
let decimals = 2;

export function setCurrency(code: string | undefined, digits: number | undefined) {
  if (code) currency = code;
  if (digits !== undefined) decimals = digits;
}

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

/** What someone typed ("12.5") → minor units (1250); null when it is not an amount. */
export function toMinor(text: string): number | null {
  const clean = text.replace(/[,\s]/g, '');
  if (!/^\d{1,12}(\.\d*)?$/.test(clean)) return null;
  return Math.round(Number(clean) * 10 ** decimals);
}

/** An amount as the restaurant takes it: digits and a point, no thousands commas. */
export const amountInput = (text: string) => text.replace(/[,\s]/g, '');

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
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
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

/** Whole minutes since a time. */
export const minutesSince = (iso: string) => Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));

/** How long ago: "12 min", "1 h 5 min". */
export function since(iso: string): string {
  const minutes = minutesSince(iso);
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

export function initials(name: string) {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join('');
}

export const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;
