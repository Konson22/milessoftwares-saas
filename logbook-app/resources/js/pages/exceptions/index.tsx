import { Head, router, usePage } from '@inertiajs/react';
import {
    CircleCheck,
    Clock,
    Search,
    ShieldAlert,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Pagination } from '@/components/entries-table';
import { ExceptionTable } from '@/components/exceptions/exception-table';
import { Check, SelectField, TextArea, TextField } from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import { Tabs, Toggle } from '@/components/work';
import type { Option, Paginated } from '@/lib/logbook';
import type { ExceptionRow } from '@/lib/work';
import { SEVERITIES } from '@/lib/work';
import { index, store } from '@/routes/exceptions';

type Props = {
    filters: { status: string; severity: string; mine: boolean; q: string };
    counts: Record<string, number>;
    openBySeverity: Record<ExceptionRow['severity'], number>;
    exceptions: Paginated<ExceptionRow>;
    canReport: boolean;
    options: { logbooks: Option[]; resources: Option[] };
};

const TABS = [
    { value: 'active', label: 'Open' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'resolved', label: 'Resolved' },
    { value: 'closed', label: 'Closed' },
    { value: 'any', label: 'All' },
];

export default function Exceptions({
    filters,
    counts,
    openBySeverity,
    exceptions,
    canReport,
    options,
}: Props) {
    const [q, setQ] = useState(filters.q);
    const query = (patch: Record<string, string | boolean>) => {
        const next = { ...filters, ...patch };

        return {
            status: next.status,
            severity: next.severity || undefined,
            q: next.q || undefined,
            mine: next.mine ? 1 : undefined,
        };
    };
    const go = (patch: Record<string, string | boolean>) =>
        router.get(index().url, query(patch), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const open = (counts.open ?? 0) + (counts.in_progress ?? 0);
    const tabCount: Record<string, number> = {
        active: open,
        overdue: counts.overdue ?? 0,
        resolved: counts.resolved ?? 0,
        closed: counts.closed ?? 0,
    };

    return (
        <>
            <Head title="Exceptions" />
            <PageHeader
                title="Exceptions"
                actions={
                    canReport && (
                        <Toggle label="Report an exception">
                            {(close) => (
                                <ReportForm options={options} onDone={close} />
                            )}
                        </Toggle>
                    )
                }
            />

            <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Open"
                    value={open}
                    hint={`${counts.in_progress ?? 0} in progress`}
                    icon={TriangleAlert}
                    accent="blue"
                    href={index({ query: query({ status: 'active' }) })}
                />
                <StatCard
                    label="Critical or high"
                    value={openBySeverity.critical + openBySeverity.high}
                    hint={`${openBySeverity.critical} critical · ${openBySeverity.high} high`}
                    icon={ShieldAlert}
                    accent={
                        openBySeverity.critical + openBySeverity.high > 0
                            ? 'red'
                            : 'slate'
                    }
                    href={index({
                        query: query({
                            status: 'active',
                            severity: 'critical',
                        }),
                    })}
                />
                <StatCard
                    label="Overdue"
                    value={counts.overdue ?? 0}
                    hint="Open past their due date"
                    icon={Clock}
                    accent={(counts.overdue ?? 0) > 0 ? 'amber' : 'slate'}
                    href={index({ query: query({ status: 'overdue' }) })}
                />
                <StatCard
                    label="Resolved"
                    value={(counts.resolved ?? 0) + (counts.closed ?? 0)}
                    hint={`${counts.closed ?? 0} closed after checking`}
                    icon={CircleCheck}
                    accent="emerald"
                    href={index({ query: query({ status: 'resolved' }) })}
                />
            </div>

            <Tabs
                items={TABS.map((t) => ({
                    label:
                        tabCount[t.value] !== undefined
                            ? `${t.label} (${tabCount[t.value]})`
                            : t.label,
                    href: index({ query: query({ status: t.value }) }).url,
                    active: filters.status === t.value,
                }))}
            />

            <Panel>
                <PanelToolbar>
                    <div className="flex w-full flex-wrap items-center gap-3">
                        <form
                            className="relative w-full sm:w-72"
                            onSubmit={(e) => {
                                e.preventDefault();
                                go({ q });
                            }}
                        >
                            <Search
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <Input
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                onBlur={() => q !== filters.q && go({ q })}
                                placeholder="Search reference or title"
                                aria-label="Search exceptions"
                                className="pl-9"
                            />
                        </form>
                        <SelectField
                            label="Severity"
                            value={filters.severity}
                            onChange={(severity) => go({ severity })}
                            options={SEVERITIES}
                            empty="Any severity"
                            className="w-40 [&_label]:sr-only"
                        />
                        <Check
                            label="Assigned to me"
                            checked={filters.mine}
                            onChange={(mine) => go({ mine })}
                        />
                        <span className="text-sm text-muted-foreground sm:ml-auto">
                            {exceptions.total ?? exceptions.data.length}{' '}
                            {(exceptions.total ?? exceptions.data.length) === 1
                                ? 'exception'
                                : 'exceptions'}
                        </span>
                    </div>
                </PanelToolbar>
                {exceptions.data.length === 0 ? (
                    <EmptyState icon={TriangleAlert} title="No exceptions here">
                        Exceptions are raised by readings and answers set to
                        open one, or reported by hand.
                    </EmptyState>
                ) : (
                    <>
                        <ExceptionTable rows={exceptions.data} />
                        <Pagination page={exceptions} />
                    </>
                )}
            </Panel>
        </>
    );
}

function ReportForm({
    options,
    onDone,
}: {
    options: Props['options'];
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        title: '',
        description: '',
        severity: 'medium',
        logbook_id: '',
        resource_id: '',
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    store().url,
                    {
                        ...data,
                        logbook_id: data.logbook_id || null,
                        resource_id: data.resource_id || null,
                    },
                    { onSuccess: onDone },
                );
            }}
        >
            <TextField
                label="What is wrong"
                value={data.title}
                onChange={(title) => set({ title })}
                error={errors.title}
                className="sm:col-span-2"
                required
            />
            <TextArea
                label="Details"
                value={data.description}
                onChange={(description) => set({ description })}
                className="sm:col-span-2"
            />
            <SelectField
                label="Severity"
                value={data.severity}
                onChange={(severity) => set({ severity })}
                options={SEVERITIES}
            />
            <SelectField
                label="Logbook"
                value={data.logbook_id}
                onChange={(logbook_id) => set({ logbook_id })}
                options={options.logbooks}
                empty="None"
                error={errors.logbook_id}
            />
            <SelectField
                label="Resource"
                value={data.resource_id}
                onChange={(resource_id) => set({ resource_id })}
                options={options.resources}
                empty="None"
                error={errors.resource_id}
            />
            <div className="sm:col-span-2">
                <Button type="submit">Report</Button>
            </div>
        </form>
    );
}
