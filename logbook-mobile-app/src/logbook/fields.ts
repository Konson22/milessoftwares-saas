import type { FieldDef, Values } from './types';

/*
 * The server's field rules (logbook-app App\Logbook\Fields::value and
 * ::clean), on the phone, so an entry written offline is checked before it
 * is queued, with the same messages. The server checks again on submission
 * and is the one that decides.
 */

export const TEXT_MAX = 1000;
export const TEXTAREA_MAX = 10000;
export const MAX_FILES = 10;

export const filled = (value: unknown) => !(value === undefined || value === null || value === '' || (Array.isArray(value) && value.length === 0));

type Result = { ok: true; value: unknown } | { ok: false; error: string };

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Whether a field shows, given the answers so far: the server's
 * Fields::visible(). A hidden field is not asked and its value is cleared.
 */
export function visible(field: FieldDef, values: Values, fields: FieldDef[] = []): boolean {
  const condition = field.show_if;
  if (!condition || typeof condition.field !== 'string') return true;
  const parent = fields.find((f) => f.name === condition.field);
  if (parent && !visible(parent, values, fields)) return false;
  const answer = values[condition.field];
  const listed = (condition.in ?? []).map(String);
  if (typeof answer === 'boolean') return listed.includes(answer ? 'yes' : 'no');
  if (Array.isArray(answer)) return answer.some((a) => listed.includes(String(a)));
  if (typeof answer === 'string' || typeof answer === 'number') return listed.includes(String(answer));
  return false;
}

const ok = (value: unknown): Result => ({ ok: true, value });
const no = (error: string): Result => ({ ok: false, error });

function validDate(value: string): boolean {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!m) return false;
  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const date = new Date(Date.UTC(y, mo - 1, d));
  return date.getUTCFullYear() === y && date.getUTCMonth() === mo - 1 && date.getUTCDate() === d;
}

/** An integer as a number; a decimal as a normalized string (exact, as the server keeps it). */
export function number(type: 'integer' | 'decimal', raw: unknown): number | string | null {
  if (typeof raw === 'number') {
    if (!Number.isFinite(raw)) return null;
    if (type === 'integer') return Number.isInteger(raw) ? raw : null;
    raw = String(raw);
  }
  if (typeof raw !== 'string') return null;
  const text = raw.trim().replace(',', '.');
  if (type === 'integer') return /^-?\d{1,15}$/.test(text) ? Number(text) : null;
  const m = /^(-?)(\d{1,15})(?:\.(\d{1,6}))?$/.exec(text);
  if (!m) return null;
  const fraction = (m[3] ?? '').replace(/0+$/, '');
  const whole = m[2].replace(/^0+/, '') || '0';
  const sign = whole === '0' && fraction === '' ? '' : m[1];
  return `${sign}${whole}${fraction ? `.${fraction}` : ''}`;
}

/** One value checked against its field. */
export function check(field: FieldDef, raw: unknown): Result {
  switch (field.type) {
    case 'text':
    case 'textarea': {
      if (typeof raw !== 'string' && typeof raw !== 'number' && typeof raw !== 'boolean') return no('Enter text.');
      let text = String(raw).replace(/\0/g, '').trim();
      if (field.type === 'text') text = text.replace(/\s+/g, ' ');
      const max = Number(field.max_length ?? (field.type === 'text' ? TEXT_MAX : TEXTAREA_MAX));
      return [...text].length > max ? no(`At most ${max} characters.`) : ok(text);
    }
    case 'integer':
    case 'decimal': {
      const n = number(field.type, raw);
      if (n === null) return no(field.type === 'integer' ? 'Enter a whole number.' : 'Enter a number.');
      if (field.min !== undefined && field.min !== null && field.min !== '' && Number(n) < Number(field.min)) return no(`At least ${field.min}.`);
      if (field.max !== undefined && field.max !== null && field.max !== '' && Number(n) > Number(field.max)) return no(`At most ${field.max}.`);
      return ok(n);
    }
    case 'date':
      return typeof raw === 'string' && validDate(raw) ? ok(raw) : no('Enter a date (YYYY-MM-DD).');
    case 'time':
      return typeof raw === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(raw) ? ok(raw) : no('Enter a time (HH:MM).');
    case 'datetime': {
      const m = typeof raw === 'string' ? /^(\d{4}-\d{2}-\d{2})[T ]([01]\d|2[0-3]):([0-5]\d)(:\d{2})?$/.exec(raw) : null;
      return m && validDate(m[1]) ? ok(`${m[1]}T${m[2]}:${m[3]}`) : no('Enter a date and time.');
    }
    case 'select_one': {
      const allowed = (field.choices ?? []).map((c) => c.value);
      return typeof raw === 'string' && allowed.includes(raw) ? ok(raw) : no('Choose one of the options.');
    }
    case 'select_multiple': {
      const allowed = (field.choices ?? []).map((c) => c.value);
      if (!Array.isArray(raw) || raw.some((v) => typeof v !== 'string' || !allowed.includes(v))) return no('Choose from the options.');
      // Kept in the template's order.
      return ok(allowed.filter((v) => raw.includes(v)));
    }
    case 'yes_no':
      if (raw === true || raw === 'yes') return ok(true);
      if (raw === false || raw === 'no') return ok(false);
      return no('Answer yes or no.');
    case 'user':
      return typeof raw === 'string' && UUID.test(raw) ? ok(raw) : no('Choose a person.');
    case 'resource':
      return typeof raw === 'string' && UUID.test(raw) ? ok(raw) : no('Choose a resource.');
    case 'file':
      return ok(raw);
  }
  return no('This field needs a newer app.');
}

