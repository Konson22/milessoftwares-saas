import { Head, Link, router } from '@inertiajs/react';
import { Plus, SearchCheck } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { SearchField } from '@miles/ui/components/search-field';
import { Button } from '@miles/ui/components/ui/button';
import { Pagination } from '@/components/entries-table';
import { PageHeader } from '@/components/page-header';
import type { Paginated } from '@/lib/logbook';
import { formatDate, formatDateTime } from '@/lib/logbook';
import { create, index, show } from '@/routes/investigations';

export type InvestigationRow = {
    id: string;
    reference: string;
    title: string;
    status: string;
    statusLabel: string;
    logbook: string;
    investigator: string;
    resource: string | null;
    occurredAt: string | null;
    period: [string | null, string | null];
    evidenceCount: number | null;
    createdAt: string | null;
};

/** Solid tints, each with its label: colour is never the only cue. */
const STATUS_STYLE: Record<string, string> = {
    open: 'bg-[#e8eefb] text-[#1d4396]',
    investigating: 'bg-[#fcefdc] text-[#8a4b00]',
    findings_recorded: 'bg-[#e3f3ee] text-[#0a5c48]',
    closed: 'bg-[#eef0f2] text-[#3d4651]',
};

export function InvestigationStatus({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    return (
        <span
            className={`inline-flex rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ${STATUS_STYLE[status] ?? STATUS_STYLE.closed}`}
        >
            {label}
        </span>
    );
}

const TABS = [
    { key: 'active', label: 'Active' },
    { key: 'open', label: 'Open' },
    { key: 'investigating', label: 'Investigating' },
    { key: 'findings_recorded', label: 'Findings recorded' },
    { key: 'closed', label: 'Closed' },
    { key: 'any', label: 'All' },
];

/**
 * Investigations: incidents being looked into, each with the records it
 * refers to and what the investigator found.
 */
export default function Investigations({
    investigations,
    filters,
    counts,
    canManage,
}: {
    investigations: Paginated<InvestigationRow>;
    filters: { status: string; q: string | null };
    counts: Record<string, number>;
    canManage: boolean;
}) {
    const [q, setQ] = useState(filters.q ?? '');
    const go = (next: Record<string, string | undefined>) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({
                    status: filters.status,
                    q: filters.q ?? undefined,
                    ...next,
                }).filter(([, v]) => v !== undefined && v !== ''),
            ),
            { preserveState: true, replace: true },
        );
    const active =
        (counts.open ?? 0) +
        (counts.investigating ?? 0) +
        (counts.findings_recorded ?? 0);
    const total = Object.values(counts).reduce((a, b) => a + b, 0);
    const count = (key: string) =>
        key === 'active' ? active : key === 'any' ? total : (counts[key] ?? 0);

    return (
        <>
            <Head title="Investigations" />
            <PageHeader
                title="Investigations"
                actions={
                    canManage && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> New investigation
                            </Link>
                        </Button>
                    )
                }
            />

            <section className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
                <div className="flex flex-wrap items-center gap-3 border-b border-[#ebebeb] px-4 py-3">
                    <div className="flex flex-wrap gap-1" role="group">
                        {TABS.map((t) => (
                            <button
                                key={t.key}
                                type="button"
                                aria-pressed={filters.status === t.key}
                                onClick={() => go({ status: t.key })}
                                className={`rounded-full px-3 py-1 text-sm font-medium ${
                                    filters.status === t.key
                                        ? 'bg-[#16202c] text-white'
                                        : 'bg-[#f1f3f5] text-[#3d4651] hover:bg-[#e6e9ec]'
                                }`}
                            >
                                {t.label}{' '}
                                <span className="tabular-nums">
                                    {count(t.key)}
                                </span>
                            </button>
                        ))}
                    </div>
                    <form
                        className="ml-auto w-full sm:w-64"
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({ q });
                        }}
                    >
                        <SearchField
                            value={q}
                            placeholder="Reference or title"
                            onChange={(e) => setQ(e.target.value)}
                            onBlur={() => q !== (filters.q ?? '') && go({ q })}
                        />
                    </form>
                </div>

                {investigations.data.length === 0 ? (
                    <EmptyState icon={SearchCheck} title="No investigations">
                        {canManage
                            ? 'Open one from a record in Reports, or start one here.'
                            : 'None in the logbooks you can see.'}
                    </EmptyState>
                ) : (
                    <ul className="divide-y divide-[#f0f0f0]">
                        {investigations.data.map((i) => (
                            <li key={i.id}>
                                <Link
                                    href={show(i.id)}
                                    className="grid gap-1 px-4 py-3 hover:bg-[#f4f8f7] sm:grid-cols-[1fr_auto] sm:items-center"
                                >
                                    <span className="grid gap-0.5">
                                        <span className="flex flex-wrap items-center gap-2">
                                            <span className="font-mono text-xs font-semibold">
                                                {i.reference}
                                            </span>
                                            <span className="font-medium">
                                                {i.title}
                                            </span>
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {i.logbook}
                                            {i.resource && ` · ${i.resource}`}
                                            {' · '}
                                            {i.investigator}
                                            {i.occurredAt &&
                                                ` · incident ${formatDateTime(i.occurredAt)}`}
                                            {' · '}
                                            {i.evidenceCount ?? 0} linked
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-3">
                                        <span className="text-xs text-muted-foreground">
                                            opened {formatDate(i.createdAt)}
                                        </span>
                                        <InvestigationStatus
                                            status={i.status}
                                            label={i.statusLabel}
                                        />
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination page={investigations} />
            </section>
        </>
    );
}
