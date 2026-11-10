import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { ListStatusesPanel } from '@/components/list-statuses';
import type { ListStatus } from '@/components/list-statuses';
import { Priority } from '@/components/status';
import { TaskForm } from '@/components/task-form';
import { formatDate } from '@/lib/status';
import { cn } from '@miles/ui/lib/utils';
import { index as spaces } from '@/routes/structure';
import { board } from '@/routes/projects';
import { show as showTask } from '@/routes/projects/tasks';
import { show as showWorkTask } from '@/routes/work/tasks';
import type { TaskChoices } from '@/types';

type Row = {
    id: string;
    number: string;
    title: string;
    priority: string;
    assignee: string | null;
    dueOn: string | null;
    overdue: boolean;
};

type Props = TaskChoices & {
    list: {
        id: string;
        name: string;
        archived: boolean;
        path: string[];
        project: { id: string; code: string; name: string } | null;
    };
    columns: { key: string; status: string; label: string; tasks: Row[] }[];
    statuses: ListStatus[];
    canManageStatuses: boolean;
    canAdd: boolean;
};

/**
 * One list's tasks, by status. A task added here goes in this list.
 */
export default function ListPage({
    list,
    columns,
    statuses,
    canManageStatuses,
    canAdd,
    people,
    milestones,
    priorities,
}: Props) {
    const total = columns.reduce((sum, column) => sum + column.tasks.length, 0);

    return (
        <>
            <Head title={list.name} />
            <PageHeader
                title={list.name}
                description={
                    <>
                        <Link href={spaces()} className="hover:underline">
                            {list.path.join(' / ')}
                        </Link>{' '}
                        {list.project &&
                            ` · ${list.project.code} · ${list.project.name}`}
                        {list.archived && ' · archived'}
                    </>
                }
                actions={
                    list.project && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={board({ project: list.project.id })}>
                                {list.project.code} board
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="grid gap-4">
                {canAdd && (
                    <Panel>
                        <PanelHeader title="Add a task" />
                        <PanelBody>
                            <TaskForm
                                projectId={list.project?.id}
                                listId={list.id}
                                choices={{ people, milestones, priorities }}
                            />
                        </PanelBody>
                    </Panel>
                )}

                {canManageStatuses && (
                    <ListStatusesPanel listId={list.id} statuses={statuses} />
                )}

                {total === 0 ? (
                    <Panel>
                        <PanelBody>
                            <p className="text-sm text-muted-foreground">
                                No tasks in this list yet.
                            </p>
                        </PanelBody>
                    </Panel>
                ) : (
                    columns
                        .filter((column) => column.tasks.length > 0)
                        .map((column) => (
                            <Panel key={column.key}>
                                <PanelHeader
                                    title={`${column.label} (${column.tasks.length})`}
                                />
                                <ul className="divide-y">
                                    {column.tasks.map((task) => (
                                        <li
                                            key={task.id}
                                            className="flex flex-wrap items-center gap-2 px-3 py-1.5 text-sm"
                                        >
                                            <Link
                                                href={
                                                    list.project
                                                        ? showTask({
                                                              project:
                                                                  list.project
                                                                      .id,
                                                              task: task.id,
                                                          })
                                                        : showWorkTask(task.id)
                                                }
                                                className="min-w-0 flex-1 truncate hover:underline"
                                            >
                                                <span className="text-muted-foreground">
                                                    {task.number}
                                                </span>{' '}
                                                {task.title}
                                            </Link>
                                            <Priority
                                                priority={task.priority}
                                            />
                                            <span className="w-32 truncate text-xs text-muted-foreground">
                                                {task.assignee ?? 'Nobody yet'}
                                            </span>
                                            <span
                                                className={cn(
                                                    'w-24 text-right text-xs',
                                                    task.overdue
                                                        ? 'text-destructive'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {task.dueOn
                                                    ? formatDate(task.dueOn)
                                                    : ''}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </Panel>
                        ))
                )}
            </div>
        </>
    );
}
