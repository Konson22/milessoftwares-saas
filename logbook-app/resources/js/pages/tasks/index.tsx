import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardList,
    ListTodo,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-react';
import { StatCard } from '@miles/ui/components/stat-card';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelHeader, PanelToolbar } from '@miles/ui/components/panel';
import { Input } from '@miles/ui/components/ui/input';
import { Pagination } from '@/components/entries-table';
import { SelectField } from '@/components/fields';
import { ChartCard } from '@/components/dashboard/chart-card';
import {
    DueSoonChart,
    PriorityChart,
    TaskFlowChart,
    WorkloadChart,
} from '@/components/tasks/task-charts';
import type { TaskCharts } from '@/components/tasks/task-charts';
import {
    SeriesForm,
    SeriesList,
    Tabs,
    TaskForm,
    TaskTable,
    Toggle,
} from '@/components/work';
import type { Paginated } from '@/lib/logbook';
import type { SeriesRow, TaskOptions, TaskRow } from '@/lib/work';
import { index } from '@/routes/tasks';

type Tab = 'mine' | 'given' | 'all' | 'recurring';

type Props = {
    tab: Tab;
    filters: { status: string; q: string; sort: string };
    counts: Record<string, number>;
    charts: TaskCharts | null;
    tasks: Paginated<TaskRow> | null;
    series: SeriesRow[] | null;
    canManage: boolean;
    options: TaskOptions | null;
};

const FILTERS = [
    { value: 'open', label: 'Still open' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'pending', label: 'To do' },
    { value: 'in_progress', label: 'In progress' },
    { value: 'awaiting_verification', label: 'Awaiting verification' },
    { value: 'returned', label: 'Returned' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'any', label: 'Any status' },
];

export default function Tasks({
    tab,
    filters,
    counts,
    charts,
    tasks,
    series,
    canManage,
    options,
}: Props) {
    const go = (query: Record<string, string>) =>
        router.get(
            index().url,
            { tab, ...filters, ...query },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const tabs: { key: Tab; label: string }[] = [
        { key: 'mine', label: 'My tasks' },
        ...(canManage
            ? ([
                  { key: 'given', label: 'Given by me' },
                  { key: 'all', label: 'All tasks' },
                  { key: 'recurring', label: 'Recurring' },
              ] as const)
            : []),
    ];

    return (
        <>
            <Head title="Tasks" />
            <PageHeader
                title="Tasks"
                description="Tasks, recurring work and their status across your logbooks."
                actions={
                    canManage &&
                    options &&
                    (tab === 'recurring' ? (
                        <Toggle label="New recurring task">
                            {(close) => (
                                <SeriesForm options={options} onDone={close} />
                            )}
                        </Toggle>
                    ) : (
                        <Toggle label="New task">
                            {(close) => (
                                <TaskForm options={options} onDone={close} />
                            )}
                        </Toggle>
                    ))
                }
            />
            <Tabs
                items={tabs.map((t) => ({
                    label: t.label,
                    href: index({ query: { tab: t.key } }).url,
                    active: tab === t.key,
                }))}
            />
            {tab !== 'recurring' && (
                <div className="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Open tasks"
                        value={
                            (counts.pending ?? 0) +
                            (counts.in_progress ?? 0) +
                            (counts.returned ?? 0)
                        }
                        hint="To do, in progress or returned"
                        icon={ClipboardList}
                        accent="blue"
                        href={index({ query: { tab, status: 'open' } })}
                    />
                    <StatCard
                        label="Overdue"
                        value={counts.overdue ?? 0}
                        hint="Past their due date"
                        icon={TriangleAlert}
                        accent={(counts.overdue ?? 0) > 0 ? 'red' : 'slate'}
                        href={index({ query: { tab, status: 'overdue' } })}
                    />
                    <StatCard
                        label="Awaiting verification"
                        value={counts.awaiting_verification ?? 0}
                        hint="Work reported, to be checked"
                        icon={ShieldCheck}
                        accent="amber"
                        href={index({
                            query: { tab, status: 'awaiting_verification' },
                        })}
                    />
                    <StatCard
                        label="Completed"
                        value={counts.completed ?? 0}
                        hint="Done and verified"
                        icon={CheckCircle2}
                        accent="emerald"
                        href={index({ query: { tab, status: 'completed' } })}
                    />
                </div>
            )}
            {charts && (
                <div className="mb-5 grid gap-5 xl:grid-cols-3">
                    <ChartCard
                        className={tab === 'mine' ? '' : 'xl:col-span-2'}
                        title="Created and completed"
                        note="Per day, last 14 days"
                    >
                        <TaskFlowChart data={charts.flow} />
                    </ChartCard>
                    <ChartCard title="Due soon" note="Open tasks, next 7 days">
                        <DueSoonChart data={charts.due} />
                    </ChartCard>
                    {tab !== 'mine' && (
                        <ChartCard
                            className="xl:col-span-2"
                            title="Workload"
                            note="Open tasks per person"
                        >
                            <WorkloadChart data={charts.workload} />
                        </ChartCard>
                    )}
                    <ChartCard title="By priority" note="Open tasks">
                        <PriorityChart data={charts.priority} />
                    </ChartCard>
                </div>
            )}
            {tab === 'recurring' ? (
                <Panel>
                    <PanelHeader title="Recurring tasks" />
                    {series && series.length > 0 && options ? (
                        <SeriesList items={series} options={options} />
                    ) : (
                        <EmptyState icon={ListTodo} title="No recurring tasks">
                            Recurring tasks are made a day ahead, each one given
                            to the person you choose.
                        </EmptyState>
                    )}
                </Panel>
            ) : (
                <Panel>
                    <PanelHeader title={`Tasks (${tasks?.total ?? 0})`} />
                    <PanelToolbar>
                        <div className="flex flex-wrap items-end gap-3">
                            <SelectField
                                label="Show"
                                value={filters.status}
                                onChange={(status) => go({ status })}
                                options={FILTERS.map((f) => ({
                                    ...f,
                                    label:
                                        counts[f.value] !== undefined
                                            ? `${f.label} (${counts[f.value]})`
                                            : f.label,
                                }))}
                                className="w-56"
                            />
                            <SelectField
                                label="Sort by"
                                value={filters.sort}
                                onChange={(sort) => go({ sort })}
                                options={[
                                    { value: 'due', label: 'Due date' },
                                    { value: 'priority', label: 'Priority' },
                                    { value: 'created', label: 'Newest' },
                                ]}
                                className="w-40"
                            />
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    const q = new FormData(e.currentTarget).get(
                                        'q',
                                    );
                                    go({ q: typeof q === 'string' ? q : '' });
                                }}
                            >
                                <Input
                                    name="q"
                                    defaultValue={filters.q}
                                    placeholder="Search reference or title"
                                    aria-label="Search tasks"
                                    className="w-64"
                                />
                            </form>
                        </div>
                    </PanelToolbar>
                    {tasks && tasks.data.length > 0 ? (
                        <>
                            <TaskTable
                                tasks={tasks.data}
                                showAssignee={tab !== 'mine'}
                            />
                            <Pagination page={tasks} />
                        </>
                    ) : (
                        <EmptyState icon={ListTodo} title="No tasks here">
                            {tab === 'mine'
                                ? 'Tasks given to you appear here, the next due first.'
                                : 'No task matches these filters.'}
                        </EmptyState>
                    )}
                </Panel>
            )}
        </>
    );
}
