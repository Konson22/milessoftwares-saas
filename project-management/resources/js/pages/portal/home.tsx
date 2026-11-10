import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CircleCheck,
    CircleX,
    Clock,
    FolderKanban,
    FolderPlus,
    ListChecks,
    Wallet,
} from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import {
    ChartPanel,
    ColumnChart,
    Meter,
    SegmentedBar,
} from '@/components/charts';
import type { Part } from '@/components/charts';
import { MyTaskRow } from '@/components/my-task';
import type { MyTask } from '@/components/my-task';
import { Progress, Status } from '@/components/status';
import { useCan } from '@/lib/access';
import { currencyDecimals } from '@/lib/money';
import {
    TASK_STATUSES,
    formatDate,
    formatMinutes,
    statusLabel,
} from '@/lib/status';
import { useMoney } from '@/lib/use-money';
import { myWork } from '@/routes';
import { create, index as projects, show } from '@/routes/projects';

type Portfolio = {
    active: number;
    open: number;
    atRisk: number;
    offTrack: number;
    overdueTasks: number;
    budgetMinor: number | null;
    actualMinor: number | null;
    completedPerWeek: { start: string; count: number }[];
    taskStatus: { status: string; count: number }[];
    paidPerMonth: { month: string; amountMinor: number }[] | null;
    budgets:
        | {
              id: string;
              code: string;
              name: string;
              budgetMinor: number;
              actualMinor: number;
              percent: number;
          }[]
        | null;
    projects: {
        id: string;
        code: string;
        name: string;
        manager: string | null;
        status: string;
        dueOn: string | null;
        progress: number;
        health: string;
        healthReasons: string[];
    }[];
};

type Props = {
    portfolio: Portfolio | null;
    myTasks: MyTask[];
    myMinutesThisWeek: number;
};

const dayMonth = (date: string) =>
    new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

const monthName = (month: string, style: 'short' | 'long') =>
    new Date(`${month}-01T00:00:00Z`).toLocaleDateString(undefined, {
        month: style,
        ...(style === 'long' ? { year: 'numeric' } : {}),
        timeZone: 'UTC',
    });

function CompletedChart({ weeks }: { weeks: Portfolio['completedPerWeek'] }) {
    const total = weeks.reduce((sum, week) => sum + week.count, 0);

    return (
        <ChartPanel
            title="Tasks completed"
            description={`${total.toLocaleString()} in the last ${weeks.length} weeks`}
            empty={
                total === 0
                    ? `No tasks completed in the last ${weeks.length} weeks.`
                    : null
            }
            columns={['Week of', 'Completed']}
            rows={weeks.map((week) => [dayMonth(week.start), week.count])}
        >
            <ColumnChart
                label="Tasks completed each week"
                data={weeks.map((week) => ({
                    key: week.start,
                    label: dayMonth(week.start),
                    title: `Week of ${dayMonth(week.start)}`,
                    value: week.count,
                    valueText: `${week.count} completed`,
                }))}
            />
        </ChartPanel>
    );
}

function HealthChart({ portfolio }: { portfolio: Portfolio }) {
    const parts: Part[] = [
        {
            key: 'on_track',
            label: statusLabel('on_track'),
            value: portfolio.open - portfolio.atRisk - portfolio.offTrack,
            fill: 'var(--viz-good)',
            ink: 'var(--viz-good-ink)',
            icon: CircleCheck,
        },
        {
            key: 'at_risk',
            label: statusLabel('at_risk'),
            value: portfolio.atRisk,
            fill: 'var(--viz-warning)',
            ink: 'var(--viz-warning-ink)',
            icon: AlertTriangle,
        },
        {
            key: 'off_track',
            label: statusLabel('off_track'),
            value: portfolio.offTrack,
            fill: 'var(--viz-critical)',
            ink: 'var(--viz-critical-ink)',
            icon: CircleX,
        },
    ];

    return (
        <ChartPanel
            title="Project health"
            description={`${portfolio.open} open projects`}
            empty={portfolio.open === 0 ? 'No open projects.' : null}
            columns={['Health', 'Projects']}
            rows={parts.map((part) => [part.label, part.value])}
        >
            <SegmentedBar label="Open projects by health" parts={parts} />
        </ChartPanel>
    );
}

