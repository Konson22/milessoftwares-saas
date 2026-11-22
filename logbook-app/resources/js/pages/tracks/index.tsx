import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    Car,
    ChevronRight,
    Clock,
    Download,
    FileText,
    MapPin,
    Route as RouteIcon,
    User,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelHeader, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Pagination } from '@/components/entries-table';
import { SelectField, Status, TextField } from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import { RouteMap } from '@/components/route-map';
import type { Option, Paginated } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';
import type { RouteGap, RoutePoint, TrackRow } from '@/lib/tracks';
import { distance, duration, elapsed } from '@/lib/tracks';
import { show as entryPage } from '@/routes/entries';
import { exportMethod as gpx, index, show } from '@/routes/tracks';

type Filters = {
    from: string;
    to: string;
    user: string;
    resource: string;
    logbook: string;
    sheet: string;
    status: string;
};

type Selected = TrackRow & {
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
    entries: {
        id: string;
        reference: string | null;
        status: string;
        sheet: string;
        occurredAt: string | null;
        location: { lat: number; lng: number; accuracy: number | null } | null;
    }[];
};

type Props = {
    filters: Filters;
    tracks: Paginated<TrackRow>;
    selected: Selected | null;
    tiles: { url: string; attribution: string };
    seesOthers: boolean;
    options: {
        logbooks: Option[];
        sheets: Option[];
        members: Option[];
        resources: Option[];
    };
};

/**
 * Routes recorded on phones, as in the design reference: the trips on the
 * left, the chosen one on the map, its details and entries on the right.
 * Your own routes, and (with logbook.tracks.view) those in the logbooks
 * you reach.
 */
