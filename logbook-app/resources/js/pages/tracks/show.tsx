import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { useConfirm } from '@/components/dialogs';
import { Detail, Status } from '@/components/fields';
import { MAP_COLOURS, RouteMap } from '@/components/route-map';
import { formatDateTime } from '@/lib/logbook';
import type { RouteGap, RoutePoint, TrackRow } from '@/lib/tracks';
import {
    distance,
    duration,
    elapsed,
    EVENT_LABELS,
    GAP_REASONS,
} from '@/lib/tracks';
import { show as entryPage } from '@/routes/entries';
import { destroy, exportMethod as gpx, index } from '@/routes/tracks';

type Props = {
    track: TrackRow & {
        task: { id: string; label: string } | null;
        device: string | null;
        lastPointAt: string | null;
        gapSeconds: number;
        rejected: number;
    };
    route: {
        segments: RoutePoint[][];
        gaps: RouteGap[];
        bounds: {
            south: number;
            west: number;
            north: number;
            east: number;
        } | null;
    };
    events: { kind: string; at: string; note: string | null }[];
    entries: {
        id: string;
        reference: string | null;
        status: string;
        sheet: string;
        occurredAt: string | null;
        location: { lat: number; lng: number; accuracy: number | null } | null;
    }[];
    can: { delete: boolean; export: boolean };
    tiles: { url: string; attribution: string };
};

