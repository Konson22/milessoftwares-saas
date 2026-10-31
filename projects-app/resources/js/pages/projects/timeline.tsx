import { Link } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { cn } from '@miles/ui/lib/utils';
import { ProjectFrame } from '@/components/project-frame';
import { formatDate, formatDay, todayIso } from '@/lib/status';
import { show } from '@/routes/projects/tasks';
import type { ProjectHeader } from '@/types';

type Props = {
    project: ProjectHeader;
    tasks: {
        id: string;
        number: string;
        title: string;
        status: string;
        assignee: string | null;
        startOn: string;
        dueOn: string;
        overdue: boolean;
        waitsFor: string[];
    }[];
    milestones: { id: string; name: string; dueOn: string; done: boolean }[];
    undated: number;
};

const DAY = 86_400_000;

const BAR: Record<string, string> = {
    todo: 'bg-slate-400',
    in_progress: 'bg-primary',
    review: 'bg-amber-500',
    done: 'bg-emerald-500',
};

function day(date: string): number {
    return Date.parse(`${date}T00:00:00Z`) / DAY;
}

/**
 * The plan as a Gantt chart: each dated task as a bar from start to due,
 * milestones as markers, today as a line.
 */
export default function Timeline({
    project,
    tasks,
    milestones,
    undated,
}: Props) {
    const today = todayIso();
    const dates = [
        ...tasks.flatMap((task) => [task.startOn, task.dueOn]),
        ...milestones.map((milestone) => milestone.dueOn),
        ...(project.startOn ? [project.startOn] : []),
        ...(project.dueOn ? [project.dueOn] : []),
        today,
    ];
    const first = Math.min(...dates.map(day)) - 1;
    const last = Math.max(...dates.map(day)) + 2;
    const span = last - first;
    const at = (date: string) => ((day(date) - first) / span) * 100;

    // Month starts along the top, for orientation.
    const months: { label: string; left: number }[] = [];
    const cursor = new Date((first + 1) * DAY);
    cursor.setUTCDate(1);
    while (cursor.getTime() / DAY < last) {
        const left = ((cursor.getTime() / DAY - first) / span) * 100;
        if (left >= 0) {
            months.push({
                label: cursor.toLocaleDateString([], {
                    month: 'short',
                    year: '2-digit',
                    timeZone: 'UTC',
                }),
                left,
            });
        }
        cursor.setUTCMonth(cursor.getUTCMonth() + 1);
    }

    const marker = (left: number, className: string) => (
        <div
            aria-hidden
            className={cn('absolute inset-y-0 w-px', className)}
            style={{ left: `${left}%` }}
        />
    );

    return (
        <ProjectFrame project={project} title="Timeline">
            <Panel>
                <PanelHeader
                    title="Timeline"
                    description={
                        undated > 0
                            ? `${undated} open task${undated === 1 ? ' has' : 's have'} no dates and isn't shown.`
                            : 'Tasks from start to due date.'
                    }
                />
                {tasks.length === 0 && milestones.length === 0 ? (
                    <EmptyState icon={CalendarRange} title="Nothing dated yet">
                        Give tasks start and due dates to see them here.
                    </EmptyState>
                ) : (
                    <div className="overflow-x-auto">
                        <div className="min-w-[720px]">
                            <div className="grid grid-cols-[16rem_1fr] border-b text-xs text-muted-foreground">
                                <div className="px-3 py-1.5">Task</div>
                                <div className="relative h-7">
                                    {months.map((month) => (
                                        <span
                                            key={month.left}
                                            className="absolute top-1.5 border-l pl-1"
                                            style={{ left: `${month.left}%` }}
                                        >
                                            {month.label}
                                        </span>
                                    ))}
                                </div>
                            </div>

                            {milestones.length > 0 && (
                                <div className="grid grid-cols-[16rem_1fr] border-b">
                                    <div className="px-3 py-2 text-xs font-medium text-muted-foreground">
                                        Milestones
                                    </div>
                                    <div className="relative h-9">
                                        {marker(at(today), 'bg-destructive/60')}
                                        {milestones.map((milestone) => (
                                            <span
                                                key={milestone.id}
                                                title={`${milestone.name} · ${formatDate(milestone.dueOn)}`}
                                                className="absolute top-2 flex -translate-x-1/2 items-center gap-1 text-xs whitespace-nowrap"
                                                style={{
                                                    left: `${at(milestone.dueOn)}%`,
                                                }}
                                            >
                                                <span
                                                    className={cn(
                                                        'size-2.5 rotate-45',
                                                        milestone.done
                                                            ? 'bg-emerald-500'
                                                            : 'bg-violet-600',
                                                    )}
                                                />
                                                {milestone.name}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {tasks.map((task) => {
                                const left = at(task.startOn);
                                const width = Math.max(
                                    at(task.dueOn) + (1 / span) * 100 - left,
                                    0.8,
                                );

                                return (
                                    <div
                                        key={task.id}
                                        className="grid grid-cols-[16rem_1fr] border-b last:border-b-0"
                                    >
                                        <div className="min-w-0 px-3 py-1.5 text-sm">
                                            <Link
                                                href={show({
                                                    project: project.id,
                                                    task: task.id,
                                                })}
                                                className="block truncate hover:underline"
                                            >
                                                <span className="text-muted-foreground">
                                                    {task.number}
                                                </span>{' '}
                                                {task.title}
                                            </Link>
                                            <p className="truncate text-xs text-muted-foreground">
                                                {task.assignee ?? 'Unassigned'}
                                                {task.waitsFor.length > 0 &&
                                                    ` · after ${task.waitsFor.join(', ')}`}
                                            </p>
                                        </div>
                                        <div className="relative">
                                            {marker(
                                                at(today),
                                                'bg-destructive/60',
                                            )}
                                            <div
                                                title={`${formatDay(task.startOn)} → ${formatDay(task.dueOn)}`}
                                                className={cn(
                                                    'absolute top-1/2 h-4 -translate-y-1/2 rounded-sm',
                                                    BAR[task.status] ??
                                                        'bg-slate-400',
                                                    task.overdue &&
                                                        'ring-2 ring-destructive',
                                                )}
                                                style={{
                                                    left: `${left}%`,
                                                    width: `${width}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}
                <div className="flex flex-wrap gap-3 border-t px-3 py-2 text-xs text-muted-foreground">
                    {Object.entries(BAR).map(([status, color]) => (
                        <span key={status} className="flex items-center gap-1">
                            <span
                                className={cn('size-2.5 rounded-sm', color)}
                            />
                            {status === 'todo'
                                ? 'To do'
                                : status === 'in_progress'
                                  ? 'In progress'
                                  : status === 'review'
                                    ? 'In review'
                                    : 'Done'}
                        </span>
                    ))}
                    <span className="flex items-center gap-1">
                        <span className="h-3 w-px bg-destructive/60" />
                        Today
                    </span>
                </div>
            </Panel>
        </ProjectFrame>
    );
}
