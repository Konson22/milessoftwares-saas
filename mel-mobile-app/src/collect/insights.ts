import type { Item, Kit } from './types';

/*
 * The Insights tab's numbers. Review totals come from the server's
 * per-form progress in the kit (they include records the phone has since
 * cleared); the daily activity from the kit too, or, from an older server,
 * from the records still on this phone.
 */

export type Outcome = 'pending' | 'correction' | 'rejected' | 'accepted';

/** Review outcomes in workflow order, each with its step of the app's teal ramp (validated as an ordinal ramp). */
export const OUTCOMES: { key: Outcome; label: string; color: string }[] = [
  { key: 'pending', label: 'Awaiting review', color: '#63b9cc' },
  { key: 'correction', label: 'Sent back', color: '#0a7f9c' },
  { key: 'rejected', label: 'Rejected', color: '#04586f' },
  { key: 'accepted', label: 'Accepted', color: '#093c4c' },
];

export type FormInsight = {
  assignmentId: string;
  formName: string;
  unit: string | null;
  counts: Record<Outcome, number>;
  received: number;
  /** Sent and not rejected: what counts toward a target. */
  done: number;
  expected: number | null;
};

export type Insights = {
  totals: Record<Outcome, number> & { received: number };
  /** Accepted out of everything reviewed, 0–100; null before any review. */
  acceptanceRate: number | null;
  activity: { date: string; count: number }[];
  activityTotal: number;
  activeDays: number;
  /** Consecutive days with something sent, up to today (or yesterday). */
  streak: number;
  forms: FormInsight[];
  fromServer: boolean;
};

function ymd(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** The phone's local day of a timestamp. */
function localDay(iso: string): string {
  return ymd(new Date(iso));
}

/** The n days up to and including today (YYYY-MM-DD), oldest first. */
export function lastDays(today: string, n: number): string[] {
  const [y, m, d] = today.split('-').map(Number);
  return Array.from({ length: n }, (_, i) => ymd(new Date(y, m - 1, d - (n - 1 - i))));
}

export function insightsOf(kit: Kit | null, items: Item[], today: string): Insights {
  const forms: FormInsight[] = (kit?.assignments ?? []).map((a) => {
    const p = a.progress ?? { received: 0, pending: 0, accepted: 0, correction: 0, rejected: 0 };
    const counts = { pending: p.pending, correction: p.correction, rejected: p.rejected, accepted: p.accepted };
    return {
      assignmentId: a.id,
      formName: a.form.name,
      unit: a.unit?.label ?? null,
      counts,
      received: p.received,
      done: p.pending + p.correction + p.accepted,
      expected: a.expected ?? null,
    };
  });

  const totals = { pending: 0, correction: 0, rejected: 0, accepted: 0, received: 0 };
  for (const f of forms) {
    for (const o of OUTCOMES) totals[o.key] += f.counts[o.key];
    totals.received += f.received;
  }
  const reviewed = totals.accepted + totals.rejected + totals.correction;

  const days = lastDays(today, 30);
  let activity: { date: string; count: number }[];
  const fromServer = Array.isArray(kit?.activity) && kit.activity.length > 0;
  if (fromServer) {
    const byDay = new Map(kit!.activity!.map((a) => [a.date, a.count]));
    activity = days.map((date) => ({ date, count: byDay.get(date) ?? 0 }));
  } else {
    const byDay = new Map<string, number>();
    for (const item of items) {
      if (!item.completedAt || item.status === 'draft') continue;
      const day = localDay(item.completedAt);
      byDay.set(day, (byDay.get(day) ?? 0) + 1);
    }
    activity = days.map((date) => ({ date, count: byDay.get(date) ?? 0 }));
  }

  let streak = 0;
  const back = [...activity].reverse();
  const start = back[0]?.count ? 0 : 1; // today may still be empty
  for (let i = start; i < back.length && back[i].count > 0; i++) streak++;

  return {
    totals,
    acceptanceRate: reviewed === 0 ? null : Math.round((totals.accepted / reviewed) * 100),
    activity,
    activityTotal: activity.reduce((s, a) => s + a.count, 0),
    activeDays: activity.filter((a) => a.count > 0).length,
    streak,
    forms: forms.sort((x, y) => y.received - x.received),
    fromServer,
  };
}
