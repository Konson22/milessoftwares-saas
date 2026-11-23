import * as Crypto from 'expo-crypto';
import * as Device from 'expo-device';
import { create } from 'zustand';

import * as Trip from '@/lib/trip-location';
import * as Tracks from '@/logbook/tracks';
import type { LocalTrack } from '@/logbook/tracks';
import { trackApi } from '@/logbook/transport';
import type { KitSheet } from '@/logbook/types';
import { uuidv7 } from '@/logbook/uuid';

import { store } from './logbook';

/*
 * Trips for the screens (ADR-0028). The route and its positions live in the
 * encrypted store (logbook/tracks.ts); this holds what the screens show and
 * drives the location service. Nothing here starts on its own: recording
 * begins only from start() or resume(), both pressed by the person.
 */

interface TripState {
  scope: string | null;
  /** The route open on this phone for the person signed in (recording or paused). */
  active: LocalTrack | null;
  /** The phone stopped it: shown until the person resumes or ends it. */
  interrupted: boolean;
  history: LocalTrack[];
  unsent: number;
  syncing: boolean;
  problem: string | null;

  open(scope: string | null): Promise<void>;
  reload(): Promise<void>;
  start(sheet: KitSheet, resource: { id: string; label: string } | null, taskId?: string | null): Promise<LocalTrack>;
  pause(): Promise<void>;
  resume(): Promise<void>;
  end(): Promise<void>;
  /** On return to the app: location still on, still allowed, service still running. */
  check(): Promise<void>;
  sync(): Promise<void>;
  retry(): Promise<void>;
}

let syncing: Promise<void> | null = null;

const label = (t: Pick<LocalTrack, 'resource' | 'sheet'>) => t.resource?.label ?? `${t.sheet.code} · ${t.sheet.name}`;