/**
 * The values of an entry, cleaned, with the errors by field. File fields
 * count the files the entry has (`fileCounts`), never a value.
 */
export function clean(fields: FieldDef[], input: Values, fileCounts: Record<string, number>, requireAll: boolean): { values: Values; errors: Record<string, string> } {
  const values: Values = {};
  const errors: Record<string, string> = {};
  for (const field of fields) {
    // Hidden by its condition: not required, and cleared (conditions name earlier fields only).
    if (!visible(field, values, fields)) continue;
    if (field.type === 'file') {
      const count = fileCounts[field.name] ?? 0;
      if (count === 0 && requireAll && field.required) errors[field.name] = 'Attach at least one file.';
      else if (count > MAX_FILES) errors[field.name] = `At most ${MAX_FILES} files.`;
      continue;
    }
    const raw = input[field.name];
    if (!filled(raw)) {
      if (requireAll && field.required) errors[field.name] = 'This field is required.';
      continue;
    }
    const result = check(field, raw);
    if (result.ok) values[field.name] = result.value;
    else errors[field.name] = result.error;
  }
  return { values, errors };
}

/** A new entry's values: the template's defaults. */
export function defaults(fields: FieldDef[]): Values {
  const out: Values = {};
  for (const field of fields) if (field.default !== undefined && field.default !== null) out[field.name] = field.default;
  return out;
}

/** A value as people read it. */
export function text(field: FieldDef, value: unknown, people: Record<string, string> = {}): string | null {
  if (!filled(value)) return null;
  const labels = Object.fromEntries((field.choices ?? []).map((c) => [c.value, c.label]));
  switch (field.type) {
    case 'select_one':
      return labels[String(value)] ?? String(value);
    case 'select_multiple':
      return (Array.isArray(value) ? value : [value]).map((v) => labels[String(v)] ?? String(v)).join(', ');
    case 'yes_no':
      return value === true ? 'Yes' : 'No';
    case 'user':
      return people[String(value)] ?? 'Unknown person';
    case 'resource':
      return people[String(value)] ?? 'Unknown resource';
    case 'datetime':
      return String(value).replace('T', ' ');
    case 'file':
      return null;
    default:
      return typeof value === 'object' ? JSON.stringify(value) : String(value as string | number);
  }
}

/** The first answers as text: what an entry is about, in lists. */
export function summary(fields: FieldDef[], values: Values, people: Record<string, string> = {}): string {
  const parts: string[] = [];
  for (const field of fields) {
    if (field.type === 'file' || field.type === 'user' || field.type === 'yes_no') continue;
    const t = text(field, values[field.name], people);
    if (t) parts.push(t);
    if (parts.length === 3) break;
  }
  const joined = parts.join(' · ');
  return joined.length > 120 ? `${joined.slice(0, 119)}…` : joined;
}

/** The phone's current date, time or both, as the fields take them. */
export function nowFor(type: 'date' | 'time' | 'datetime', now = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  const date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  const time = `${pad(now.getHours())}:${pad(now.getMinutes())}`;
  return type === 'date' ? date : type === 'time' ? time : `${date}T${time}`;
}
