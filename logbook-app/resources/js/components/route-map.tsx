import 'leaflet/dist/leaflet.css';
import type { LatLngBoundsExpression } from 'leaflet';
import {
    CircleMarker,
    MapContainer,
    Polyline,
    Popup,
    TileLayer,
    Tooltip,
} from 'react-leaflet';
import { formatDateTime } from '@/lib/logbook';
import type { RouteGap, RoutePoint } from '@/lib/tracks';
import { duration, GAP_REASONS } from '@/lib/tracks';

/** Solid colours only: the line, gaps, start, end and entries. */
const COLOURS = {
    route: '#2563eb',
    gap: '#c2410c',
    start: '#16a34a',
    end: '#dc2626',
    entry: '#0d2a45',
};

export type MapEntry = {
    id: string;
    label: string;
    at: string | null;
    lat: number;
    lng: number;
    href: string;
};

const iso = (t: number) => new Date(t * 1000).toISOString();

/**
 * The positions as the phone recorded them, joined in order. Gaps are
 * drawn dashed and labelled: the line there is not the road travelled.
 * Only the background tiles come from the tile server.
 */
export function RouteMap({
    segments,
    gaps,
    bounds,
    entries,
    tiles,
    className = 'h-[28rem]',
}: {
    segments: RoutePoint[][];
    gaps: RouteGap[];
    bounds: { south: number; west: number; north: number; east: number } | null;
    entries: MapEntry[];
    tiles: { url: string; attribution: string };
    /** The map's height (and any extra classes). */
    className?: string;
}) {
    const first = segments[0]?.[0];
    const lastSegment = segments[segments.length - 1];
    const last = lastSegment?.[lastSegment.length - 1];
    const box: LatLngBoundsExpression | null = bounds
        ? [
              [bounds.south, bounds.west],
              [bounds.north, bounds.east],
          ]
        : entries.length > 0
          ? entries.map((e) => [e.lat, e.lng] as [number, number])
          : null;

    if (!box) {
        return (
            <div
                className={`flex items-center justify-center rounded-lg border bg-muted p-6 text-center text-sm text-muted-foreground ${className}`}
            >
                No usable position has been received for this route yet.
            </div>
        );
    }

    return (
        <MapContainer
            bounds={box}
            boundsOptions={{ padding: [24, 24], maxZoom: 17 }}
            className={`w-full rounded-lg border ${className}`}
            scrollWheelZoom={false}
        >
            <TileLayer url={tiles.url} attribution={tiles.attribution} />
            {segments.map((segment, i) => (
                <Polyline
                    key={`s${i}`}
                    positions={segment.map((p) => [p[0], p[1]])}
                    pathOptions={{
                        color: COLOURS.route,
                        weight: 4,
                        opacity: 1,
                    }}
                />
            ))}
            {gaps.map((g, i) => (
                <Polyline
                    key={`g${i}`}
                    positions={[
                        [g.from[0], g.from[1]],
                        [g.to[0], g.to[1]],
                    ]}
                    pathOptions={{
                        color: COLOURS.gap,
                        weight: 3,
                        opacity: 1,
                        dashArray: '6 8',
                    }}
                >
                    <Tooltip sticky>
                        {GAP_REASONS[g.reason]} for {duration(g.seconds)} (
                        {formatDateTime(iso(g.from[2]))} to{' '}
                        {formatDateTime(iso(g.to[2]))}). Not the road travelled.
                    </Tooltip>
                </Polyline>
            ))}
            {first && (
                <CircleMarker
                    center={[first[0], first[1]]}
                    radius={8}
                    pathOptions={{
                        color: '#ffffff',
                        weight: 2,
                        fillColor: COLOURS.start,
                        fillOpacity: 1,
                        opacity: 1,
                    }}
                >
                    <Tooltip>Start · {formatDateTime(iso(first[2]))}</Tooltip>
                </CircleMarker>
            )}
            {last && (
                <CircleMarker
                    center={[last[0], last[1]]}
                    radius={8}
                    pathOptions={{
                        color: '#ffffff',
                        weight: 2,
                        fillColor: COLOURS.end,
                        fillOpacity: 1,
                        opacity: 1,
                    }}
                >
                    <Tooltip>
                        Last position · {formatDateTime(iso(last[2]))}
                    </Tooltip>
                </CircleMarker>
            )}
            {entries.map((e) => (
                <CircleMarker
                    key={e.id}
                    center={[e.lat, e.lng]}
                    radius={7}
                    pathOptions={{
                        color: '#ffffff',
                        weight: 2,
                        fillColor: COLOURS.entry,
                        fillOpacity: 1,
                        opacity: 1,
                    }}
                >
                    <Popup>
                        <a href={e.href} className="font-medium">
                            {e.label}
                        </a>
                        <br />
                        {formatDateTime(e.at)}
                    </Popup>
                </CircleMarker>
            ))}
        </MapContainer>
    );
}

export const MAP_COLOURS = COLOURS;