function TaskStatusChart({ statuses }: { statuses: Portfolio['taskStatus'] }) {
    const total = statuses.reduce((sum, item) => sum + item.count, 0);
    const parts: Part[] = statuses.map((item) => {
        const step = TASK_STATUSES.indexOf(
            item.status as (typeof TASK_STATUSES)[number],
        );

        return {
            key: item.status,
            label: statusLabel(item.status),
            value: item.count,
            fill: `var(--viz-step-${step + 1})`,
            ink: `var(--viz-step-${step + 1}-ink)`,
        };
    });

    return (
        <ChartPanel
            title="Work in progress"
            description={`${total.toLocaleString()} tasks on open projects`}
            empty={total === 0 ? 'No tasks on open projects.' : null}
            columns={['Status', 'Tasks']}
            rows={parts.map((part) => [part.label, part.value])}
        >
            <SegmentedBar label="Tasks by status" parts={parts} />
        </ChartPanel>
    );
}

function PaidChart({
    months,
}: {
    months: NonNullable<Portfolio['paidPerMonth']>;
}) {
    const { currency } = usePage().props;
    const money = useMoney();
    const total = months.reduce((sum, month) => sum + month.amountMinor, 0);
    const compact = new Intl.NumberFormat(undefined, {
        notation: 'compact',
        maximumFractionDigits: 1,
    });
    const scale = 10 ** currencyDecimals(currency ?? 'USD');

    return (
        <ChartPanel
            title="Paid expenses"
            description={`${money(total)} in the last ${months.length} months`}
            empty={
                total === 0
                    ? `No expenses paid in the last ${months.length} months.`
                    : null
            }
            columns={['Month', 'Paid']}
            rows={months.map((month) => [
                monthName(month.month, 'long'),
                money(month.amountMinor),
            ])}
        >
            <ColumnChart
                label={`Paid expenses each month, in ${currency ?? 'USD'}`}
                axis={(minor) => compact.format(minor / scale)}
                data={months.map((month) => ({
                    key: month.month,
                    label: monthName(month.month, 'short'),
                    title: monthName(month.month, 'long'),
                    value: month.amountMinor,
                    valueText: money(month.amountMinor),
                }))}
            />
        </ChartPanel>
    );
}