/** One recorded route: the map, the figures, the entries and what interrupted it. */
export default function TrackShow({
    track,
    route,
    events,
    entries,
    can,
    tiles,
}: Props) {
    const ask = useConfirm();
    const total = elapsed(track.startedAt, track.endedAt);
    const accuracies = route.segments
        .flat()
        .map((p) => p[3])
        .filter((a): a is number => a !== null)
        .sort((a, b) => a - b);
    const medianAccuracy =
        accuracies.length > 0
            ? Math.round(accuracies[Math.floor(accuracies.length / 2)])
            : null;

    return (
        <>
            <Head title="Route" />
            <PageHeader
                title={`Route · ${track.resource?.label ?? track.sheet.label}`}
                description={
                    <>
                        <Link href={index()} className="hover:underline">
                            Routes
                        </Link>{' '}
                        · {track.user?.name ?? 'Unknown'} ·{' '}
                        {formatDateTime(track.startedAt)}
                    </>
                }
                badges={<Status value={track.status} />}
                actions={
                    <div className="flex gap-2">
                        {can.export && (
                            <Button variant="outline" size="sm" asChild>
                                <a href={gpx(track.id).url}>
                                    <Download /> GPX
                                </a>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={async () => {
                                    if (
                                        await ask({
                                            title: 'Remove this route?',
                                            message:
                                                'Its points are deleted. Entries written on it keep their own location. This is recorded in the audit trail.',
                                            confirmLabel: 'Remove route',
                                            destructive: true,
                                        })
                                    ) {
                                        router.delete(destroy(track.id).url);
                                    }
                                }}
                            >
                                Remove
                            </Button>
                        )}
                    </div>
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid content-start gap-6 lg:col-span-2">
                    <Panel>
                        <PanelBody className="grid gap-3">
                            <RouteMap
                                segments={route.segments}
                                gaps={route.gaps}
                                bounds={route.bounds}
                                tiles={tiles}
                                entries={entries
                                    .filter((e) => e.location !== null)
                                    .map((e) => ({
                                        id: e.id,
                                        label: `${e.reference ?? 'Draft'} · ${e.sheet}`,
                                        at: e.occurredAt,
                                        lat: e.location!.lat,
                                        lng: e.location!.lng,
                                        href: entryPage(e.id).url,
                                    }))}
                            />
                            <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                <Legend
                                    colour={MAP_COLOURS.route}
                                    label="Recorded positions"
                                />
                                <Legend
                                    colour={MAP_COLOURS.gap}
                                    label="Gap: no recording (dashed)"
                                />
                                <Legend
                                    colour={MAP_COLOURS.start}
                                    label="Start"
                                />
                                <Legend
                                    colour={MAP_COLOURS.end}
                                    label="Last position"
                                />
                                <Legend
                                    colour={MAP_COLOURS.entry}
                                    label="Entry"
                                />
                            </ul>
                            <p className="text-xs text-muted-foreground">
                                The line joins the positions the phone recorded,
                                in order. It is not a road route, and distance
                                is not counted across gaps.
                            </p>
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader title="Entries on this route" />
                        {entries.length === 0 ? (
                            <p className="px-4 py-3 text-sm text-muted-foreground">
                                No entry was linked to this route.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {entries.map((e) => (
                                    <li
                                        key={e.id}
                                        className="flex items-center justify-between gap-3 px-4 py-2 text-sm"
                                    >
                                        <span>
                                            <Link
                                                href={entryPage(e.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {e.reference ?? 'Draft'}
                                            </Link>{' '}
                                            · {e.sheet}
                                            <span className="block text-xs text-muted-foreground">
                                                {formatDateTime(e.occurredAt)}
                                                {e.location
                                                    ? ` · ${e.location.lat.toFixed(5)}, ${e.location.lng.toFixed(5)}${e.location.accuracy !== null ? ` (±${Math.round(e.location.accuracy)} m)` : ''}`
                                                    : ' · no location taken'}
                                            </span>
                                        </span>
                                        <Status value={e.status} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <div className="grid content-start gap-6">
                    <Panel>
                        <PanelHeader title="Trip" />
                        <PanelBody>
                            <dl className="grid gap-3">
                                <Detail label="Person">
                                    {track.user?.name}
                                </Detail>
                                <Detail label="Vehicle or resource">
                                    {track.resource?.label}
                                </Detail>
                                <Detail label="Log sheet">
                                    {track.sheet.label} · {track.logbook}
                                </Detail>
                                {track.task && (
                                    <Detail label="Task">
                                        {track.task.label}
                                    </Detail>
                                )}
                                <Detail label="Started">
                                    {formatDateTime(track.startedAt)}
                                </Detail>
                                <Detail label="Ended">
                                    {track.endedAt
                                        ? formatDateTime(track.endedAt)
                                        : 'Not yet'}
                                </Detail>
                                <Detail label="Distance (estimated)">
                                    {distance(track.distanceM)}
                                </Detail>
                                <Detail label="Duration">
                                    {duration(total)} · moving{' '}
                                    {duration(track.movingSeconds)}
                                </Detail>
                                <Detail label="Positions">
                                    {track.points} received
                                    {track.rejected > 0 &&
                                        `, ${track.rejected} left out as inaccurate or implausible`}
                                </Detail>
                                <Detail label="Typical accuracy">
                                    {medianAccuracy !== null
                                        ? `±${medianAccuracy} m`
                                        : null}
                                </Detail>
                                <Detail label="Gaps">
                                    {track.gaps === 0
                                        ? 'None'
                                        : `${track.gaps} · ${duration(track.gapSeconds)} without recording`}
                                </Detail>
                                <Detail label="Last position received">
                                    {formatDateTime(track.lastPointAt)}
                                </Detail>
                                <Detail label="Recorded with">
                                    {track.device
                                        ? `Phone app · ${track.device}`
                                        : 'Phone app'}
                                </Detail>
                            </dl>
                        </PanelBody>
                    </Panel>

                    {route.gaps.length > 0 && (
                        <Panel>
                            <PanelHeader title="Gaps" />
                            <ul className="divide-y text-sm">
                                {route.gaps.map((g, i) => (
                                    <li key={i} className="px-4 py-2">
                                        {GAP_REASONS[g.reason]}
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateTime(
                                                new Date(
                                                    g.from[2] * 1000,
                                                ).toISOString(),
                                            )}{' '}
                                            · {duration(g.seconds)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader title="Recording log" />
                        <ul className="divide-y text-sm">
                            {events.map((e, i) => (
                                <li key={i} className="px-4 py-2">
                                    {EVENT_LABELS[e.kind] ?? e.kind}
                                    <span className="block text-xs text-muted-foreground">
                                        {formatDateTime(e.at)}
                                        {e.note ? ` · ${e.note}` : ''}
                                    </span>
                                </li>
                            ))}
                            {events.length === 0 && (
                                <li className="px-4 py-2 text-muted-foreground">
                                    Nothing received from the phone yet.
                                </li>
                            )}
                        </ul>
                    </Panel>
                </div>
            </div>
        </>
    );
}

function Legend({ colour, label }: { colour: string; label: string }) {
    return (
        <li className="flex items-center gap-1.5">
            <span
                className="inline-block size-3 rounded-full"
                style={{ backgroundColor: colour }}
            />
            {label}
        </li>
    );
}
