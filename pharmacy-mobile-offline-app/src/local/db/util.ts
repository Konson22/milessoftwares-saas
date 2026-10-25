import { randomUUID } from 'expo-crypto';

/** An error the window shows as the pharmacy's answer: a message, per-field errors, a status. */
export class LocalError extends Error {
  status: number;
  errors: Record<string, string[]>;
  code: string | null;

  constructor(message: string, status = 422, errors: Record<string, string[]> = {}, code: string | null = null) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.code = code;
  }
}

export const invalid = (field: string, message: string) => new LocalError(message, 422, { [field]: [message] });

export function guard(allowed: boolean) {
  if (!allowed) throw new LocalError('You may not do that.', 403);
}

export const notFound = () => new LocalError('Not found.', 404);

/** Collects every field's problem before answering, as a form's validation does. */
export class Validation {
  errors: Record<string, string[]> = {};

  add(field: string, message: string) {
    (this.errors[field] ??= []).push(message);
  }

  check() {
    const first = Object.values(this.errors)[0]?.[0];
    if (first) throw new LocalError(first, 422, this.errors);
  }
}

export const uuid = () => randomUUID();
export const now = () => new Date().toISOString();

/** Today on this phone's calendar, YYYY-MM-DD. */
export function today(offsetDays = 0): string {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return localDate(d);
}

export function localDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function addDays(day: string, days: number): string {
  const d = new Date(`${day}T00:00:00`);
  d.setDate(d.getDate() + days);
  return localDate(d);
}

export const isDay = (value: unknown): value is string => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value));

export const percentOf = (minor: number, percent: number) => Math.round((minor * percent) / 100);

/** "1,250.50" as minor units of a currency with so many decimals; null when it is not an amount. */
export function toMinor(input: unknown, decimals: number): number | null {
  if (typeof input === 'number') return Number.isFinite(input) ? Math.round(input * 10 ** decimals) : null;
  if (typeof input !== 'string') return null;
  const text = input.replace(/[,\s]/g, '');
  if (!/^\d+(\.\d+)?$/.test(text)) return null;
  return Math.round(Number(text) * 10 ** decimals);
}

export function toMajor(minor: number, decimals: number): string {
  return (minor / 10 ** decimals).toFixed(decimals);
}

/** Trimmed text, or null when empty. */
export function text(value: unknown): string | null {
  if (typeof value !== 'string' && typeof value !== 'number') return null;
  const trimmed = String(value).trim();
  return trimmed === '' ? null : trimmed;
}

export function int(value: unknown): number | null {
  const n = typeof value === 'number' ? value : typeof value === 'string' && value.trim() !== '' ? Number(value) : NaN;
  return Number.isInteger(n) ? n : null;
}

export const bool = (value: unknown) => value === true || value === 1 || value === '1' || value === 'true';
export const flag = (value: boolean) => (value ? 1 : 0);
