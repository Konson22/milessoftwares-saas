/**
 * The free trial: 90 days from the first opening in offline mode. Kept twice
 * (the database and an encrypted file, src/main/licence/index.ts) and the
 * earlier start wins, so deleting either does not start it again. The latest
 * time the app was seen running spots a clock turned back.
 */

export const TRIAL_DAYS = 90;

const DAY = 24 * 60 * 60 * 1000;

/** A clock may drift or a time zone change; beyond a day back, it was turned back. */
export const CLOCK_TOLERANCE = DAY;

export interface TrialRecord {
  started_at: string;
  last_seen_at: string;
}

export type TrialStatus =
  | { kind: 'running'; endsAt: string; daysLeft: number }
  | { kind: 'ended'; endsAt: string }
  | { kind: 'clock'; lastSeenAt: string };

const valid = (record: TrialRecord | null | undefined): record is TrialRecord =>
  !!record && !Number.isNaN(Date.parse(record.started_at)) && !Number.isNaN(Date.parse(record.last_seen_at));

/** The two copies as one: the earliest start, the latest time seen. Null when neither is readable. */
export function mergeTrial(...records: (TrialRecord | null | undefined)[]): TrialRecord | null {
  const found = records.filter(valid);
  if (found.length === 0) return null;
  const earliest = Math.min(...found.map((r) => Date.parse(r.started_at)));
  const latest = Math.max(...found.map((r) => Date.parse(r.last_seen_at)));
  return { started_at: new Date(earliest).toISOString(), last_seen_at: new Date(latest).toISOString() };
}

export function trialEnds(record: TrialRecord): Date {
  return new Date(Date.parse(record.started_at) + TRIAL_DAYS * DAY);
}

export function trialStatus(record: TrialRecord, now: Date): TrialStatus {
  if (now.getTime() < Date.parse(record.last_seen_at) - CLOCK_TOLERANCE) return { kind: 'clock', lastSeenAt: record.last_seen_at };
  const ends = trialEnds(record);
  const left = ends.getTime() - now.getTime();
  if (left <= 0) return { kind: 'ended', endsAt: ends.toISOString() };
  return { kind: 'running', endsAt: ends.toISOString(), daysLeft: Math.ceil(left / DAY) };
}

/** The record after a look at the clock: seen now, unless the clock went back. */
export function seen(record: TrialRecord, now: Date): TrialRecord {
  return now.getTime() > Date.parse(record.last_seen_at) ? { ...record, last_seen_at: now.toISOString() } : record;
}
