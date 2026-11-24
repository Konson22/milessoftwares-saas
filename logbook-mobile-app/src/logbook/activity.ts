import type { Occurrence } from './work';
import type { ActivitySettings, FuelRate, Item, Kit, KitResource, KitShift, Values } from './types';

/*
 * Activities, fuel estimates and shifts on the phone (ADR-0030 §4.4-§4.7),
 * with no React Native in it. The phone's estimate is a preview made with
 * the rates the kit brought: the server works the figure out again with
 * its own rate history when the entry is submitted and keeps that one. The
 * phone's own number travels with the entry as evidence only.
 */

/** The rate in force at a moment: the latest that started at or before it. */
export function rateAt(rates: FuelRate[] | undefined, at: string): FuelRate | null {
  const t = new Date(at).getTime();
  let found: FuelRate | null = null;
  for (const rate of rates ?? []) {
    if (new Date(rate.effective_from).getTime() <= t) found = rate;
  }
  return found;
}

const number = (v: unknown): number | null => {
  if (v === undefined || v === null || v === '') return null;
  const n = Number(v);
  return Number.isFinite(n) ? n : null;
};

export type Estimate = {
  resource: KitResource | null;
  quantity: number | null;
  unit: 'km' | 'h';
  rate: FuelRate | null;
  /** The resource's rate is for the other measure (by distance against hours). */
  mismatch: boolean;
  estimated: number | null;
  actual: number | null;
  refill: number | null;
  difference: number | null;
  /** The end reading is below the start. */
  endBelowStart: boolean;
  /** The start is below the last end reading the phone knows of: the server will refuse it unless a meter event explains it. */
  belowLast: number | null;
};

/**
 * The estimate for what is typed now: km × rate ÷ 100, or h × rate, with
 * the rate in force when the activity started.
 */
export function estimate(activity: ActivitySettings, resources: KitResource[], values: Values, startedAt: string, endedAt: string | null): Estimate | null {
  const resourceId = values[activity.resource_field];
  if (typeof resourceId !== 'string' || resourceId === '') return null;
  const resource = resources.find((r) => r.id === resourceId) ?? null;
  const method = activity.measure === 'odometer' ? 'distance' : 'hours';
  const unit = method === 'distance' ? 'km' : 'h';

  let quantity: number | null = null;
  let endBelowStart = false;
  let belowLast: number | null = null;
  if (activity.measure === 'duration') {
    if (endedAt) {
      const ms = new Date(endedAt).getTime() - new Date(startedAt).getTime();
      quantity = ms >= 0 ? Math.round(ms / 36_000) / 100 : null;
    }
  } else {
    const start = number(activity.start_field ? values[activity.start_field] : null);
    const end = number(activity.end_field ? values[activity.end_field] : null);
    const last = resource?.meters?.[activity.measure];
    if (start !== null && last !== undefined && start < last) belowLast = last;
    if (start !== null && end !== null) {
      endBelowStart = end < start;
      quantity = endBelowStart ? null : Math.round((end - start) * 10) / 10;
    }
  }

  const rate = rateAt(resource?.fuel, startedAt);
  const mismatch = rate !== null && rate.method !== method;
  const estimated = quantity !== null && rate !== null && !mismatch ? Math.round((method === 'distance' ? (quantity * rate.value) / 100 : quantity * rate.value) * 100) / 100 : null;
  const actual = number(activity.actual_field ? values[activity.actual_field] : null);
  return {
    resource,
    quantity,
    unit,
    rate,
    mismatch,
    estimated,
    actual,
    refill: number(activity.refill_field ? values[activity.refill_field] : null),
    difference: estimated !== null && actual !== null ? Math.round((actual - estimated) * 100) / 100 : null,
    endBelowStart,
    belowLast,
  };
}

/** The shift whose hours hold a moment on the phone's clock, among those of a logbook. */
export function shiftAt(shifts: KitShift[], logbookId: string | null, at: string, me: string | null = null): string | null {
  const mine = shifts.filter((s) => s.logbookId === null || s.logbookId === logbookId);
  const d = new Date(at);
  const hm = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
  const hit = mine.find((s) => (s.ends < s.starts ? hm >= s.starts || hm < s.ends : hm >= s.starts && hm < s.ends));
  if (hit) return hit.id;
  const linked = me ? mine.filter((s) => s.members.includes(me)) : [];
  return linked.length === 1 ? linked[0].id : null;
}

/** The sheet's recording settings from the kit; absent on servers before ADR-0030. */
export function recordingOf(kit: Kit | null, sheetId: string) {
  return kit?.sheets.find((s) => s.id === sheetId)?.recording ?? null;
}

/**
 * The ADR-0030 fields an entry sends. Only what the item has: items made
 * before these existed send nothing new, and older servers ignore them.
 */
export function activityBody(item: Item): Record<string, unknown> {
  const body: Record<string, unknown> = {};
  if (item.scheduleLink) body.schedule_link = item.scheduleLink;
  if (item.shiftId !== undefined) body.shift_id = item.shiftId;
  if (item.activityStartedAt !== undefined) body.activity_started_at = item.activityStartedAt;
  if (item.activityEndedAt !== undefined) body.activity_ended_at = item.activityEndedAt;
  if (item.activityEntryId) body.activity_entry_id = item.activityEntryId;
  if (item.calculation) body.calculation = item.calculation;
  // ADR-0032: the planned trip (only when the entry is made) and the trip's stops.
  if (item.dispatchId && item.serverId === null) body.dispatch_id = item.dispatchId;
  if (item.stops !== undefined) body.stops = item.stops;
  return body;
}

/** The phone's figure kept with the entry (evidence; the server's is the one recorded). */
export function phoneCalculation(e: Estimate | null): Item['calculation'] {
  if (!e || e.estimated === null) return null;
  return { estimated_l: e.estimated, rate_id: e.rate?.id ?? null, rate: e.rate?.value ?? null, quantity: e.quantity };
}

/** States in which an expected reading can still be taken. */
const OPEN = ['upcoming', 'due', 'overdue', 'missed'];

/**
 * The expected readings an entry on this sheet can be for (ADR-0030 §4.3):
 * the sheet's due and missed ones from the last fetch, soonest first.
 */
export function readingChoices(occurrences: Occurrence[], sheetId: string, keep: string | null = null): Occurrence[] {
  const seen = new Set<string>();
  return occurrences
    .filter((o) => o.sheet.id === sheetId && (o.id === keep || OPEN.includes(o.state)))
    .filter((o) => (seen.has(o.id) ? false : (seen.add(o.id), true)))
    .sort((a, b) => a.dueAt.localeCompare(b.dueAt));
}

/** The reading whose window holds the time, when exactly one does. */
export function readingAt(choices: Occurrence[], at: string): string | null {
  const t = new Date(at).getTime();
  const hits = choices.filter((o) => new Date(o.opensAt ?? o.dueAt).getTime() <= t && t <= new Date(o.closesAt).getTime());
  return hits.length === 1 ? hits[0].id : null;
}
