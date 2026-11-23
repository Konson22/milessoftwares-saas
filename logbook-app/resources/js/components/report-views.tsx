import { Link, router } from '@inertiajs/react';
import { useModalStack } from '@inertiaui/modal-react';
import {
    CalendarClock,
    CheckCheck,
    ClipboardList,
    Clock3,
    FilePen,
    ListTodo,
    MapPin,
    NotebookPen,
    Route as RouteIcon,
    TriangleAlert,
    Undo2,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { Pagination } from '@/components/entries-table';
import { Status } from '@/components/fields';
import { RouteMap } from '@/components/route-map';
import type { MapEntry } from '@/components/route-map';
import type { EntryRow, Paginated } from '@/lib/logbook';
import { formatDate, formatDateTime } from '@/lib/logbook';
import { show as exceptionShow } from '@/routes/exceptions';
import { record } from '@/routes/reports';
import { show as taskShow } from '@/routes/tasks';
import { show as trackShow } from '@/routes/tracks';

export type TableData = {
    columns: {
        name: string;
        label: string;
        unit: string | null;
        numeric: boolean;
    }[];
    rows: Paginated<
        EntryRow & {
            cells?: { text: string | null; flag: string | null }[];
        }
    >;
};

export type TimelineEvent = {
    key: string;
    kind: string;
    at: string | null;
    basis: 'occurred' | 'recorded';
    title: string;
    detail: string;
    subject: { type: 'entry' | 'exception' | 'task' | 'track'; id: string };
    entryId: string | null;
    person: string | null;
    severity: string | null;
};

export type TrendSeries = {
    name: string;
    label: string;
    unit: string | null;
    days: {
        day: string;
        count: number;
        sum: number;
        average: number;
        min: number;
        max: number;
    }[];
};

export type MapData = {
    entries: MapEntry[];
    tracks: {
        id: string;
        user: string;
        sheet: string;
        resource: string | null;
        startedAt: string | null;
        endedAt: string | null;
        distanceM: number;
        status: string;
    }[];
    truncated: boolean;
    tiles: { url: string; attribution: string };
};

const num = (n: number) =>
    n.toLocaleString(undefined, { maximumFractionDigits: 2 });

/** Opens one entry over the report (a slide-over, Inertia UI Modal). */
export function useOpenRecord() {
    const { visitModal } = useModalStack();

    return (id: string) =>
        void visitModal(record(id).url, {
            config: { slideover: true, maxWidth: '3xl', position: 'right' },
        });
}

export function Shell({
    title,
    icon: Icon,
    note,
    children,
}: {
    title: string;
    icon: ComponentType<{ className?: string }>;
    note?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
            <header className="flex flex-wrap items-center gap-2 border-b border-[#ebebeb] px-4 py-3">
                <Icon className="size-4 text-muted-foreground" />
                <h2 className="text-sm font-semibold">{title}</h2>
                {note && (
                    <span className="text-xs text-muted-foreground">
                        · {note}
                    </span>
                )}
            </header>
            {children}
        </section>
    );
}

/** Entries as rows; with one sheet chosen, its fields as columns. A row opens the record. */
export function TableView({ data }: { data: TableData }) {
    const open = useOpenRecord();
    const { columns, rows } = data;

    return (
        <Shell
            title="Records"
            icon={ClipboardList}
            note={`${rows.total.toLocaleString()} matching · newest first${columns.length === 0 ? ' · choose one log sheet to see its fields as columns' : ''}`}
        >
            {rows.data.length === 0 ? (
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                    No entries match these filters.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-[#ebebeb] bg-[#f8f9fa] text-left text-xs text-muted-foreground">
                                <th className="px-4 py-2 font-medium whitespace-nowrap">
                                    Reference
                                </th>
                                <th className="px-3 py-2 font-medium whitespace-nowrap">
                                    Happened
                                </th>
                                <th className="px-3 py-2 font-medium">By</th>
                                {columns.length === 0 ? (
                                    <th className="px-3 py-2 font-medium">
                                        What
                                    </th>
                                ) : (
                                    columns.map((c) => (
                                        <th
                                            key={c.name}
                                            className={`px-3 py-2 font-medium whitespace-nowrap ${c.numeric ? 'text-right' : ''}`}
                                        >
                                            {c.label}
                                            {c.unit && (
                                                <span className="ml-1 font-normal">
                                                    ({c.unit})
                                                </span>
                                            )}
                                        </th>
                                    ))
                                )}
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#f0f0f0]">
                            {rows.data.map((r) => (
                                <tr
                                    key={r.id}
                                    tabIndex={0}
                                    onClick={() => open(r.id)}
                                    onKeyDown={(e) =>
                                        (e.key === 'Enter' || e.key === ' ') &&
                                        (e.preventDefault(), open(r.id))
                                    }
                                    className="cursor-pointer hover:bg-[#f4f8f7] focus-visible:bg-[#eaf4f1] focus-visible:outline-none"
                                    aria-label={`Open ${r.reference ?? 'entry'}`}
                                >
                                    <td className="px-4 py-2.5 whitespace-nowrap">
                                        <span className="font-mono text-xs font-semibold">
                                            {r.reference}
                                        </span>
                                        {r.outOfRange > 0 && (
                                            <span
                                                className="ml-1.5 inline-flex items-center gap-0.5 rounded bg-[#fbe5e7] px-1 text-[11px] font-semibold text-[#a10d19]"
                                                title={`${r.outOfRange} reading(s) outside the normal range`}
                                            >
                                                <TriangleAlert className="size-3" />
                                                {r.outOfRange}
                                            </span>
                                        )}
                                        <span className="block text-xs text-muted-foreground">
                                            {r.sheet.code} · {r.logbook}
                                        </span>
                                    </td>
                                    <td className="px-3 py-2.5 whitespace-nowrap">
                                        {formatDateTime(r.occurredAt)}
                                    </td>
                                    <td className="px-3 py-2.5">{r.creator}</td>
                                    {r.cells === undefined ? (
                                        <td className="max-w-[24rem] truncate px-3 py-2.5 text-muted-foreground">
                                            {r.summary}
                                        </td>
                                    ) : (
                                        r.cells.map((c, i) => (
                                            <td
                                                key={columns[i]?.name ?? i}
                                                className={`max-w-[14rem] truncate px-3 py-2.5 ${columns[i]?.numeric ? 'text-right tabular-nums' : ''} ${c.flag ? 'font-semibold text-[#a10d19]' : ''}`}
                                                title={
                                                    c.flag
                                                        ? `Outside the normal range (${c.flag})`
                                                        : undefined
                                                }
                                            >
                                                {c.text ?? (
                                                    <span className="text-[#b0b6bd]">
                                                        –
                                                    </span>
                                                )}
                                                {c.flag &&
                                                    (c.flag === 'high'
                                                        ? ' ▲'
                                                        : ' ▼')}
                                            </td>
                                        ))
                                    )}
                                    <td className="px-3 py-2.5">
                                        <Status value={r.status} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            <Pagination page={rows} />
        </Shell>
    );
}

const EVENT_STYLE: Record<
    string,
    { icon: ComponentType<{ className?: string }>; bg: string; fg: string }
> = {
    'entry.occurred': { icon: Clock3, bg: '#eef0f2', fg: '#3d4651' },
    'entry.submitted': { icon: NotebookPen, bg: '#e3f3ee', fg: '#0a5c48' },
    'entry.revised': { icon: FilePen, bg: '#e8eefb', fg: '#1d4396' },
    'review.reviewed': { icon: CheckCheck, bg: '#e6f2e6', fg: '#0b650a' },
    'review.returned': { icon: Undo2, bg: '#fcefdc', fg: '#8a4b00' },
    'exception.raised': { icon: TriangleAlert, bg: '#fbe5e7', fg: '#a10d19' },
    'exception.resolved': { icon: CheckCheck, bg: '#e6f2e6', fg: '#0b650a' },
    'exception.closed': { icon: CheckCheck, bg: '#eef0f2', fg: '#3d4651' },
    'task.created': { icon: ListTodo, bg: '#e8eefb', fg: '#1d4396' },
    'task.completed': { icon: CheckCheck, bg: '#e6f2e6', fg: '#0b650a' },
    'route.started': { icon: RouteIcon, bg: '#eef0f2', fg: '#3d4651' },
    'route.ended': { icon: RouteIcon, bg: '#eef0f2', fg: '#3d4651' },
};

/** Everything that happened, newest first, grouped by day; each time says which clock it is on. */
export function TimelineView({
    timeline,
}: {
    timeline: { events: TimelineEvent[]; truncated: boolean };
}) {
    const open = useOpenRecord();
    const [kinds, setKinds] = useState<string>('all');
    const shown = timeline.events.filter((e) =>
        kinds === 'all' ? true : e.kind.startsWith(kinds),
    );
    const days = useMemo(() => {
        const map = new Map<string, TimelineEvent[]>();
        for (const e of shown) {
            const day = e.at ? e.at.slice(0, 10) : 'unknown';
            map.set(day, [...(map.get(day) ?? []), e]);
        }
        return [...map.entries()];
    }, [shown]);

    const go = (e: TimelineEvent) => {
        if (e.subject.type === 'entry') return open(e.subject.id);
        const href =
            e.subject.type === 'exception'
                ? exceptionShow(e.subject.id).url
                : e.subject.type === 'task'
                  ? taskShow(e.subject.id).url
                  : trackShow(e.subject.id).url;
        router.visit(href);
    };

    return (
        <Shell
            title="Timeline"
            icon={CalendarClock}
            note={
                timeline.truncated
                    ? `the latest ${timeline.events.length} events; narrow the filters for older ones`
                    : `${timeline.events.length} events`
            }
        >
            <div className="flex flex-wrap items-center gap-2 border-b border-[#ebebeb] px-4 py-2.5">
                {[
                    ['all', 'Everything'],
                    ['entry', 'Entries'],
                    ['review', 'Reviews'],
                    ['exception', 'Exceptions'],
                    ['task', 'Tasks'],
                    ['route', 'Routes'],
                ].map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        aria-pressed={kinds === key}
                        onClick={() => setKinds(key)}
                        className={`rounded-full px-3 py-1 text-xs font-medium ${kinds === key ? 'bg-[#16202c] text-white' : 'bg-[#f1f3f5] text-[#3d4651] hover:bg-[#e6e9ec]'}`}
                    >
                        {label}
                    </button>
                ))}
                <span className="ml-auto flex items-center gap-3 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                        <span className="rounded bg-[#16202c] px-1.5 text-[10px] font-semibold text-white">
                            HAPPENED
                        </span>
                        when the activity took place
                    </span>
                    <span className="inline-flex items-center gap-1">
                        <span className="rounded border border-[#9aa1a9] px-1.5 text-[10px] font-semibold">
                            RECORDED
                        </span>
                        when it reached Logbook
                    </span>
                </span>
            </div>
            {days.length === 0 ? (
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                    Nothing happened within these filters.
                </p>
            ) : (
                <ol className="grid gap-5 px-4 py-4">
                    {days.map(([day, events]) => (
                        <li key={day}>
                            <h3 className="mb-2 text-xs font-semibold text-muted-foreground">
                                {day === 'unknown'
                                    ? 'No date'
                                    : formatDate(day)}
                            </h3>
                            <ol className="grid">
                                {events.map((e, i) => {
                                    const style =
                                        EVENT_STYLE[e.kind] ??
                                        EVENT_STYLE['entry.occurred'];
                                    return (
                                        <li key={e.key} className="flex gap-3">
                                            <span className="flex flex-col items-center">
                                                <span
                                                    className="grid size-7 shrink-0 place-items-center rounded-full"
                                                    style={{
                                                        backgroundColor:
                                                            style.bg,
                                                        color: style.fg,
                                                    }}
                                                >
                                                    <style.icon className="size-3.5" />
                                                </span>
                                                {i < events.length - 1 && (
                                                    <span className="w-px flex-1 bg-[#e3e6e9]" />
                                                )}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => go(e)}
                                                className="mb-2 grid min-w-0 flex-1 gap-0.5 rounded-md px-2 py-1 text-left hover:bg-[#f4f8f7]"
                                            >
                                                <span className="flex flex-wrap items-center gap-2 text-sm">
                                                    <span className="text-xs text-muted-foreground tabular-nums">
                                                        {e.at
                                                            ? new Date(
                                                                  e.at,
                                                              ).toLocaleTimeString(
                                                                  undefined,
                                                                  {
                                                                      hour: '2-digit',
                                                                      minute: '2-digit',
                                                                  },
                                                              )
                                                            : '—'}
                                                    </span>
                                                    {e.basis === 'occurred' ? (
                                                        <span className="rounded bg-[#16202c] px-1.5 text-[10px] font-semibold text-white">
                                                            HAPPENED
                                                        </span>
                                                    ) : (
                                                        <span className="rounded border border-[#9aa1a9] px-1.5 text-[10px] font-semibold">
                                                            RECORDED
                                                        </span>
                                                    )}
                                                    <span className="truncate font-medium">
                                                        {e.title}
                                                    </span>
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {e.detail}
                                                    {e.person &&
                                                        ` · ${e.person}`}
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ol>
                        </li>
                    ))}
                </ol>
            )}
        </Shell>
    );
}

/** One number field per day: a column chart of the chosen measure, with a table underneath. */
export function TrendsView({
    trends,
    sheetChosen,
}: {
    trends: TrendSeries[] | null;
    sheetChosen: boolean;
}) {
    const [field, setField] = useState(trends?.[0]?.name ?? '');
    const [measure, setMeasure] = useState<'sum' | 'average' | 'max'>('sum');
    const [hover, setHover] = useState<number | null>(null);

    if (!sheetChosen || trends === null) {
        return (
            <Shell title="Trends" icon={CalendarClock}>
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                    Choose one log sheet in the filters: trends follow the
                    number fields of one sheet.
                </p>
            </Shell>
        );
    }
    if (trends.length === 0) {
        return (
            <Shell title="Trends" icon={CalendarClock}>
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                    This log sheet has no number fields.
                </p>
            </Shell>
        );
    }
    const series = trends.find((t) => t.name === field) ?? trends[0];
    const values = series.days.map((d) => d[measure]);
    const peak = Math.max(1, ...values);
    const current = hover !== null ? series.days[hover] : null;
    const unit = series.unit ? ` ${series.unit}` : '';

    return (
        <Shell
            title="Trends"
            icon={CalendarClock}
            note="per day, by when the activity happened"
        >
            <div className="flex flex-wrap items-end gap-3 border-b border-[#ebebeb] px-4 py-3">
                <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                    Field
                    <select
                        value={series.name}
                        onChange={(e) => setField(e.target.value)}
                        className="h-9 rounded-md border border-[#c7c7c7] bg-card px-2.5 text-sm text-foreground"
                    >
                        {trends.map((t) => (
                            <option key={t.name} value={t.name}>
                                {t.label}
                                {t.unit ? ` (${t.unit})` : ''}
                            </option>
                        ))}
                    </select>
                </label>
                <div
                    className="flex gap-1 rounded-lg bg-[#f1f3f5] p-1"
                    role="group"
                    aria-label="Measure"
                >
                    {(
                        [
                            ['sum', 'Total'],
                            ['average', 'Average'],
                            ['max', 'Highest'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={measure === key}
                            onClick={() => setMeasure(key)}
                            className={`rounded-md px-3 py-1 text-sm font-medium ${measure === key ? 'bg-card shadow-[0_1px_2px_#d5d9de]' : 'text-muted-foreground'}`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <p className="ml-auto h-5 text-sm" aria-live="polite">
                    {current ? (
                        <>
                            <span className="font-semibold tabular-nums">
                                {num(current[measure])}
                                {unit}
                            </span>{' '}
                            on {formatDate(current.day)} · {current.count}{' '}
                            {current.count === 1 ? 'answer' : 'answers'}
                        </>
                    ) : (
                        <span className="text-muted-foreground">
                            Highest day{' '}
                            <span className="font-semibold text-foreground tabular-nums">
                                {num(peak)}
                                {unit}
                            </span>
                        </span>
                    )}
                </p>
            </div>
            {series.days.length === 0 ? (
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                    No answers for this field in these filters.
                </p>
            ) : (
                <div className="px-4 pt-4 pb-3">
                    <div
                        className="flex h-52 items-end gap-[2px] border-b border-[#9aa1a9]"
                        onMouseLeave={() => setHover(null)}
                    >
                        {series.days.slice(-60).map((d, i) => (
                            <button
                                key={d.day}
                                type="button"
                                className="flex h-full min-w-0 flex-1 items-end focus-visible:outline-none"
                                aria-label={`${formatDate(d.day)}: ${num(d[measure])}${unit}`}
                                onMouseEnter={() => setHover(i)}
                                onFocus={() => setHover(i)}
                                onBlur={() => setHover(null)}
                            >
                                <span
                                    className="mx-auto block w-full max-w-8 rounded-t-[4px]"
                                    style={{
                                        height: `${Math.max(2, (d[measure] / peak) * 100)}%`,
                                        backgroundColor:
                                            hover === i ? '#0a5c48' : '#0e8a6c',
                                    }}
                                />
                            </button>
                        ))}
                    </div>
                    <div className="mt-1.5 flex justify-between text-xs text-muted-foreground">
                        <span>{formatDate(series.days.slice(-60)[0].day)}</span>
                        <span>
                            {formatDate(
                                series.days[series.days.length - 1].day,
                            )}
                        </span>
                    </div>
                </div>
            )}
            <details className="border-t border-[#ebebeb]">
                <summary className="cursor-pointer px-4 py-2.5 text-xs font-medium text-muted-foreground">
                    Show the figures as a table
                </summary>
                <div className="max-h-80 overflow-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="bg-[#f8f9fa] text-left text-xs text-muted-foreground">
                                <th className="px-4 py-2 font-medium">Day</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Answers
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Total
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Average
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Lowest
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Highest
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#f0f0f0] tabular-nums">
                            {[...series.days].reverse().map((d) => (
                                <tr key={d.day}>
                                    <td className="px-4 py-1.5">
                                        {formatDate(d.day)}
                                    </td>
                                    <td className="px-3 py-1.5 text-right">
                                        {d.count}
                                    </td>
                                    <td className="px-3 py-1.5 text-right">
                                        {num(d.sum)}
                                    </td>
                                    <td className="px-3 py-1.5 text-right">
                                        {num(d.average)}
                                    </td>
                                    <td className="px-3 py-1.5 text-right">
                                        {num(d.min)}
                                    </td>
                                    <td className="px-3 py-1.5 text-right">
                                        {num(d.max)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </details>
        </Shell>
    );
}

/** Where entries were written (captured locations only) and the routes recorded in the same scope. */
export function MapView({ map }: { map: MapData }) {
    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
            <Shell
                title="Entry locations"
                icon={MapPin}
                note={
                    map.entries.length === 0
                        ? 'none captured'
                        : `${map.entries.length}${map.truncated ? ' (latest 500)' : ''} with a location`
                }
            >
                {map.entries.length === 0 ? (
                    <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                        No entry in these filters was written with a location.
                        Locations are only taken on log sheets that ask for
                        them.
                    </p>
                ) : (
                    <RouteMap
                        segments={[]}
                        gaps={[]}
                        bounds={null}
                        entries={map.entries}
                        tiles={map.tiles}
                        className="h-[30rem]"
                    />
                )}
            </Shell>
            <Shell
                title="Routes"
                icon={RouteIcon}
                note={`${map.tracks.length}`}
            >
                {map.tracks.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-muted-foreground">
                        No route was recorded in these filters.
                    </p>
                ) : (
                    <ul className="max-h-[30rem] divide-y divide-[#f0f0f0] overflow-y-auto">
                        {map.tracks.map((t) => (
                            <li key={t.id}>
                                <Link
                                    href={trackShow(t.id)}
                                    className="grid gap-0.5 px-4 py-2.5 hover:bg-[#f4f8f7]"
                                >
                                    <span className="flex justify-between gap-2 text-sm font-medium">
                                        <span className="truncate">
                                            {t.user}
                                        </span>
                                        <span className="shrink-0 tabular-nums">
                                            {(t.distanceM / 1000).toFixed(1)} km
                                        </span>
                                    </span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        {t.sheet}
                                        {t.resource && ` · ${t.resource}`}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {formatDateTime(t.startedAt)}
                                        {t.status !== 'ended' &&
                                            ` · ${t.status}`}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="border-t border-[#ebebeb] px-4 py-2 text-xs text-muted-foreground">
                    Distances are estimated from the phone's positions.
                </p>
            </Shell>
        </div>
    );
}
