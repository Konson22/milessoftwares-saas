import { Head, router, usePage } from '@inertiajs/react';
import { BarChart3, FileDown, FileSpreadsheet } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
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

const number = (n: number | null) =>
    n === null
        ? '—'
        : n.toLocaleString(undefined, { maximumFractionDigits: 4 });

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
    const maxDay = Math.max(1, ...counts.byDay.map((d) => d.count));

    return (
        <>
            <Head title="Reports" />
            <PageHeader
                title="Reports"
                description="Submitted entries you can read. Choose one log sheet for totals of its number fields."
                actions={
                    <>
                        <Button asChild variant="outline">
                            <a href={csv({ query }).url}>
                                <FileSpreadsheet /> CSV
                            </a>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            disabled={counts.total > pdfLimit}
                        >
                            <a
                                href={pdf({ query }).url}
                                aria-disabled={counts.total > pdfLimit}
                                title={
                                    counts.total > pdfLimit
                                        ? `At most ${pdfLimit} entries per PDF`
                                        : undefined
                                }
                            >
                                <FileDown /> PDF
                            </a>
                        </Button>
                    </>
                }
            />
            <Errors errors={errors.export ? { export: errors.export } : {}} />
            <Panel className="mb-6">
                <PanelToolbar className="grid gap-3">
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
                    />
                    <SelectField
                        className="max-w-xs"
                        label="Review"
                        value={filters.review ?? ''}
                        onChange={(review) =>
                            router.get(
                                index().url,
                                { ...query, review: review || undefined },
                                { preserveState: true, replace: true },
                            )
                        }
                        options={[
                            { value: 'pending', label: 'Awaiting review' },
                            { value: 'reviewed', label: 'Reviewed' },
                            { value: 'returned', label: 'Returned' },
                            { value: 'not_required', label: 'No review step' },
                        ]}
                        empty="Any"
                    />
                </PanelToolbar>
            </Panel>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <StatCard label="Entries" value={counts.total} accent="slate" />
                <StatCard
                    label="Awaiting review"
                    value={counts.byStatus.submitted ?? 0}
                    accent="amber"
                />
                <StatCard
                    label="Returned"
                    value={counts.byStatus.returned ?? 0}
                    accent="red"
                />
                <StatCard
                    label="Reviewed"
                    value={counts.byStatus.reviewed ?? 0}
                    accent="emerald"
                />
                <StatCard
                    label="Recorded without review"
                    value={counts.byStatus.finalized ?? 0}
                    accent="blue"
                />
            </div>

            {counts.total === 0 ? (
                <Panel className="mt-6">
                    <EmptyState icon={BarChart3} title="No entries match">
                        Change the filters, or wait for entries to be submitted.
                    </EmptyState>
                </Panel>
            ) : (
                <>
                    {numbers && sheet && (
                        <Panel className="mt-6">
                            <PanelHeader
                                title={`${sheet.code} · number fields`}
                                description="Across the filtered entries; each entry is read with its own template version."
                            />
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
                                                <TableCell>{n.label}</TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {n.count}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
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
                        </Panel>
                    )}

                    <div className="mt-6 grid gap-6 lg:grid-cols-2">
                        <Panel>
                            <PanelHeader
                                title="Entries by day"
                                description="Days with entries, by when the activity happened."
                            />
                            <PanelBody>
                                <ul className="grid gap-1.5">
                                    {counts.byDay.slice(-31).map((d) => (
                                        <li
                                            key={d.day}
                                            className="grid grid-cols-[7rem_1fr_3rem] items-center gap-2 text-sm"
                                        >
                                            <span className="text-muted-foreground">
                                                {formatDate(d.day)}
                                            </span>
                                            <span className="h-2 rounded bg-muted">
                                                <span
                                                    className="block h-2 rounded bg-primary"
                                                    style={{
                                                        width: `${(d.count / maxDay) * 100}%`,
                                                    }}
                                                />
                                            </span>
                                            <span className="text-right tabular-nums">
                                                {d.count}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                {counts.byDay.length > 31 && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        The last 31 days with entries; narrow
                                        the dates for others.
                                    </p>
                                )}
                            </PanelBody>
                        </Panel>
                        <Panel>
                            <PanelHeader title="Entries by contributor" />
                            <Table>
                                <TableBody>
                                    {counts.byContributor.map((c) => (
                                        <TableRow key={c.name}>
                                            <TableCell>{c.name}</TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {c.count}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Panel>
                    </div>

                    <Panel className="mt-6">
                        <PanelHeader title="Entries" />
                        <EntriesTable entries={entries.data} />
                        <Pagination page={entries} />
                    </Panel>
                </>
            )}
        </>
    );
}
