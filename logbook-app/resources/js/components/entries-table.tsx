import { TriangleAlert } from 'lucide-react';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { SearchField } from '@miles/ui/components/search-field';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, SelectField, Status } from '@/components/fields';
import type { EntryRow, Option, Paginated } from '@/lib/logbook';
import { ENTRY_STATUSES, formatDateTime, status } from '@/lib/logbook';
import { show } from '@/routes/entries';

/**
 * Entries in a table: reference, sheet, when, who, status. Works as cards
 * on narrow screens.
 */
export function EntriesTable({
    entries,
    showCreator = true,
}: {
    entries: EntryRow[];
    showCreator?: boolean;
}) {
    return (
        <>
            <div className="hidden md:block">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Reference</TableHead>
                            <TableHead>Log sheet</TableHead>
                            <TableHead>Occurred</TableHead>
                            {showCreator && <TableHead>By</TableHead>}
                            <TableHead>Summary</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {entries.map((e) => (
                            <TableRow key={e.id}>
                                <TableCell className="whitespace-nowrap">
                                    <Link
                                        href={show(e.id)}
                                        className="font-medium hover:underline"
                                    >
                                        {e.reference ?? 'Draft'}
                                    </Link>
                                </TableCell>
                                <TableCell>
                                    {e.sheet.name}
                                    <span className="block text-xs text-muted-foreground">
                                        {e.logbook}
                                    </span>
                                </TableCell>
                                <TableCell className="whitespace-nowrap">
                                    {formatDateTime(e.occurredAt)}
                                </TableCell>
                                {showCreator && (
                                    <TableCell>{e.creator}</TableCell>
                                )}
                                <TableCell className="max-w-sm truncate text-muted-foreground">
                                    {e.summary}
                                </TableCell>
                                <TableCell>
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <Status value={e.status} />
                                        <RangeFlag count={e.outOfRange} />
                                    </span>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            <ul className="divide-y md:hidden">
                {entries.map((e) => (
                    <li key={e.id}>
                        <Link
                            href={show(e.id)}
                            className="grid gap-1 px-4 py-3"
                        >
                            <span className="flex items-center justify-between gap-2">
                                <span className="font-medium">
                                    {e.reference ?? 'Draft'}
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <RangeFlag count={e.outOfRange} />
                                    <Status value={e.status} />
                                </span>
                            </span>
                            <span className="text-sm">{e.sheet.name}</span>
                            <span className="text-xs text-muted-foreground">
                                {formatDateTime(e.occurredAt)}
                                {showCreator && ` · ${e.creator}`}
                            </span>
                            {e.summary && (
                                <span className="truncate text-xs text-muted-foreground">
                                    {e.summary}
                                </span>
                            )}
                        </Link>
                    </li>
                ))}
            </ul>
        </>
    );
}

export function Pagination({ page }: { page: Paginated<unknown> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-2 border-t px-4 py-3 text-sm">
            <span className="text-muted-foreground">
                Page {page.current_page} of {page.last_page} · {page.total}{' '}
                entries
            </span>
            <div className="flex gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!page.prev_page_url}
                    onClick={() =>
                        page.prev_page_url &&
                        router.visit(page.prev_page_url, {
                            preserveScroll: true,
                        })
                    }
                >
                    Previous
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!page.next_page_url}
                    onClick={() =>
                        page.next_page_url &&
                        router.visit(page.next_page_url, {
                            preserveScroll: true,
                        })
                    }
                >
                    Next
                </Button>
            </div>
        </div>
    );
}

export type Filters = {
    logbook?: string;
    sheet?: string;
    from?: string;
    to?: string;
    status?: string;
    review?: string;
    q?: string;
};

export type FilterOptions = {
    logbooks: Option[];
    sheets: (Option & { logbook: string })[];
};

/**
 * The filters every entry list and report takes. Applied on the server.
 */
export function EntryFilters({
    url,
    filters,
    options,
    extra = {},
    statuses = ENTRY_STATUSES,
}: {
    url: string;
    filters: Filters;
    options: FilterOptions;
    extra?: Record<string, string | undefined>;
    statuses?: string[];
}) {
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (next: Filters) =>
        router.get(
            url,
            Object.fromEntries(
                Object.entries({ ...extra, ...filters, ...next }).filter(
                    ([, v]) => v !== undefined && v !== '',
                ),
            ),
            { preserveState: true, replace: true },
        );
    const sheets = filters.logbook
        ? options.sheets.filter((s) => s.logbook === filters.logbook)
        : options.sheets;

    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
            <form
                className="sm:col-span-2 lg:col-span-4 xl:col-span-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    apply({ q });
                }}
            >
                <Field label="Search">
                    <SearchField
                        value={q}
                        placeholder="Reference or any answer"
                        onChange={(e) => setQ(e.target.value)}
                        onBlur={() => q !== (filters.q ?? '') && apply({ q })}
                    />
                </Field>
            </form>
            <SelectField
                label="Logbook"
                value={filters.logbook ?? ''}
                onChange={(v) => apply({ logbook: v, sheet: '' })}
                options={options.logbooks}
                empty="All logbooks"
            />
            <SelectField
                label="Log sheet"
                value={filters.sheet ?? ''}
                onChange={(v) => apply({ sheet: v })}
                options={sheets}
                empty="All sheets"
            />
            <SelectField
                label="Status"
                value={filters.status ?? ''}
                onChange={(v) => apply({ status: v })}
                options={statuses.map((s) => ({
                    value: s,
                    label: status(s).label,
                }))}
                empty="Any status"
            />
            <Field label="From">
                <Input
                    type="date"
                    value={filters.from ?? ''}
                    onChange={(e) => apply({ from: e.target.value })}
                />
            </Field>
            <Field label="To">
                <Input
                    type="date"
                    value={filters.to ?? ''}
                    onChange={(e) => apply({ to: e.target.value })}
                />
            </Field>
        </div>
    );
}

/** "2 out of range": readings outside their normal range on this entry. */
function RangeFlag({ count }: { count: number }) {
    if (count === 0) {
        return null;
    }

    return (
        <span
            className="inline-flex items-center gap-1 rounded-sm border border-amber-300 bg-amber-50 px-1.5 text-xs font-medium whitespace-nowrap text-amber-900"
            title="Readings outside their normal range"
        >
            <TriangleAlert className="size-3" aria-hidden />
            {count} out of range
        </span>
    );
}
