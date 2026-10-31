import { Link } from '@inertiajs/react';
import { Flag, History, ShieldAlert } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { ProjectFrame } from '@/components/project-frame';
import { Progress } from '@/components/status';
import { formatMoney } from '@/lib/money';
import {
    formatDate,
    formatDateTime,
    formatMinutes,
    statusLabel,
    TASK_STATUSES,
} from '@/lib/status';
import { board, budget, team, time } from '@/routes/projects';
import { index as risks } from '@/routes/projects/risks';
import { show as task } from '@/routes/projects/tasks';
import type { ProjectHeader } from '@/types';

type Props = {
    project: ProjectHeader;
    description: string | null;
    metrics: {
        progress: number;
        tasks: number;
        tasksDone: number;
        overdueTasks: number;
        minutes: number;
        budgetMinor: number | null;
        labourMinor: number | null;
        expensesMinor: number | null;
        actualMinor: number | null;
    };
    byStatus: Record<string, number>;
    milestones: {
        id: string;
        name: string;
        dueOn: string;
        completedAt: string | null;
        tasks: number;
        done: number;
        late: boolean;
    }[];
    topRisks: { id: string; title: string; score: number; level: string }[];
    upcoming: {
        id: string;
        number: string;
        title: string;
        dueOn: string;
        overdue: boolean;
        assignee: string | null;
    }[];
    activity: { id: string; description: string; by: string; at: string }[];
};

const LEVEL_TONE = {
    high: 'danger',
    medium: 'warning',
    low: 'neutral',
} as const;

/**
 * A project at a glance: progress, spend, what's next, what could go wrong.
 */
export default function ProjectOverview({
    project,
    description,
    metrics,
    byStatus,
    milestones,
    topRisks,
    upcoming,
    activity,
}: Props) {
    const money = (minor: number) => formatMoney(minor, project.currency);
    const used =
        metrics.budgetMinor && metrics.actualMinor !== null
            ? Math.round((metrics.actualMinor / metrics.budgetMinor) * 100)
            : null;

    return (
        <ProjectFrame project={project} title="Overview">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Tasks done"
                    value={`${metrics.tasksDone} / ${metrics.tasks}`}
                    hint={TASK_STATUSES.filter((status) => status !== 'done')
                        .map(
                            (status) =>
                                `${byStatus[status] ?? 0} ${statusLabel(status).toLowerCase()}`,
                        )
                        .join(' · ')}
                    href={board(project.id)}
                />
                <StatCard
                    label="Overdue tasks"
                    value={metrics.overdueTasks}
                    accent={metrics.overdueTasks > 0 ? 'red' : 'slate'}
                />
                <StatCard
                    label="Time logged"
                    value={formatMinutes(metrics.minutes)}
                    accent="emerald"
                    href={time(project.id)}
                />
                {metrics.actualMinor !== null ? (
                    <StatCard
                        label="Spent"
                        value={money(metrics.actualMinor)}
                        hint={
                            metrics.budgetMinor
                                ? `${used}% of ${money(metrics.budgetMinor)}`
                                : 'No budget set'
                        }
                        accent={used !== null && used > 100 ? 'red' : 'violet'}
                        href={budget(project.id)}
                    />
                ) : (
                    <StatCard
                        label="Open risks"
                        value={topRisks.length}
                        accent="amber"
                        href={risks(project.id)}
                    />
                )}
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-[3fr_2fr]">
                <div className="grid content-start gap-4">
                    {description && (
                        <Panel>
                            <PanelHeader title="Goal and scope" />
                            <PanelBody className="text-sm whitespace-pre-line">
                                {description}
                            </PanelBody>
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader
                            title="Milestones"
                            actions={
                                <Link
                                    href={team(project.id)}
                                    className="text-xs text-muted-foreground hover:underline"
                                >
                                    Manage
                                </Link>
                            }
                        />
                        {milestones.length === 0 ? (
                            <EmptyState icon={Flag} title="No milestones yet" />
                        ) : (
                            <ul className="divide-y">
                                {milestones.map((milestone) => (
                                    <li
                                        key={milestone.id}
                                        className="grid gap-1 px-3 py-2 text-sm"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="flex-1 font-medium">
                                                {milestone.name}
                                            </span>
                                            {milestone.completedAt ? (
                                                <StatusBadge tone="success">
                                                    Reached
                                                </StatusBadge>
                                            ) : milestone.late ? (
                                                <StatusBadge tone="danger">
                                                    Late
                                                </StatusBadge>
                                            ) : null}
                                            <span className="text-xs text-muted-foreground">
                                                {formatDate(milestone.dueOn)}
                                            </span>
                                        </div>
                                        {milestone.tasks > 0 && (
                                            <Progress
                                                value={Math.round(
                                                    (milestone.done /
                                                        milestone.tasks) *
                                                        100,
                                                )}
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel>
                        <PanelHeader title="Coming up" />
                        {upcoming.length === 0 ? (
                            <p className="px-3 py-4 text-sm text-muted-foreground">
                                No open tasks with due dates.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {upcoming.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-center gap-2 px-3 py-2 text-sm"
                                    >
                                        <Link
                                            href={task({
                                                project: project.id,
                                                task: item.id,
                                            })}
                                            className="min-w-0 flex-1 truncate hover:underline"
                                        >
                                            <span className="text-muted-foreground">
                                                {item.number}
                                            </span>{' '}
                                            {item.title}
                                        </Link>
                                        <span className="text-xs text-muted-foreground">
                                            {item.assignee ?? 'Unassigned'}
                                        </span>
                                        <span
                                            className={
                                                item.overdue
                                                    ? 'text-xs font-medium text-destructive'
                                                    : 'text-xs text-muted-foreground'
                                            }
                                        >
                                            {formatDate(item.dueOn)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <div className="grid content-start gap-4">
                    <Panel>
                        <PanelHeader
                            title="Top risks"
                            actions={
                                <Link
                                    href={risks(project.id)}
                                    className="text-xs text-muted-foreground hover:underline"
                                >
                                    Register
                                </Link>
                            }
                        />
                        {topRisks.length === 0 ? (
                            <EmptyState
                                icon={ShieldAlert}
                                title="No open risks"
                            />
                        ) : (
                            <ul className="divide-y">
                                {topRisks.map((risk) => (
                                    <li
                                        key={risk.id}
                                        className="flex items-center gap-2 px-3 py-2 text-sm"
                                    >
                                        <span className="flex-1">
                                            {risk.title}
                                        </span>
                                        <StatusBadge
                                            tone={
                                                LEVEL_TONE[
                                                    risk.level as keyof typeof LEVEL_TONE
                                                ] ?? 'neutral'
                                            }
                                        >
                                            {risk.score}
                                        </StatusBadge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel>
                        <PanelHeader title="Activity" />
                        {activity.length === 0 ? (
                            <EmptyState icon={History} title="Nothing yet" />
                        ) : (
                            <ul className="divide-y">
                                {activity.map((item) => (
                                    <li
                                        key={item.id}
                                        className="px-3 py-2 text-sm"
                                    >
                                        <p>{item.description}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {item.by} ·{' '}
                                            {formatDateTime(item.at)}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </div>
        </ProjectFrame>
    );
}
