/** Recorded routes (ADR-0028). */

export type TrackRow = {
    id: string;
    status: 'recording' | 'paused' | 'ended';
    source: 'phone';
    user: { id: string; name: string } | null;
    sheet: { id: string; label: string };
    logbook: string;
    resource: { id: string; label: string } | null;
    startedAt: string;
    endedAt: string | null;
    distanceM: number;
    movingSeconds: number;
    points: number;
    gaps: number;
};

/** [lat, lng, unix seconds, accuracy in metres] */
export type RoutePoint = [number, number, number, number | null];

export type RouteGap = {
    from: [number, number, number];
    to: [number, number, number];
    seconds: number;
    reason: 'no_signal' | 'interrupted' | 'gps_off' | 'permission_lost';
};

export type LocationSettings = {
    mode: 'entry' | 'route';
    required: boolean;
    resource_required: boolean;
    accuracy_m: number | null;
};

export const GAP_REASONS: Record<RouteGap['reason'], string> = {
    no_signal: 'No position received',
    interrupted: 'Recording stopped by the phone',
    gps_off: 'Location turned off',
    permission_lost: 'Location permission taken away',
};

export const EVENT_LABELS: Record<string, string> = {
    start: 'Started',
    pause: 'Paused',
    resume: 'Resumed',
    end: 'Ended',
    interrupted: 'Interrupted (the phone stopped the app)',
    recovered: 'Recovered after the app was stopped',
    gps_off: 'Location turned off',
    gps_on: 'Location back on',
    permission_lost: 'Location permission taken away',
};

export function distance(metres: number): string {
    return metres >= 1000
        ? `${(metres / 1000).toFixed(metres >= 100_000 ? 0 : 1)} km`
        : `${metres} m`;
}

export function duration(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);
    return h > 0 ? `${h} h ${m} min` : `${m} min`;
}

/** Elapsed between two ISO times, or to now while still open. */
export function elapsed(from: string, to: string | null): number {
    const end = to ? new Date(to).getTime() : Date.now();
    return Math.max(0, Math.round((end - new Date(from).getTime()) / 1000));
}

/** The location kept on an entry. */
export type EntryLocation = {
    lat: number;
    lng: number;
    accuracy_m: number | null;
    captured_at: string;
    received_at?: string;
    source: 'phone' | 'browser';
};