function BudgetUse({
    budgets,
}: {
    budgets: NonNullable<Portfolio['budgets']>;
}) {
    const money = useMoney();

    return (
        <Panel>
            <PanelHeader
                title="Budget used"
                description="Time and paid expenses, the most used first."
            />
            {budgets.length === 0 ? (
                <EmptyState icon={Wallet} title="No project budgets set" />
            ) : (
                <ul className="divide-y">
                    {budgets.map((item) => {
                        const over = item.percent > 100;
                        const close = !over && item.percent >= 80;

                        return (
                            <li key={item.id} className="px-3 py-2.5">
                                <div className="mb-1.5 flex items-baseline justify-between gap-3 text-sm">
                                    <Link
                                        href={show({ project: item.id })}
                                        className="min-w-0 truncate font-medium hover:underline"
                                    >
                                        {item.code}{' '}
                                        <span className="font-normal text-muted-foreground">
                                            {item.name}
                                        </span>
                                    </Link>
                                    <span className="flex shrink-0 items-center gap-1 font-medium tabular-nums">
                                        {(over || close) && (
                                            <AlertTriangle
                                                className="size-3.5 self-center"
                                                style={{
                                                    color: over
                                                        ? 'var(--viz-critical)'
                                                        : 'var(--viz-warning)',
                                                }}
                                                aria-hidden
                                            />
                                        )}
                                        {item.percent}%
                                        {over && (
                                            <span className="font-normal text-muted-foreground">
                                                over budget
                                            </span>
                                        )}
                                    </span>
                                </div>
                                <Meter
                                    label={`${item.code} budget used`}
                                    percent={item.percent}
                                    fill={
                                        over
                                            ? 'var(--viz-critical)'
                                            : close
                                              ? 'var(--viz-warning)'
                                              : 'var(--primary)'
                                    }
                                />
                                <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                    {money(item.actualMinor)} of{' '}
                                    {money(item.budgetMinor)}
                                </p>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Panel>
    );
}

/**
 * The portfolio at a glance: how it stands, how work and money are moving,
 * then each project, worst first, and the member's own work.
 */
export default function Home({ portfolio, myTasks, myMinutesThisWeek }: Props) {
    const { organization, user } = usePage().props;
    const can = useCan();
    const money = useMoney();
    const today = new Date().toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
    const spentPercent =
        portfolio?.budgetMinor && portfolio.actualMinor !== null
            ? Math.round((100 * portfolio.actualMinor) / portfolio.budgetMinor)
            : null;

    return (
        <>
            <Head title="Portfolio" />
            <PageHeader
                title={organization?.name ?? 'Portfolio'}
                description={`${today}. Welcome, ${user?.name ?? ''}.`}
                actions={
                    can('projects.manage') && (
                        <Button asChild>
                            <Link href={create()}>
                                <FolderPlus className="size-4" aria-hidden />
                                New project
                            </Link>
                        </Button>
                    )
                }
            />

            {portfolio && (
                <>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Open projects"
                            value={portfolio.open}
                            hint={`${portfolio.active} active`}
                            icon={FolderKanban}
                            href={projects()}
                        />
                        <StatCard
                            label="At risk or off track"
                            value={portfolio.atRisk + portfolio.offTrack}
                            hint={`${portfolio.offTrack} off track`}
                            icon={AlertTriangle}
                            accent={
                                portfolio.offTrack > 0
                                    ? 'red'
                                    : portfolio.atRisk > 0
                                      ? 'amber'
                                      : 'slate'
                            }
                        />
                        <StatCard
                            label="Overdue tasks"
                            value={portfolio.overdueTasks}
                            hint={
                                portfolio.overdueTasks > 0
                                    ? 'Across open projects'
                                    : 'None overdue'
                            }
                            icon={ListChecks}
                            accent={
                                portfolio.overdueTasks > 0 ? 'red' : 'slate'
                            }
                        />
                        {portfolio.budgetMinor !== null &&
                        portfolio.actualMinor !== null ? (
                            <StatCard
                                label="Spent of open budgets"
                                value={money(portfolio.actualMinor)}
                                hint={
                                    <>
                                        of {money(portfolio.budgetMinor)}
                                        {spentPercent !== null && (
                                            <div className="mt-1.5">
                                                <Meter
                                                    label="Open budgets used"
                                                    percent={spentPercent}
                                                />
                                            </div>
                                        )}
                                    </>
                                }
                                icon={Wallet}
                                accent="violet"
                            />
                        ) : (
                            <StatCard
                                label="My hours this week"
                                value={formatMinutes(myMinutesThisWeek)}
                                hint="See my work"
                                icon={Clock}
                                accent="emerald"
                                href={myWork()}
                            />
                        )}
                    </div>

                    <div className="mt-4 grid gap-4 lg:grid-cols-[3fr_2fr]">
                        <CompletedChart weeks={portfolio.completedPerWeek} />
                        <div className="flex flex-col gap-4">
                            <HealthChart portfolio={portfolio} />
                            <TaskStatusChart statuses={portfolio.taskStatus} />
                        </div>
                    </div>

                    {portfolio.paidPerMonth && portfolio.budgets && (
                        <div className="mt-4 grid gap-4 lg:grid-cols-[3fr_2fr]">
                            <PaidChart months={portfolio.paidPerMonth} />
                            <BudgetUse budgets={portfolio.budgets} />
                        </div>
                    )}
                </>
            )}

            <div className="mt-4 grid items-start gap-4 lg:grid-cols-[3fr_2fr]">
                {portfolio && (
                    <Panel>
                        <PanelHeader
                            title="How projects stand"
                            description="Off track first, then at risk."
                            actions={
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={projects()}>
                                        All projects →
                                    </Link>
                                </Button>
                            }
                        />
                        {portfolio.projects.length === 0 ? (
                            <EmptyState
                                icon={FolderKanban}
                                title="No open projects"
                            />
                        ) : (
                            <ul className="divide-y">
                                {portfolio.projects.map((project) => (
                                    <li
                                        key={project.id}
                                        className="px-3 py-2.5 hover:bg-muted/40"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={show({
                                                    project: project.id,
                                                })}
                                                className="min-w-0 flex-1 text-sm font-medium hover:underline"
                                            >
                                                <span className="text-muted-foreground">
                                                    {project.code}
                                                </span>{' '}
                                                {project.name}
                                            </Link>
                                            <Status status={project.health} />
                                        </div>
                                        <Progress
                                            value={project.progress}
                                            className="mt-1.5"
                                        />
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {[
                                                project.manager,
                                                project.dueOn
                                                    ? `Due ${formatDate(project.dueOn)}`
                                                    : null,
                                                ...project.healthReasons,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                )}

                <Panel>
                    <PanelHeader
                        title="My open tasks"
                        description={`${formatMinutes(myMinutesThisWeek)} logged this week`}
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={myWork()}>My work →</Link>
                            </Button>
                        }
                    />
                    {myTasks.length === 0 ? (
                        <EmptyState
                            icon={ListChecks}
                            title="Nothing assigned to you"
                        />
                    ) : (
                        <ul className="divide-y">
                            {myTasks.map((item) => (
                                <MyTaskRow key={item.id} item={item} />
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