export const useTrips = create<TripState>((set, get) => ({
  scope: null,
  active: null,
  interrupted: false,
  history: [],
  unsent: 0,
  syncing: false,
  problem: null,

  async open(scope) {
    set({ scope, active: null, history: [], interrupted: false, unsent: 0, problem: null });
    const pointer = await store.active();
    if (pointer && pointer.scope !== scope) {
      // Recorded for someone else (signed out or switched workspace): stopped, kept for them.
      const other = await store.track(pointer.scope, pointer.id);
      if (other?.status === 'recording') {
        await Trip.stopUpdates();
        await Tracks.interruptTrack(store, pointer.scope, pointer.id, new Date(), 'signed out');
      }
    }
    if (!scope) return;
    if (pointer?.scope === scope) {
      const mine = await store.track(scope, pointer.id);
      // Killed by the phone (or rebooted) while recording: say so, and let the person choose.
      if (mine?.status === 'recording' && !(await Trip.running())) {
        await Tracks.interruptTrack(store, scope, mine.id, new Date(), 'the phone stopped the app');
        set({ interrupted: true });
      } else if (mine && mine.status === 'paused') {
        const records = await store.records(scope, mine.id, Math.max(0, mine.nextSeq - 2), 1);
        set({ interrupted: records[0]?.type === 'event' && records[0].kind === 'interrupted' });
      }
    }
    await get().reload();
    void Tracks.purgeTracks(store, scope, new Date());
  },

  async reload() {
    const { scope } = get();
    if (!scope) return;
    const tracks = (await store.tracks(scope)).sort((a, b) => b.startedAt.localeCompare(a.startedAt));
    const pointer = await store.active();
    const active = pointer?.scope === scope ? (tracks.find((t) => t.id === pointer.id && t.status !== 'ended') ?? null) : null;
    if (get().scope === scope) set({ active, history: tracks.filter((t) => t.id !== active?.id), unsent: Tracks.unsentRecords(tracks) });
  },

  async start(sheet, resource, taskId = null) {
    const { scope } = get();
    if (!scope) throw new Tracks.TripError('Sign in first.');
    const ready = await Trip.prepare();
    if (!ready.ok) throw new Tracks.TripError(ready.message);
    const track = await Tracks.startTrack(
      store,
      {
        id: uuidv7(Date.now(), (n) => Crypto.getRandomBytes(n)),
        scope,
        sheet: { id: sheet.id, name: sheet.name, code: sheet.code },
        logbook: sheet.logbook.name,
        resource,
        taskId,
        device: [Device.manufacturer, Device.modelName].filter(Boolean).join(' ').slice(0, 120) || null,
      },
      new Date(),
    );
    try {
      await Trip.startUpdates(label(track));
    } catch (error) {
      // No service, no recording: the route is closed rather than left "recording" with nothing behind it.
      await Tracks.changeTrack(store, scope, track.id, 'end', new Date());
      await get().reload();
      throw new Tracks.TripError(error instanceof Error ? `The phone would not start recording: ${error.message}` : 'The phone would not start recording.');
    }
    set({ interrupted: false, problem: null });
    await get().reload();
    void get().sync();
    return track;
  },

  async pause() {
    const { scope, active } = get();
    if (!scope || !active) return;
    await Trip.stopUpdates();
    await Tracks.changeTrack(store, scope, active.id, 'pause', new Date());
    await get().reload();
    void get().sync();
  },

  async resume() {
    const { scope, active, interrupted } = get();
    if (!scope || !active) return;
    const ready = await Trip.prepare();
    if (!ready.ok) throw new Tracks.TripError(ready.message);
    await Tracks.changeTrack(store, scope, active.id, interrupted ? 'recovered' : 'resume', new Date());
    try {
      await Trip.startUpdates(label(active));
    } catch (error) {
      await Tracks.interruptTrack(store, scope, active.id, new Date(), 'could not restart');
      await get().reload();
      throw new Tracks.TripError(error instanceof Error ? error.message : 'The phone would not start recording.');
    }
    set({ interrupted: false, problem: null });
    await get().reload();
  },

  async end() {
    const { scope, active } = get();
    if (!scope || !active) return;
    await Trip.stopUpdates();
    await Tracks.changeTrack(store, scope, active.id, 'end', new Date());
    set({ interrupted: false, problem: null });
    await get().reload();
    void get().sync();
  },

  async check() {
    const { scope, active } = get();
    if (!scope || !active || active.status !== 'recording') return;
    const [on, allowed, alive] = await Promise.all([Trip.servicesOn(), Trip.permitted(), Trip.running()]);
    if (!allowed) {
      await Trip.stopUpdates();
      await Tracks.noteTrack(store, scope, active.id, 'permission_lost', new Date());
      await Tracks.interruptTrack(store, scope, active.id, new Date(), 'permission taken away');
      set({ interrupted: true, problem: 'Location permission was taken away. Allow it again to resume the route.' });
    } else if (!alive) {
      await Tracks.interruptTrack(store, scope, active.id, new Date(), 'the phone stopped the app');
      set({ interrupted: true });
    } else {
      await Tracks.noteTrack(store, scope, active.id, on ? 'gps_on' : 'gps_off', new Date());
      set({ problem: on ? null : 'Location is turned off: nothing is being recorded. Turn it back on.' });
    }
    await get().reload();
  },

  sync() {
    syncing ??= (async () => {
      const { scope } = get();
      if (!scope) return;
      set({ syncing: true });
      try {
        await Tracks.syncTracks(store, trackApi, scope);
      } finally {
        set({ syncing: false });
        await get().reload();
      }
    })().finally(() => {
      syncing = null;
    });
    return syncing;
  },

  async retry() {
    const { scope } = get();
    if (!scope) return;
    await Tracks.retryTracks(store, scope, new Date());
    await get().sync();
  },
}));

/** Positions arriving from the location service refresh what is shown. */
let pending: ReturnType<typeof setTimeout> | null = null;
Trip.onTripChange(() => {
  pending ??= setTimeout(() => {
    pending = null;
    void useTrips.getState().reload();
  }, 1_000);
});
