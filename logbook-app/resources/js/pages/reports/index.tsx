import { Head, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    BookOpen,
    CalendarClock,
    CheckCheck,
    ClipboardCheck,
    FileDown,
    FileSpreadsheet,
    FilterX,
    Hourglass,
    Info,
    LayoutDashboard,
    LineChart,
    Map as MapIcon,
    SlidersHorizontal,
    Table2,
    Undo2,
    Users,
    Fuel,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import type { FilterOptions, Filters } from '@/components/entries-table';
import { EntryFilters } from '@/components/entries-table';
import type { ActivitiesData } from '@/components/activities-view';
import { ActivitiesView } from '@/components/activities-view';
import { Errors, SelectField } from '@/components/fields';
import type { Issues } from '@/components/issues-table';
import { IssuesTable } from '@/components/issues-table';
import { PageHeader } from '@/components/page-header';
import type {
    MapData,
    TableData,
    TimelineEvent,
    TrendSeries,
} from '@/components/report-views';
import {
    MapView,
    TableView,
    TimelineView,
    TrendsView,
} from '@/components/report-views';
import { formatDate } from '@/lib/logbook';
import { activitiesCsv, csv, index, pdf } from '@/routes/reports';

type Count = { name: string; count: number };

type Option = { value: string; label: string };

type ReportFilters = Filters & {
    person?: string;
    relation?: string;
    basis?: string;
    resource?: string;
    shift?: string;
    mode?: string;
    location?: string;
    flag?: string;
};

type View = 'overview' | 'table' | 'timeline' | 'trends' | 'map' | 'activities';

type Props = {
    filters: ReportFilters;
    view: View;
    options: FilterOptions & {
        people: Option[];
        resources: Option[];
        shifts: Option[];
        locations: Option[];
    };
    table: TableData | null;
    timeline: { events: TimelineEvent[]; truncated: boolean } | null;
    trends: TrendSeries[] | null;
    map: MapData | null;
    activities: ActivitiesData | null;
    counts: {
        total: number;
        byStatus: Record<string, number>;
        byDay: { day: string; count: number }[];
        byContributor: Count[];
        byLogbook: Count[];
    };
    issues: Issues | null;
    numbers:
        | {
              name: string;
              label: string;
              count: number;
              sum: number;
              average: number | null;
              min: number | null;
              max: number | null;
          }[]
        | null;
    sheet: { id: string; name: string; code: string } | null;
    pdfLimit: number;
};

/*
 * Status colours, solid, checked with the palette validator (each also
 * carries its label and count, so colour is never the only cue). Soft
 * backgrounds are solid tints, not transparency.
 */
const STATES = [
    {
        key: 'reviewed',
        label: 'Reviewed',
        colour: '#0e7a0d',
        soft: '#e6f2e6',
        icon: CheckCheck,
    },
    {
        key: 'finalized',
        label: 'Recorded, no review',
        colour: '#2f5fd0',
        soft: '#e8eefb',
        icon: ClipboardCheck,
    },
    {
        key: 'submitted',
        label: 'Awaiting review',
        colour: '#d97706',
        soft: '#fcefdc',
        icon: Hourglass,
    },
    {
        key: 'returned',
        label: 'Returned',
        colour: '#c50f1f',
        soft: '#fbe5e7',
        icon: Undo2,
    },
] as const;

/** Bars in the app's teal; the hovered one a solid darker step. */
const BAR = '#0e8a6c';
const BAR_ACTIVE = '#0a5c48';

const FILTER_KEYS = [
    'q',
    'logbook',
    'sheet',
    'status',
    'from',
    'to',
    'review',
    'person',
    'basis',
    'resource',
    'location',
    'flag',
    'shift',
    'mode',
] as const;

const VIEWS: {
    key: View;
    label: string;
    icon: ComponentType<{ className?: string }>;
}[] = [
    { key: 'overview', label: 'Overview', icon: LayoutDashboard },
    { key: 'table', label: 'Table', icon: Table2 },
    { key: 'timeline', label: 'Timeline', icon: CalendarClock },
    { key: 'trends', label: 'Trends', icon: LineChart },
    { key: 'map', label: 'Map', icon: MapIcon },
    { key: 'activities', label: 'Activities and fuel', icon: Fuel },
];

const number = (n: number | null) =>
    n === null
        ? '—'
        : n.toLocaleString(undefined, { maximumFractionDigits: 2 });

const share = (part: number, total: number) =>
    total === 0 ? '0%' : `${Math.round((part / total) * 100)}%`;

const iso = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

/** Quick periods: each sets the From and To filters. */
function periods(): {
    key: string;
    label: string;
    from?: string;
    to?: string;
}[] {
    const today = new Date();
    const daysAgo = (n: number) => {
        const d = new Date(today);
        d.setDate(d.getDate() - n);
        return iso(d);
    };
    const monthStart = iso(new Date(today.getFullYear(), today.getMonth(), 1));

    return [
        { key: 'all', label: 'All time' },
        { key: 'today', label: 'Today', from: iso(today), to: iso(today) },
        { key: '7', label: 'Last 7 days', from: daysAgo(6), to: iso(today) },
        { key: '30', label: 'Last 30 days', from: daysAgo(29), to: iso(today) },
        { key: 'month', label: 'This month', from: monthStart, to: iso(today) },
    ];
}

/**
 * Reports: submitted entries at a glance (how many, how far through
 * review, when, where and by whom), the number fields of one log sheet,
 * and where issues need investigating. Exports carry the same filters. Drafts are
 * never counted.
 */
export default function Reports({
    filters,
    view,
    table,
    timeline,
    trends,
    map,
    activities,
    options,
    counts,
    issues,
    numbers,
    sheet,
    pdfLimit,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const query = Object.fromEntries(
        Object.entries(filters).filter(
            ([, v]) => v !== undefined && v !== null && v !== '',
        ),
    );
    const active = FILTER_KEYS.filter((k) => {
        const v = (filters as Record<string, unknown>)[k];
        return v !== undefined && v !== null && v !== '';
    }).length;
    const tooMany = counts.total > pdfLimit;
    const [showFilters, setShowFilters] = useState(
        active > 0 && !(active <= 2 && (filters.from || filters.to)),
    );
    const quick = periods();
    const current =
        quick.find(
            (p) =>
                (p.from ?? '') === (filters.from ?? '') &&
                (p.to ?? '') === (filters.to ?? ''),
        )?.key ?? null;
    const viewQuery: Record<string, string> =
        view === 'overview' ? {} : { view };
    const go = (next: Record<string, string | undefined>) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({ ...viewQuery, ...query, ...next }).filter(
                    ([, v]) => v !== undefined && v !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Reports" />
            <PageHeader
                title="Reports"
                actions={
                    <>
                        {view === 'activities' && (
                            <Button asChild variant="outline">
                                <a href={activitiesCsv({ query }).url}>
                                    <FileSpreadsheet /> Export activities
                                </a>
                            </Button>
                        )}
                        <Button asChild variant="outline">
                            <a href={csv({ query }).url}>
                                <FileSpreadsheet /> Export CSV
                            </a>
                        </Button>
                        {tooMany ? (
                            <Button
                                variant="outline"
                                disabled
                                title={`At most ${pdfLimit} entries per PDF: narrow the filters`}
                            >
                                <FileDown /> Export PDF
                            </Button>
                        ) : (
                            <Button asChild variant="outline">
                                <a href={pdf({ query }).url}>
                                    <FileDown /> Export PDF
                                </a>
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-5">
                <Errors
                    errors={errors.export ? { export: errors.export } : {}}
                />

                {/* One row of controls above everything they change. */}
                <section className="rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
                    <div className="flex flex-wrap items-center gap-2 px-4 py-3">
                        <div
                            className="flex flex-wrap gap-1 rounded-lg bg-[#f1f3f5] p-1"
                            role="group"
                            aria-label="Period"
                        >
                            {quick.map((p) => (
                                <button
                                    key={p.key}
                                    type="button"
                                    aria-pressed={current === p.key}
                                    onClick={() =>
                                        go({ from: p.from, to: p.to })
                                    }
                                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                                        current === p.key
                                            ? 'bg-card text-foreground shadow-[0_1px_2px_#d5d9de]'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {p.label}
                                </button>
                            ))}
                            {current === null &&
                                (filters.from || filters.to) && (
                                    <span className="rounded-md bg-card px-3 py-1.5 text-sm font-medium shadow-[0_1px_2px_#d5d9de]">
                                        {filters.from
                                            ? formatDate(filters.from)
                                            : '…'}{' '}
                                        –{' '}
                                        {filters.to
                                            ? formatDate(filters.to)
                                            : 'today'}
                                    </span>
                                )}
                        </div>
                        <div className="ml-auto flex items-center gap-2">
                            {active > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.get(index().url, viewQuery, {
                                            preserveState: true,
                                        })
                                    }
                                >
                                    <FilterX /> Clear
                                </Button>
                            )}
                            <Button
                                variant={showFilters ? 'secondary' : 'outline'}
                                size="sm"
                                aria-expanded={showFilters}
                                onClick={() => setShowFilters(!showFilters)}
                            >
                                <SlidersHorizontal /> Filters
                                {active > 0 && (
                                    <span className="ml-0.5 grid min-w-5 place-items-center rounded-full bg-primary px-1.5 text-[11px] text-primary-foreground tabular-nums">
                                        {active}
                                    </span>
                                )}
                            </Button>
                        </div>
                    </div>
                    {showFilters && (
                        <div className="border-t border-[#ebebeb] px-4 py-4">
                            <EntryFilters
                                url={index().url}
                                extra={viewQuery}
                                filters={filters}
                                options={options}
                                statuses={[
                                    'submitted',
                                    'returned',
                                    'reviewed',
                                    'finalized',
                                ]}
                            >
                                <SelectField
                                    label="Review"
                                    value={filters.review ?? ''}
                                    onChange={(review) =>
                                        go({ review: review || undefined })
                                    }
                                    options={[
                                        {
                                            value: 'pending',
                                            label: 'Awaiting review',
                                        },
                                        {
                                            value: 'reviewed',
                                            label: 'Reviewed',
                                        },
                                        {
                                            value: 'returned',
                                            label: 'Returned',
                                        },
                                        {
                                            value: 'not_required',
                                            label: 'No review step',
                                        },
                                    ]}
                                    empty="Any review state"
                                />
                                <SelectField
                                    label="Person"
                                    value={filters.person ?? ''}
                                    onChange={(person) =>
                                        go({ person: person || undefined })
                                    }
                                    options={options.people}
                                    empty="Anyone"
                                />
                                <SelectField
                                    label="Their part"
                                    value={filters.relation ?? ''}
                                    onChange={(relation) =>
                                        go({ relation: relation || undefined })
                                    }
                                    options={[
                                        { value: 'created', label: 'Wrote it' },
                                        {
                                            value: 'reviewed',
                                            label: 'Reviewed it',
                                        },
                                        {
                                            value: 'named',
                                            label: 'Named in an answer',
                                        },
                                    ]}
                                    empty="Any part"
                                />
                                <SelectField
                                    label="Dates by"
                                    value={filters.basis ?? ''}
                                    onChange={(basis) =>
                                        go({ basis: basis || undefined })
                                    }
                                    options={[
                                        {
                                            value: 'submitted',
                                            label: 'When it was recorded',
                                        },
                                    ]}
                                    empty="When it happened"
                                />
                                <SelectField
                                    label="Recorded"
                                    value={filters.mode ?? ''}
                                    onChange={(mode) =>
                                        go({ mode: mode || undefined })
                                    }
                                    options={[
                                        {
                                            value: 'scheduled',
                                            label: 'On a schedule',
                                        },
                                        {
                                            value: 'event',
                                            label: 'Per activity',
                                        },
                                        {
                                            value: 'on_demand',
                                            label: 'On demand',
                                        },
                                    ]}
                                    empty="Any way"
                                />
                                {options.shifts.length > 0 && (
                                    <SelectField
                                        label="Shift"
                                        value={filters.shift ?? ''}
                                        onChange={(shift) =>
                                            go({ shift: shift || undefined })
                                        }
                                        options={options.shifts}
                                        empty="Any shift"
                                    />
                                )}
                                <SelectField
                                    label="Resource"
                                    value={filters.resource ?? ''}
                                    onChange={(resource) =>
                                        go({ resource: resource || undefined })
                                    }
                                    options={options.resources}
                                    empty="Any resource"
                                />
                                <SelectField
                                    label="Location"
                                    value={filters.location ?? ''}
                                    onChange={(location) =>
                                        go({ location: location || undefined })
                                    }
                                    options={options.locations}
                                    empty="Any location"
                                />
                                <SelectField
                                    label="Flags"
                                    value={filters.flag ?? ''}
                                    onChange={(flag) =>
                                        go({ flag: flag || undefined })
                                    }
                                    options={[
                                        {
                                            value: 'exceptions',
                                            label: 'With an exception',
                                        },
                                        {
                                            value: 'open_exceptions',
                                            label: 'With an open exception',
                                        },
                                        {
                                            value: 'abnormal',
                                            label: 'Abnormal reading or failed check',
                                        },
                                    ]}
                                    empty="No flag filter"
                                />
                            </EntryFilters>
                        </div>
                    )}
                </section>

                <nav
                    className="flex gap-1 overflow-x-auto border-b border-[#dfe2e5]"
                    aria-label="Report views"
                >
                    {VIEWS.map((v) => (
                        <button
                            key={v.key}
                            type="button"
                            aria-current={view === v.key ? 'page' : undefined}
                            onClick={() =>
                                router.get(
                                    index().url,
                                    v.key === 'overview'
                                        ? query
                                        : { ...query, view: v.key },
                                    { preserveScroll: true },
                                )
                            }
                            className={`-mb-px inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-medium ${
                                view === v.key
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            <v.icon className="size-4" /> {v.label}
                        </button>
                    ))}
                </nav>

                {view === 'table' && table && <TableView data={table} />}
                {view === 'timeline' && timeline && (
                    <TimelineView timeline={timeline} />
                )}
                {view === 'trends' && (
                    <TrendsView trends={trends} sheetChosen={sheet !== null} />
                )}
                {view === 'map' && map && <MapView map={map} />}
                {view === 'activities' && activities && (
                    <ActivitiesView data={activities} />
                )}

                {view === 'overview' && <Figures counts={counts} />}

                {view === 'overview' && issues && (
                    <IssuesTable
                        issues={issues}
                        onLogbook={(id) =>
                            go({ logbook: id, sheet: undefined })
                        }
                    />
                )}

                {view !== 'overview' ? null : counts.total === 0 ? (
                    <section className="rounded-lg border bg-card">
                        <EmptyState icon={BarChart3} title="No entries match">
                            Choose another period, clear the filters, or wait
                            for entries to be submitted.
                        </EmptyState>
                    </section>
                ) : (
                    <>
                        <div className="grid gap-5 xl:grid-cols-3">
                            <ByDay days={counts.byDay} />
                            <Ranked
                                title="By logbook"
                                icon={BookOpen}
                                items={counts.byLogbook}
                                total={counts.total}
                                unit="logbook"
                            />
                        </div>

                        <div className="grid gap-5 xl:grid-cols-3">
                            <div className="xl:col-span-2">
                                {numbers && sheet ? (
                                    <NumberFields
                                        numbers={numbers}
                                        sheet={sheet}
                                    />
                                ) : (
                                    <Card
                                        title="Number fields"
                                        description="Totals, averages, lowest and highest values"
                                    >
                                        <div className="flex flex-col items-start gap-3 px-4 py-6 sm:flex-row sm:items-center">
                                            <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-[#e3f3ee] text-primary">
                                                <Info className="size-5" />
                                            </span>
                                            <p className="flex-1 text-sm text-muted-foreground">
                                                Choose one log sheet to add up
                                                its number fields, such as hours
                                                run or litres used.
                                            </p>
                                            <SheetPicker
                                                options={options}
                                                onPick={(id) =>
                                                    go({ sheet: id })
                                                }
                                            />
                                        </div>
                                    </Card>
                                )}
                            </div>
                            <Ranked
                                title="By contributor"
                                icon={Users}
                                items={counts.byContributor}
                                total={counts.total}
                                unit="person"
                                initials
                            />
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

function Card({
    title,
    description,
    icon: Icon,
    className = '',
    children,
}: {
    title: string;
    description?: string;
    icon?: ComponentType<{ className?: string }>;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            className={`overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3] ${className}`}
        >
            <header className="flex items-center gap-2.5 border-b border-[#ebebeb] px-4 py-3">
                {Icon && (
                    <Icon className="size-4 shrink-0 text-muted-foreground" />
                )}
                <h2 className="text-sm font-semibold">{title}</h2>
                {description && (
                    <span className="truncate text-xs text-muted-foreground">
                        · {description}
                    </span>
                )}
            </header>
            {children}
        </section>
    );
}

/** The headline figure with its split by state, then one tile per state. */
function Figures({ counts }: { counts: Props['counts'] }) {
    const total = counts.total;

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[1.4fr_repeat(4,1fr)]">
            <div className="rounded-lg border bg-card p-4 shadow-[0_1px_2px_#e3e3e3] sm:col-span-2 xl:col-span-1">
                <p className="text-xs font-medium text-muted-foreground">
                    Submitted entries
                </p>
                <p className="mt-1 text-4xl leading-none font-semibold tracking-tight tabular-nums">
                    {total.toLocaleString()}
                </p>
                <div
                    className="mt-4 flex h-2.5 w-full gap-[2px] overflow-hidden rounded-[4px] bg-[#eef0f2]"
                    role="img"
                    aria-label={STATES.map(
                        (s) => `${s.label}: ${counts.byStatus[s.key] ?? 0}`,
                    ).join(', ')}
                >
                    {total > 0 &&
                        STATES.map((s) => {
                            const n = counts.byStatus[s.key] ?? 0;
                            return n === 0 ? null : (
                                <span
                                    key={s.key}
                                    className="h-full"
                                    style={{
                                        width: `${(n / total) * 100}%`,
                                        backgroundColor: s.colour,
                                    }}
                                    title={`${s.label}: ${n} (${share(n, total)})`}
                                />
                            );
                        })}
                </div>
                <p className="mt-2 text-xs text-muted-foreground">
                    {total === 0
                        ? 'Nothing in this period.'
                        : `${share((counts.byStatus.reviewed ?? 0) + (counts.byStatus.finalized ?? 0), total)} settled · ${share(counts.byStatus.submitted ?? 0, total)} still waiting`}
                </p>
            </div>
            {STATES.map((s) => {
                const n = counts.byStatus[s.key] ?? 0;
                const Icon = s.icon;

                return (
                    <div
                        key={s.key}
                        className="flex items-start gap-3 rounded-lg border bg-card p-4 shadow-[0_1px_2px_#e3e3e3]"
                    >
                        <span
                            className="grid size-9 shrink-0 place-items-center rounded-lg"
                            style={{ backgroundColor: s.soft, color: s.colour }}
                        >
                            <Icon className="size-4" />
                        </span>
                        <span className="grid min-w-0 gap-0.5">
                            <span className="truncate text-xs font-medium text-muted-foreground">
                                {s.label}
                            </span>
                            <span className="text-2xl leading-tight font-semibold tabular-nums">
                                {n.toLocaleString()}
                            </span>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {share(n, total)} of entries
                            </span>
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

/** Entries per day as columns on a light grid, the exact count on hover or focus. */
function ByDay({ days }: { days: Props['counts']['byDay'] }) {
    const shown = days.slice(-31);
    const peak = Math.max(1, ...shown.map((d) => d.count));
    // A round top for the scale: 1, 2, 5, 10, 20, 50...
    const step =
        [1, 2, 5]
            .flatMap((m) => [1, 10, 100, 1000].map((p) => m * p))
            .sort((a, b) => a - b)
            .find((s) => s * 4 >= peak) ?? peak;
    const top = Math.max(step * Math.ceil(peak / step), 1);
    const ticks = [top, top / 2, 0];
    const total = shown.reduce((a, d) => a + d.count, 0);
    const average = shown.length === 0 ? 0 : total / shown.length;
    const [hover, setHover] = useState<number | null>(null);
    const current = hover !== null ? shown[hover] : null;

    return (
        <Card
            title="Entries by day"
            icon={BarChart3}
            description={
                days.length > 31
                    ? 'last 31 days with entries'
                    : 'by when the activity happened'
            }
            className="xl:col-span-2"
        >
            <div className="px-4 pt-4 pb-3">
                <p className="mb-3 h-5 text-sm" aria-live="polite">
                    {current ? (
                        <>
                            <span className="font-semibold tabular-nums">
                                {current.count}
                            </span>{' '}
                            {current.count === 1 ? 'entry' : 'entries'} on{' '}
                            {formatDate(current.day)}
                        </>
                    ) : (
                        <span className="text-muted-foreground">
                            Peak{' '}
                            <span className="font-semibold text-foreground tabular-nums">
                                {peak}
                            </span>{' '}
                            · average{' '}
                            <span className="font-semibold text-foreground tabular-nums">
                                {number(Math.round(average * 10) / 10)}
                            </span>{' '}
                            a day
                        </span>
                    )}
                </p>
                <div className="flex gap-2">
                    <div className="flex h-48 flex-col justify-between pb-px text-right text-[11px] text-muted-foreground tabular-nums">
                        {ticks.map((t) => (
                            <span
                                key={t}
                                className="-translate-y-1.5 leading-none"
                            >
                                {number(t)}
                            </span>
                        ))}
                    </div>
                    <div className="relative flex-1">
                        {ticks.map((t) => (
                            <span
                                key={t}
                                className={`absolute inset-x-0 h-px ${t === 0 ? 'bg-[#9aa1a9]' : 'bg-[#eceef0]'}`}
                                style={{ bottom: `${(t / top) * 100}%` }}
                                aria-hidden
                            />
                        ))}
                        <div
                            className="relative flex h-48 items-end gap-[2px]"
                            onMouseLeave={() => setHover(null)}
                        >
                            {shown.map((d, i) => (
                                <button
                                    key={d.day}
                                    type="button"
                                    className="flex h-full min-w-0 flex-1 items-end focus-visible:outline-none"
                                    aria-label={`${formatDate(d.day)}: ${d.count} ${d.count === 1 ? 'entry' : 'entries'}`}
                                    onMouseEnter={() => setHover(i)}
                                    onFocus={() => setHover(i)}
                                    onBlur={() => setHover(null)}
                                >
                                    <span
                                        className="mx-auto block w-full max-w-7 rounded-t-[4px]"
                                        style={{
                                            height: `${Math.max(2, (d.count / top) * 100)}%`,
                                            backgroundColor:
                                                hover === i ? BAR_ACTIVE : BAR,
                                        }}
                                    />
                                </button>
                            ))}
                        </div>
                    </div>
                </div>
                {shown.length > 0 && (
                    <div className="mt-1.5 flex justify-between pl-8 text-xs text-muted-foreground">
                        <span>{formatDate(shown[0].day)}</span>
                        {shown.length > 1 && (
                            <span>
                                {formatDate(shown[shown.length - 1].day)}
                            </span>
                        )}
                    </div>
                )}
            </div>
        </Card>
    );
}

/** A ranked list with a bar each: largest first, with count and share. */
function Ranked({
    title,
    icon,
    items,
    total,
    unit,
    initials = false,
}: {
    title: string;
    icon: ComponentType<{ className?: string }>;
    items: Count[];
    total: number;
    unit: string;
    initials?: boolean;
}) {
    const sorted = [...items].sort((a, b) => b.count - a.count);
    const max = Math.max(1, ...sorted.map((p) => p.count));

    return (
        <Card
            title={title}
            icon={icon}
            description={`${sorted.length} ${sorted.length === 1 ? unit : unit === 'person' ? 'people' : `${unit}s`}`}
        >
            <ol className="max-h-[17.5rem] divide-y divide-[#f0f0f0] overflow-y-auto">
                {sorted.map((p, i) => (
                    <li
                        key={p.name}
                        className="flex items-center gap-3 px-4 py-2.5"
                    >
                        {initials ? (
                            <span className="grid size-7 shrink-0 place-items-center rounded-full bg-[#e3f3ee] text-[11px] font-semibold text-[#0a5c48]">
                                {p.name
                                    .split(/\s+/)
                                    .map((w) => w[0])
                                    .slice(0, 2)
                                    .join('')
                                    .toUpperCase()}
                            </span>
                        ) : (
                            <span className="w-5 shrink-0 text-right text-xs text-muted-foreground tabular-nums">
                                {i + 1}
                            </span>
                        )}
                        <span className="grid min-w-0 flex-1 gap-1.5">
                            <span className="flex items-baseline justify-between gap-3 text-sm">
                                <span className="truncate font-medium">
                                    {p.name}
                                </span>
                                <span className="shrink-0 tabular-nums">
                                    {p.count.toLocaleString()}
                                    <span className="ml-1.5 text-xs text-muted-foreground">
                                        {share(p.count, total)}
                                    </span>
                                </span>
                            </span>
                            <span className="block h-1.5 rounded-[4px] bg-[#eef0f2]">
                                <span
                                    className="block h-full rounded-[4px]"
                                    style={{
                                        width: `${(p.count / max) * 100}%`,
                                        backgroundColor: BAR,
                                    }}
                                />
                            </span>
                        </span>
                    </li>
                ))}
            </ol>
        </Card>
    );
}

function SheetPicker({
    options,
    onPick,
}: {
    options: FilterOptions;
    onPick: (id: string) => void;
}) {
    return (
        <select
            value=""
            onChange={(e) => e.target.value && onPick(e.target.value)}
            aria-label="Choose a log sheet"
            className="h-9 rounded-md border border-[#c7c7c7] bg-card px-2.5 text-sm"
        >
            <option value="">Choose a log sheet…</option>
            {options.sheets.map((s) => (
                <option key={s.value} value={s.value}>
                    {s.label}
                </option>
            ))}
        </select>
    );
}

/** Totals of the chosen log sheet's number fields: tiles for the first four, the table for all. */
function NumberFields({
    numbers,
    sheet,
}: {
    numbers: NonNullable<Props['numbers']>;
    sheet: NonNullable<Props['sheet']>;
}) {
    return (
        <Card title={`Number fields · ${sheet.code}`} description={sheet.name}>
            {numbers.length === 0 ? (
                <p className="px-4 py-4 text-sm text-muted-foreground">
                    This log sheet has no number fields.
                </p>
            ) : (
                <>
                    <div className="grid gap-3 border-b border-[#ebebeb] p-4 sm:grid-cols-2 lg:grid-cols-4">
                        {numbers.slice(0, 4).map((n) => (
                            <div
                                key={n.name}
                                className="rounded-lg bg-[#f6f8f9] px-3.5 py-3"
                            >
                                <p className="truncate text-xs font-medium text-muted-foreground">
                                    {n.label}
                                </p>
                                <p className="text-2xl font-semibold tabular-nums">
                                    {number(n.sum)}
                                </p>
                                <p className="text-xs text-muted-foreground tabular-nums">
                                    avg {number(n.average)} · {n.count}{' '}
                                    {n.count === 1 ? 'answer' : 'answers'}
                                </p>
                            </div>
                        ))}
                    </div>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Field</TableHead>
                                    <TableHead className="text-right">
                                        Answered
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Total
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Average
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Lowest
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Highest
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {numbers.map((n) => (
                                    <TableRow key={n.name}>
                                        <TableCell className="font-medium">
                                            {n.label}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {n.count}
                                        </TableCell>
                                        <TableCell className="text-right font-semibold tabular-nums">
                                            {number(n.sum)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {number(n.average)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {number(n.min)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {number(n.max)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </>
            )}
        </Card>
    );
}
