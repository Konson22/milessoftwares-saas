import { Head, Link, router } from '@inertiajs/react';
import { History } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Pagination } from '@/components/entries-table';
import { Field, SelectField } from '@/components/fields';
import type { Paginated } from '@/lib/logbook';
import { action, formatDateTime } from '@/lib/logbook';
import { index } from '@/routes/audit';
import { show as entry } from '@/routes/entries';
import { show as logbook } from '@/routes/logbooks';
import { show as sheet } from '@/routes/sheets';

type Event = {
    id: string;
    action: string;
    subjectType: 'logbook' | 'log_sheet' | 'entry';
    subjectId: string | null;
    subject: string;
    user: string | null;
    details: Record<string, unknown> | null;
    at: string;
};

const link = (e: Event) =>
    e.subjectId === null || e.action === 'entry.discarded'
        ? null
        : e.subjectType === 'entry'
          ? entry(e.subjectId)
          : e.subjectType === 'log_sheet'
            ? sheet(e.subjectId)
            : logbook(e.subjectId);

/** Read-only: no one edits or removes audit events. */
export default function Audit({
    filters,
    events,
}: {
    filters: { type?: string; q?: string };
    events: Paginated<Event>;
}) {
    const [q, setQ] = useState(filters.q ?? '');
    const apply = (next: { type?: string; q?: string }) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(([, v]) => v),
            ),
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Audit trail" />
            <PageHeader
                title="Audit trail"
                description="Every change to logbooks, templates, entries and access, kept permanently."
            />
            <Panel>
                <PanelToolbar className="grid gap-3 sm:grid-cols-3">
                    <SelectField
                        label="Records"
                        value={filters.type ?? ''}
                        onChange={(type) => apply({ type })}
                        options={[
                            { value: 'logbook', label: 'Logbooks and access' },
                            {
                                value: 'log_sheet',
                                label: 'Log sheets and templates',
                            },
                            { value: 'entry', label: 'Entries and reviews' },
                        ]}
                        empty="Everything"
                    />
                    <form
                        className="sm:col-span-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            apply({ q });
                        }}
                    >
                        <Field label="Search">
                            <SearchField
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Person, record or action"
                            />
                        </Field>
                    </form>
                </PanelToolbar>
                {events.data.length === 0 ? (
                    <EmptyState icon={History} title="No events" />
                ) : (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>Who</TableHead>
                                    <TableHead>What</TableHead>
                                    <TableHead>Record</TableHead>
                                    <TableHead>Details</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {events.data.map((e) => {
                                    const href = link(e);

                                    return (
                                        <TableRow key={e.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {formatDateTime(e.at)}
                                            </TableCell>
                                            <TableCell>
                                                {e.user ?? 'Miles Logbook'}
                                            </TableCell>
                                            <TableCell>
                                                {action(e.action)}
                                            </TableCell>
                                            <TableCell>
                                                {href ? (
                                                    <Link
                                                        href={href}
                                                        className="hover:underline"
                                                    >
                                                        {e.subject}
                                                    </Link>
                                                ) : (
                                                    e.subject
                                                )}
                                            </TableCell>
                                            <TableCell className="max-w-xs text-xs text-muted-foreground">
                                                {e.details &&
                                                    Object.entries(e.details)
                                                        .map(
                                                            ([k, v]) =>
                                                                `${k.replace(/_/g, ' ')}: ${Array.isArray(v) ? v.join(', ') : String(v)}`,
                                                        )
                                                        .join(' · ')}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                )}
                <Pagination page={events} />
            </Panel>
        </>
    );
}
