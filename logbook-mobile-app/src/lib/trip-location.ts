import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';

import { SqliteStore } from '@/logbook/sqlite-store';
import { noteTrack, recordFixes, type Fix } from '@/logbook/tracks';

/*
 * The location service behind a trip (ADR-0028). Only ever started by the
 * person pressing Start or Resume, with the app open; Android shows its
 * notification for as long as it runs. It uses "while in use" permission
 * with a foreground service: background location permission is never asked
 * for. Pausing or ending stops it, so nothing is read in between.
 *
 * The task is defined when this module loads; index.js imports it before the
 * app starts, so Android can deliver positions even when it restarts the JS alone.
 */

export const TRIP_TASK = 'miles-logbook-trip';

const store = new SqliteStore();
const listeners = new Set<() => void>();

/** Called when positions arrive, for the screens to refresh. */
export function onTripChange(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}
const changed = () => listeners.forEach((l) => l());

type TaskBody = { locations?: Location.LocationObject[] };

if (!TaskManager.isTaskDefined(TRIP_TASK)) {
  TaskManager.defineTask<TaskBody>(TRIP_TASK, async ({ data, error }) => {
    const active = await store.active().catch(() => null);
    if (!active) return;
    if (error) {
      // Android reports a lost permission through the task.
      await noteTrack(store, active.scope, active.id, 'permission_lost', new Date()).catch(() => undefined);
      changed();
      return;
    }
    const fixes: Fix[] = (data?.locations ?? []).map((l) => ({
      lat: l.coords.latitude,
      lng: l.coords.longitude,
      accuracy: l.coords.accuracy ?? null,
      speed: l.coords.speed !== null && l.coords.speed >= 0 ? l.coords.speed : null,
      altitude: l.coords.altitude ?? null,
      at: new Date(l.timestamp).toISOString(),
    }));
    await recordFixes(store, active.scope, active.id, fixes).catch(() => undefined);
    changed();
  });
}

export type Readiness = { ok: true } | { ok: false; reason: 'services_off' | 'denied' | 'blocked'; message: string };

/** Asks for "while in use" location, once the person has read why. */
export async function prepare(): Promise<Readiness> {
  if (!(await Location.hasServicesEnabledAsync())) {
    return { ok: false, reason: 'services_off', message: 'Location is turned off on this phone. Turn it on in the quick settings, then try again.' };
  }
  const current = await Location.getForegroundPermissionsAsync();
  if (current.granted) return { ok: true };
  if (!current.canAskAgain) {
    return { ok: false, reason: 'blocked', message: 'Location permission is turned off for Miles Logbook. Allow it in the phone settings (Apps › Miles Logbook › Permissions).' };
  }
  const asked = await Location.requestForegroundPermissionsAsync();
  return asked.granted ? { ok: true } : { ok: false, reason: 'denied', message: 'Without location permission no route can be recorded. Entries can still be written.' };
}

/** Starts reading positions for the trip shown in the notification. */
export async function startUpdates(label: string): Promise<void> {
  if (await Location.hasStartedLocationUpdatesAsync(TRIP_TASK)) return;
  await Location.startLocationUpdatesAsync(TRIP_TASK, {
    accuracy: Location.Accuracy.High,
    // A position every 5 s or 10 m, delivered in small groups to spare the battery.
    timeInterval: 5_000,
    distanceInterval: 10,
    deferredUpdatesInterval: 15_000,
    pausesUpdatesAutomatically: false,
    activityType: Location.ActivityType.AutomotiveNavigation,
    showsBackgroundLocationIndicator: true,
    foregroundService: {
      notificationTitle: 'Recording your route',
      notificationBody: `${label} · open Miles Logbook to pause or end it`,
      notificationColor: '#1d2a5c',
      killServiceOnDestroy: false,
    },
  });
}

export async function stopUpdates(): Promise<void> {
  if (await Location.hasStartedLocationUpdatesAsync(TRIP_TASK).catch(() => false)) {
    await Location.stopLocationUpdatesAsync(TRIP_TASK).catch(() => undefined);
  }
}

export const running = () => Location.hasStartedLocationUpdatesAsync(TRIP_TASK).catch(() => false);
export const servicesOn = () => Location.hasServicesEnabledAsync().catch(() => true);
export const permitted = async () => (await Location.getForegroundPermissionsAsync().catch(() => ({ granted: false }))).granted;

/** One position now, for an entry (asked for by the person). */
export async function currentFix(): Promise<Fix> {
  const l = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
  return {
    lat: l.coords.latitude,
    lng: l.coords.longitude,
    accuracy: l.coords.accuracy ?? null,
    speed: null,
    altitude: l.coords.altitude ?? null,
    at: new Date(l.timestamp).toISOString(),
  };
}
