import type { Item, Kit } from '@/logbook/types';

/*
 * ADR-0032 on the phone: vehicles assigned to the driver, trips a manager
 * planned, and the stops of a trip. All of it works offline from the kit;
 * the server checks it all again when the trip is sent.
 */

/** A point the trip stopped at. Times are ISO; the server keeps them in UTC. */
export type Stop = {
  place: string;
  arrived_at: string;
  departed_at: string | null;
  odometer: number | null;
  purpose: string | null;
  note: string | null;
  pin: { lat: number; lng: number; accuracy_m: number | null } | null;
};

/** A standing assignment from the kit: days inclusive, `to` null means until ended. */
export type Assignment = { resourceId: string; from: string; to: string | null };

/** A planned trip from the kit (the driver's own, still to record). */
export type Dispatch = {
  id: string;
  status: 'planned' | 'started' | 'done' | 'cancelled';
  day: string;
  time: string | null;
  destination: string | null;
  purpose: string | null;
  sheetId: string;
  sheet: string | null;
  resourceId: string;
  resource: string | null;
  entryId: string | null;
};

/** The phone's own calendar day of an ISO time, YYYY-MM-DD. */
export function localDay(iso: string): string {
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Whether the sheet lets a person record only the assets assigned to them. */
export function assignedOnly(kit: Kit | null, sheetId: string): boolean {
  return kit?.sheets.find((s) => s.id === sheetId)?.assignedOnly === true;
}

/**
 * Ids of the assets assigned to the person on a day: standing assignments
 * covering it, and planned trips (not cancelled) on it.
 */
export function assignedOn(kit: Kit | null, day: string): string[] {
  const standing = (kit?.assignments ?? []).filter((a) => a.from <= day && (a.to === null || a.to >= day)).map((a) => a.resourceId);
  const planned = (kit?.dispatches ?? []).filter((d) => d.day === day && d.status !== 'cancelled').map((d) => d.resourceId);
  return [...new Set([...standing, ...planned])];
}

/** Planned trips still to start on this phone: none of its entries records them yet. */
export function tripsToDo(kit: Kit | null, items: Item[]): Dispatch[] {
  const begun = new Set(items.map((i) => i.dispatchId).filter(Boolean));
  return (kit?.dispatches ?? []).filter((d) => d.status === 'planned' && !begun.has(d.id));
}

/** The planned trip an entry records, from the kit. */
export function dispatchOf(kit: Kit | null, id: string | null | undefined): Dispatch | null {
  return id ? ((kit?.dispatches ?? []).find((d) => d.id === id) ?? null) : null;
}

/** The sheet's stops setting; absent from older servers means none. */
export function stopsSetting(kit: Kit | null, sheetId: string): 'off' | 'optional' | 'required' {
  return kit?.sheets.find((s) => s.id === sheetId)?.recording?.stops ?? 'off';
}

const time = (iso: string) => {
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

/**
 * The server's checks on a trip's stops (logbook-app App\Logbook\Stops),
 * done before sending so the driver can fix them offline. Keys follow the
 * server's: "stops", "stops.N.place", "stops.N.arrived_at"...
 */
export function stopProblems(
  stops: Stop[],
  trip: { startedAt: string; endedAt: string | null; odometerStart: number | null; odometerEnd: number | null },
  setting: 'off' | 'optional' | 'required',
): Record<string, string> {
  const out: Record<string, string> = {};
  if (setting === 'off') return out;
  if (setting === 'required' && stops.length === 0) out.stops = 'Record at least one stop of this trip.';
  // ISO times from the server and from the phone are written differently: compare instants.
  const ms = (iso: string) => new Date(iso).getTime();
  let lastTime: number | null = null;
  let lastOdo = trip.odometerStart;
  stops.forEach((s, i) => {
    const at = `stops.${i}`;
    const arrived = ms(s.arrived_at);
    const left = s.departed_at ? ms(s.departed_at) : null;
    if (s.place.trim() === '') out[`${at}.place`] = 'Name the place.';
    if (left !== null && left < arrived) out[`${at}.departed_at`] = 'The departure is before the arrival.';
    if (arrived < ms(trip.startedAt)) out[`${at}.arrived_at`] = `This stop is before the trip started (${time(trip.startedAt)}).`;
    else if (trip.endedAt && (left ?? arrived) > ms(trip.endedAt)) out[`${at}.arrived_at`] = `This stop is after the trip ended (${time(trip.endedAt)}).`;
    else if (lastTime !== null && arrived < lastTime) out[`${at}.arrived_at`] = 'Stops go in the order they happened: this one is before the previous stop.';
    lastTime = left ?? arrived;
    if (s.odometer !== null) {
      if (lastOdo !== null && s.odometer < lastOdo) out[`${at}.odometer`] = `The odometer is below the previous reading (${lastOdo}).`;
      else if (trip.odometerEnd !== null && s.odometer > trip.odometerEnd) out[`${at}.odometer`] = `The odometer is above the reading at the end of the trip (${trip.odometerEnd}).`;
      lastOdo = s.odometer;
    }
  });
  return out;
}

/** Km from the previous point (the trip's start reading for the first). */
export function legs(stops: Stop[], odometerStart: number | null): (number | null)[] {
  let last = odometerStart;
  return stops.map((s) => {
    const leg = s.odometer !== null && last !== null ? Math.round((s.odometer - last) * 10) / 10 : null;
    if (s.odometer !== null) last = s.odometer;
    return leg;
  });
}