export default function Tracks({
    filters,
    tracks,
    selected,
    tiles,
    seesOthers,
    options,
}: Props) {
    const clean = (query: Record<string, string>) =>
        Object.fromEntries(Object.entries(query).filter(([, v]) => v !== ''));
    const go = (query: Partial<Filters>) =>
        router.get(index().url, clean({ ...filters, ...query }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const pick = (id: string) =>
        router.get(
            index().url,
            clean({
                ...filters,
                page: String(tracks.current_page ?? ''),
                track: id,
            }),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="GPS Routes" />
            <PageHeader
                title="GPS Routes"
                description={
                    seesOthers
                        ? 'Trips recorded on the phone, with entries, distance and duration.'
                        : 'The trips you recorded with the phone app.'
                }
            />

            <Panel className="mb-5">
                <PanelToolbar className="border-b-0">
                    <div className="flex flex-wrap items-end gap-3">
                        <TextField
                            label="From"
                            type="date"
                            value={filters.from}
                            onChange={(from) => go({ from })}
                            className="w-40"
                        />
                        <TextField
                            label="To"
                            type="date"
                            value={filters.to}
                            onChange={(to) => go({ to })}
                            className="w-40"
                        />
                        {seesOthers && (
                            <SelectField
                                label="Driver or person"
                                value={filters.user}
                                onChange={(user) => go({ user })}
                                options={options.members}
                                empty="All people"
                                className="w-48"
                            />
                        )}
                        <SelectField
                            label="Vehicle"
                            value={filters.resource}
                            onChange={(resource) => go({ resource })}
                            options={options.resources}
                            empty="All vehicles"
                            className="w-48"
                        />
                        <SelectField
                            label="Logbook"
                            value={filters.logbook}
                            onChange={(logbook) => go({ logbook })}
                            options={options.logbooks}
                            empty="All logbooks"
                            className="w-48"
                        />
                        <SelectField
                            label="Log sheet"
                            value={filters.sheet}
                            onChange={(sheet) => go({ sheet })}
                            options={options.sheets}
                            empty="All sheets"
                            className="w-48"
                        />
                        <SelectField
                            label="Status"
                            value={filters.status}
                            onChange={(status) => go({ status })}
                            options={[
                                { value: 'recording', label: 'Recording' },
                                { value: 'paused', label: 'Paused' },
                                { value: 'ended', label: 'Ended' },
                            ]}
                            empty="Any"
                            className="w-36"
                        />
                    </div>
                </PanelToolbar>
            </Panel>

            {tracks.data.length === 0 ? (
                <Panel>
                    <EmptyState icon={RouteIcon} title="No routes here">
                        Routes are recorded with the phone app on log sheets set
                        to record a route, and only when the person starts one.
                    </EmptyState>
                </Panel>
            ) : (
                <div className="grid gap-5 xl:grid-cols-[20rem_minmax(0,1fr)_20rem]">
                    <Panel className="flex flex-col">
                        <PanelHeader title={`Trips (${tracks.total})`} />
                        <ul className="flex-1 divide-y overflow-y-auto xl:max-h-[36rem]">
                            {tracks.data.map((t) => (
                                <li key={t.id}>
                                    <button
                                        type="button"
                                        onClick={() => pick(t.id)}
                                        aria-current={
                                            selected?.id === t.id
                                                ? 'true'
                                                : undefined
                                        }
                                        className={`flex w-full items-start gap-3 px-4 py-3 text-left text-sm transition-colors ${
                                            selected?.id === t.id
                                                ? 'bg-[#e2f4ee]'
                                                : 'hover:bg-muted'
                                        }`}
                                    >
                                        <Car
                                            className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">
                                                {t.resource?.label ??
                                                    t.sheet.label}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {t.user?.name ?? 'Unknown'}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {formatDateTime(t.startedAt)}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-right">
                                            <span className="block font-medium tabular-nums">
                                                {distance(t.distanceM)}
                                            </span>
                                            {t.status !== 'ended' && (
                                                <Status value={t.status} />
                                            )}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                        <Pagination page={tracks} />
                    </Panel>

                    <Panel className="p-3">
                        {selected ? (
                            <RouteMap
                                key={selected.id}
                                segments={selected.route.segments}
                                gaps={selected.route.gaps}
                                bounds={selected.route.bounds}
                                tiles={tiles}
                                className="h-[24rem] xl:h-[36rem]"
                                entries={selected.entries
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
                        ) : null}
                    </Panel>

                    {selected && <TripDetails trip={selected} />}
                </div>
            )}

            {selected && (
                <div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Figure
                        icon={RouteIcon}
                        value={distance(selected.distanceM)}
                        label="Distance"
                    />
                    <Figure
                        icon={Clock}
                        value={duration(
                            elapsed(selected.startedAt, selected.endedAt),
                        )}
                        label="Duration"
                    />
                    <Figure
                        icon={Car}
                        value={selected.resource?.label ?? '—'}
                        label="Vehicle"
                    />
                    <Figure
                        icon={User}
                        value={selected.user?.name ?? '—'}
                        label="Driver"
                    />
                </div>
            )}
        </>
    );
}

function TripDetails({ trip }: { trip: Selected }) {
    return (
        <div className="grid content-start gap-5">
            <Panel>
                <PanelHeader
                    title="Trip details"
                    actions={<Status value={trip.status} />}
                />
                <dl className="divide-y text-sm">
                    <Row icon={Car} label="Vehicle">
                        {trip.resource?.label ?? '—'}
                    </Row>
                    <Row icon={User} label="Driver">
                        {trip.user?.name ?? '—'}
                    </Row>
                    <Row icon={CalendarDays} label="Date">
                        {new Date(trip.startedAt).toLocaleDateString(
                            undefined,
                            {
                                day: 'numeric',
                                month: 'short',
                                year: 'numeric',
                            },
                        )}
                    </Row>
                    <Row icon={Clock} label="Duration">
                        {duration(elapsed(trip.startedAt, trip.endedAt))}
                    </Row>
                    <Row icon={RouteIcon} label="Distance">
                        {distance(trip.distanceM)}
                    </Row>
                    <Row icon={MapPin} label="Start">
                        {clock(trip.startedAt)}
                    </Row>
                    <Row icon={MapPin} label="End">
                        {trip.endedAt ? clock(trip.endedAt) : 'Not yet'}
                    </Row>
                    <Row icon={FileText} label="Log sheet">
                        {trip.sheet.label}
                    </Row>
                </dl>
            </Panel>

            <Panel>
                <PanelHeader
                    title={`Entries on this route (${trip.entries.length})`}
                />
                {trip.entries.length === 0 ? (
                    <p className="px-4 py-3 text-sm text-muted-foreground">
                        No entry was linked to this route.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {trip.entries.map((e) => (
                            <li key={e.id}>
                                <Link
                                    href={entryPage(e.id)}
                                    className="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-muted"
                                >
                                    <span className="w-12 shrink-0 font-medium tabular-nums">
                                        {e.occurredAt
                                            ? clock(e.occurredAt)
                                            : '—'}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {e.sheet}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {e.reference ?? 'Draft'}
                                        </span>
                                    </span>
                                    <ChevronRight
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <div className="flex flex-wrap gap-2">
                <Button variant="outline" asChild>
                    <Link href={show(trip.id)}>Full route</Link>
                </Button>
                <Button variant="outline" asChild>
                    <a href={gpx(trip.id).url}>
                        <Download /> Export GPX
                    </a>
                </Button>
            </div>
        </div>
    );
}

function Row({
    icon: Icon,
    label,
    children,
}: {
    icon: LucideIcon;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="flex items-start gap-3 px-4 py-2.5">
            <Icon
                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                aria-hidden
            />
            <dt className="w-20 shrink-0 text-muted-foreground">{label}</dt>
            <dd className="min-w-0 flex-1 text-right font-medium">
                {children}
            </dd>
        </div>
    );
}

function Figure({
    icon: Icon,
    value,
    label,
}: {
    icon: LucideIcon;
    value: string;
    label: string;
}) {
    return (
        <div
            data-slot="stat-card"
            className="flex items-center gap-3 border bg-card"
        >
            <Icon className="size-7 shrink-0 text-primary" aria-hidden />
            <div className="min-w-0">
                <p className="truncate text-lg font-semibold">{value}</p>
                <p className="text-xs text-muted-foreground">{label}</p>
            </div>
        </div>
    );
}

/** "08:14". */
function clock(at: string): string {
    return new Date(at).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
}
