import { Head, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    FileDown,
    FileSpreadsheet,
    FilterX,
    Info,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import {
    EntriesTable,
    EntryFilters,
    Pagination,
} from '@/components/entries-table';
import { Errors, SelectField } from '@/components/fields';
import type { EntryRow, Paginated } from '@/lib/logbook';
import { formatDate } from '@/lib/logbook';
import { csv, index, pdf } from '@/routes/reports';

type Props = {
    filters: Filters;
    options: FilterOptions;
    counts: {
        total: number;
        byStatus: Record<string, number>;
        byDay: { day: string; count: number }[];
        byContributor: { name: string; count: number }[];
    };
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
    entries: Paginated<EntryRow>;
    pdfLimit: number;
};

/*
 * Status colours, solid, in an order checked for colour-blind readers
 * (red and green never side by side). Each segment also carries its
 * label and count, so colour is never the only cue.
 */
const STATES = [
    { key: 'reviewed', label: 'Reviewed', colour: '#0e7a0d' },
    { key: 'finalized', label: 'Recorded without review', colour: '#0353ff' },
    { key: 'submitted', label: 'Awaiting review', colour: '#d97706' },
    { key: 'returned', label: 'Returned for correction', colour: '#c50f1f' },
] as const;

const FILTER_KEYS = [
    'q',
    'logbook',
    'sheet',
    'status',
    'from',
    'to',
    'review',
] as const;

const number = (n: number | null) =>
    n === null
        ? '—'
        : n.toLocaleString(undefined, { maximumFractionDigits: 2 });

const share = (part: number, total: number) =>
    total === 0 ? '0%' : `${Math.round((part / total) * 100)}%`;

/**
 * Counts and numeric summaries over submitted entries, with exports that
 * carry the same filters. Drafts are never counted.
 */
export default function Reports({
    filters,
    options,
    counts,
    numbers,
    sheet,
    entries,
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

    return (
        <>
            <Head title="Reports" />
            <PageHeader
                title="Reports"
                description="Submitted entries counted and summed; exports carry the same filters."
                actions={
                    <>
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
            <div className="grid gap-6">
                <Errors
                    errors={errors.export ? { export: errors.export } : {}}
                />

                <Panel>
                    <PanelHeader
                        title="Filters"
                        description={
                            active === 0
                                ? 'Showing every submitted entry you can read.'
                                : `${active} ${active === 1 ? 'filter' : 'filters'} applied.`
                        }
                        actions={
                            active > 0 && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.get(
                                            index().url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    <FilterX /> Clear filters
                                </Button>
                            )
                        }
                    />
                    <PanelBody>
                        <EntryFilters
                            url={index().url}
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
                                    router.get(
                                        index().url,
                                        {
                                            ...query,
                                            review: review || undefined,
                                        },
                                        { preserveState: true, replace: true },
                                    )
                                }
                                options={[
                                    {
                                        value: 'pending',
                                        label: 'Awaiting review',
                                    },
                                    { value: 'reviewed', label: 'Reviewed' },
                                    { value: 'returned', label: 'Returned' },
                                    {
                                        value: 'not_required',
                                        label: 'No review step',
                                    },
                                ]}
                                empty="Any review state"
                            />
                        </EntryFilters>
                    </PanelBody>
                </Panel>

                <Summary counts={counts} />

                {counts.total === 0 ? (
                    <Panel>
                        <EmptyState icon={BarChart3} title="No entries match">
                            Change or clear the filters, or wait for entries to
                            be submitted.
                        </EmptyState>
                    </Panel>
                ) : (
                    <>
                        <div className="grid gap-6 lg:grid-cols-5">
                            <ByDay days={counts.byDay} />
                            <ByContributor
                                people={counts.byContributor}
                                total={counts.total}
                            />
                        </div>

                        {numbers && sheet ? (
                            <NumberFields numbers={numbers} sheet={sheet} />
                        ) : (
                            <p className="flex items-center gap-2 border border-dashed bg-card px-4 py-3 text-sm text-muted-foreground">
                                <Info className="size-4 shrink-0" aria-hidden />
                                Choose one log sheet in the filters to see the
                                total, average, lowest and highest of its number
                                fields.
                            </p>
                        )}

                        <Panel>
                            <PanelHeader
                                title="Entries"
                                description={`${counts.total.toLocaleString()} matching, newest first.`}
                            />
                            <EntriesTable entries={entries.data} />
                            <Pagination page={entries} />
                        </Panel>
                    </>
                )}
            </div>
        </>
    );
}

/** The headline figure and how it splits by state, in one bar. */
function Summary({ counts }: { counts: Props['counts'] }) {
    const total = counts.total;

    return (
        <Panel>
            <PanelBody className="grid gap-5 md:grid-cols-[14rem_1fr] md:items-center">
                <div>
                    <p className="text-[13px] font-semibold text-muted-foreground">
                        Submitted entries
                    </p>
                    <p className="text-4xl font-semibold tracking-tight tabular-nums">
                        {total.toLocaleString()}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        matching the filters
                    </p>
                </div>
                <div className="grid gap-3">
                    <div
                        className={`flex h-3 w-full gap-[2px] overflow-hidden rounded-[4px] ${total > 0 ? 'bg-card' : 'bg-muted'}`}
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
                    <dl className="grid grid-cols-2 gap-x-6 gap-y-3 lg:grid-cols-4">
                        {STATES.map((s) => {
                            const n = counts.byStatus[s.key] ?? 0;
                            return (
                                <div key={s.key} className="grid gap-0.5">
                                    <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <span
                                            className="inline-block size-2.5 shrink-0 rounded-[2px]"
                                            style={{
                                                backgroundColor: s.colour,
                                            }}
                                            aria-hidden
                                        />
                                        {s.label}
                                    </dt>
                                    <dd className="flex items-baseline gap-1.5">
                                        <span className="text-xl font-semibold tabular-nums">
                                            {n.toLocaleString()}
                                        </span>
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {share(n, total)}
                                        </span>
                                    </dd>
                                </div>
                            );
                        })}
                    </dl>
                </div>
            </PanelBody>
        </Panel>
    );
}

/** Entries per day as columns, with the exact count on hover or focus. */
function ByDay({ days }: { days: Props['counts']['byDay'] }) {
    const shown = days.slice(-31);
    const max = Math.max(1, ...shown.map((d) => d.count));
    const average =
        shown.length === 0
            ? 0
            : shown.reduce((a, d) => a + d.count, 0) / shown.length;
    const [hover, setHover] = useState<number | null>(null);
    const current = hover !== null ? shown[hover] : null;

    return (
        <Panel className="lg:col-span-3">
            <PanelHeader
                title="Entries by day"
                description={
                    days.length > 31
                        ? 'The last 31 days with entries, by when the activity happened. Narrow the dates for others.'
                        : 'Days with entries, by when the activity happened.'
                }
            />
            <PanelBody>
                <div className="mb-3 flex h-10 items-end justify-between gap-4 text-sm">
                    {current ? (
                        <p>
                            <span className="font-semibold tabular-nums">
                                {current.count}
                            </span>{' '}
                            {current.count === 1 ? 'entry' : 'entries'} on{' '}
                            {formatDate(current.day)}
                        </p>
                    ) : (
                        <p className="text-muted-foreground">
                            Peak{' '}
                            <span className="font-semibold text-foreground tabular-nums">
                                {max}
                            </span>{' '}
                            · average{' '}
                            <span className="font-semibold text-foreground tabular-nums">
                                {number(Math.round(average * 10) / 10)}
                            </span>{' '}
                            a day
                        </p>
                    )}
                </div>
                <div className="relative">
                    <div
                        className="flex h-44 items-end gap-[2px] border-b border-muted-foreground"
                        onMouseLeave={() => setHover(null)}
                    >
                        {shown.map((d, i) => (
                            <button
                                key={d.day}
                                type="button"
                                className="group flex h-full min-w-0 flex-1 items-end focus-visible:outline-none"
                                aria-label={`${formatDate(d.day)}: ${d.count} ${d.count === 1 ? 'entry' : 'entries'}`}
                                onMouseEnter={() => setHover(i)}
                                onFocus={() => setHover(i)}
                                onBlur={() => setHover(null)}
                            >
                                <span
                                    className={`block w-full rounded-t-[4px] ${hover === i ? 'bg-[#0040c4]' : 'bg-primary'} group-focus-visible:bg-[#0040c4]`}
                                    style={{
                                        height: `${Math.max(2, (d.count / max) * 100)}%`,
                                    }}
                                />
                            </button>
                        ))}
                    </div>
                </div>
                {shown.length > 0 && (
                    <div className="mt-1.5 flex justify-between text-xs text-muted-foreground">
                        <span>{formatDate(shown[0].day)}</span>
                        {shown.length > 1 && (
                            <span>
                                {formatDate(shown[shown.length - 1].day)}
                            </span>
                        )}
                    </div>
                )}
            </PanelBody>
        </Panel>
    );
}

/** Who wrote the entries, largest first, each with a bar and a share. */
function ByContributor({
    people,
    total,
}: {
    people: Props['counts']['byContributor'];
    total: number;
}) {
    const sorted = [...people].sort((a, b) => b.count - a.count);
    const max = Math.max(1, ...sorted.map((p) => p.count));

    return (
        <Panel className="lg:col-span-2">
            <PanelHeader
                title="Entries by contributor"
                description={`${sorted.length} ${sorted.length === 1 ? 'person' : 'people'}`}
            />
            <ul className="max-h-[19rem] divide-y overflow-y-auto">
                {sorted.map((p) => (
                    <li key={p.name} className="grid gap-1.5 px-3 py-2.5">
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="truncate font-medium">
                                {p.name}
                            </span>
                            <span className="shrink-0 tabular-nums">
                                {p.count.toLocaleString()}
                                <span className="ml-1.5 text-xs text-muted-foreground">
                                    {share(p.count, total)}
                                </span>
                            </span>
                        </div>
                        <span className="block h-1.5 rounded-[4px] bg-muted">
                            <span
                                className="block h-full rounded-[4px] bg-primary"
                                style={{ width: `${(p.count / max) * 100}%` }}
                            />
                        </span>
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/** Totals of the chosen log sheet's number fields: cards for the totals, the table for the rest. */
function NumberFields({
    numbers,
    sheet,
}: {
    numbers: NonNullable<Props['numbers']>;
    sheet: NonNullable<Props['sheet']>;
}) {
    return (
        <Panel>
            <PanelHeader
                title={`Number fields · ${sheet.code} ${sheet.name}`}
                description="Across the filtered entries; each entry is read with its own template version."
            />
            {numbers.length === 0 ? (
                <p className="px-3 py-3 text-sm text-muted-foreground">
                    This log sheet has no number fields.
                </p>
            ) : (
                <>
                    <div className="grid gap-px border-b bg-border sm:grid-cols-2 xl:grid-cols-4">
                        {numbers.slice(0, 4).map((n) => (
                            <div key={n.name} className="bg-card px-4 py-3">
                                <p className="truncate text-xs text-muted-foreground">
                                    {n.label} · total
                                </p>
                                <p className="text-2xl font-semibold tabular-nums">
                                    {number(n.sum)}
                                </p>
                                <p className="text-xs text-muted-foreground tabular-nums">
                                    average {number(n.average)} · from {n.count}{' '}
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
        </Panel>
    );
}
