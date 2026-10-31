import { Form, Link, router } from '@inertiajs/react';
import { Pencil, X } from 'lucide-react';
import { useState } from 'react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Select } from '@/components/field';
import { ProjectFrame } from '@/components/project-frame';
import { Priority, Status } from '@/components/status';
import { TaskForm } from '@/components/task-form';
import { formatMoney } from '@/lib/money';
import {
    formatDate,
    formatDateTime,
    formatMinutes,
    statusLabel,
    TASK_STATUSES,
} from '@/lib/status';
import { board } from '@/routes/projects';
import { move, show } from '@/routes/projects/tasks';
import { store as comment } from '@/routes/projects/tasks/comments';
import {
    destroy as unlink,
    store as link,
} from '@/routes/projects/tasks/dependencies';
import type { Option, ProjectHeader, TaskCard, TaskChoices } from '@/types';

type Related = { id: string; number: string; title: string; status: string };

type Props = TaskChoices & {
    project: ProjectHeader;
    task: TaskCard & {
        description: string | null;
        assigneeId: string | null;
        milestoneId: string | null;
        createdBy: string;
        createdAt: string;
        completedAt: string | null;
        dependencies: Related[];
        dependents: Related[];
        comments: { id: string; body: string; author: string; at: string }[];
        time: {
            id: string;
            person: string;
            workedOn: string;
            minutes: number;
            note: string | null;
            costMinor: number | null;
        }[];
        loggedMinutes: number;
    };
    candidates: Option[];
};

/**
 * One task: its details, what it waits for, the discussion and the time on it.
 */
export default function TaskPage({
    project,
    task,
    candidates,
    people,
    milestones,
    priorities,
}: Props) {
    const editable = project.can.tasks && project.open;
    const [editing, setEditing] = useState(false);
    const route = { project: project.id, task: task.id };

    const relatedList = (items: Related[], removable: boolean) => (
        <ul className="divide-y">
            {items.map((item) => (
                <li
                    key={item.id}
                    className="flex items-center gap-2 px-3 py-1.5 text-sm"
                >
                    <Link
                        href={show({ project: project.id, task: item.id })}
                        className="min-w-0 flex-1 truncate hover:underline"
                    >
                        <span className="text-muted-foreground">
                            {item.number}
                        </span>{' '}
                        {item.title}
                    </Link>
                    <Status status={item.status} />
                    {removable && (
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label={`Stop waiting for ${item.number}`}
                            onClick={() =>
                                router.delete(
                                    unlink.url({
                                        ...route,
                                        dependency: item.id,
                                    }),
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <X className="size-4" />
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );

    return (
        <ProjectFrame
            project={project}
            title={task.number}
            actions={
                <Button variant="outline" size="sm" asChild>
                    <Link href={board(project.id)}>← Board</Link>
                </Button>
            }
        >
            <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                <div className="grid content-start gap-4">
                    <Panel>
                        <PanelHeader
                            title={
                                <>
                                    <span className="text-muted-foreground">
                                        {task.number}
                                    </span>{' '}
                                    {task.title}
                                </>
                            }
                            description={`Added by ${task.createdBy}, ${formatDateTime(task.createdAt)}`}
                            actions={
                                editable &&
                                !editing && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditing(true)}
                                    >
                                        <Pencil
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Edit
                                    </Button>
                                )
                            }
                        />
                        <PanelBody>
                            {editing ? (
                                <TaskForm
                                    projectId={project.id}
                                    task={task}
                                    choices={{ people, milestones, priorities }}
                                    onDone={() => setEditing(false)}
                                />
                            ) : (
                                <>
                                    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Status
                                            </dt>
                                            <dd className="mt-0.5">
                                                {editable ? (
                                                    <Select
                                                        aria-label="Status"
                                                        value={task.status}
                                                        onChange={(event) =>
                                                            router.post(
                                                                move.url(route),
                                                                {
                                                                    status: event
                                                                        .target
                                                                        .value,
                                                                },
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                        options={TASK_STATUSES.map(
                                                            (value) => ({
                                                                value,
                                                                label: statusLabel(
                                                                    value,
                                                                ),
                                                            }),
                                                        )}
                                                        className="h-7 w-32 text-xs"
                                                    />
                                                ) : (
                                                    <Status
                                                        status={task.status}
                                                    />
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Priority
                                            </dt>
                                            <dd className="mt-0.5">
                                                <Priority
                                                    priority={task.priority}
                                                />
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Assignee
                                            </dt>
                                            <dd>
                                                {task.assignee ?? 'Unassigned'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Dates
                                            </dt>
                                            <dd
                                                className={
                                                    task.overdue
                                                        ? 'font-medium text-destructive'
                                                        : undefined
                                                }
                                            >
                                                {task.startOn
                                                    ? formatDate(task.startOn)
                                                    : '…'}{' '}
                                                →{' '}
                                                {task.dueOn
                                                    ? formatDate(task.dueOn)
                                                    : '…'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Estimate / logged
                                            </dt>
                                            <dd className="tabular-nums">
                                                {task.estimateHours !== null
                                                    ? `${task.estimateHours} h`
                                                    : '—'}{' '}
                                                /{' '}
                                                {formatMinutes(
                                                    task.loggedMinutes,
                                                )}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-muted-foreground">
                                                Milestone
                                            </dt>
                                            <dd>{task.milestone ?? '—'}</dd>
                                        </div>
                                    </dl>
                                    {task.description && (
                                        <p className="mt-4 text-sm whitespace-pre-line">
                                            {task.description}
                                        </p>
                                    )}
                                    {task.completedAt && (
                                        <p className="mt-4 text-xs text-muted-foreground">
                                            Done{' '}
                                            {formatDateTime(task.completedAt)}
                                        </p>
                                    )}
                                </>
                            )}
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title="Discussion"
                            description={`${task.comments.length} comment${task.comments.length === 1 ? '' : 's'}`}
                        />
                        <ul className="divide-y">
                            {task.comments.map((item) => (
                                <li key={item.id} className="px-3 py-2 text-sm">
                                    <p className="text-xs text-muted-foreground">
                                        <span className="font-medium text-foreground">
                                            {item.author}
                                        </span>{' '}
                                        · {formatDateTime(item.at)}
                                    </p>
                                    <p className="mt-1 whitespace-pre-line">
                                        {item.body}
                                    </p>
                                </li>
                            ))}
                        </ul>
                        {project.can.tasks && (
                            <PanelBody className="border-t">
                                <Form
                                    {...comment.form(route)}
                                    resetOnSuccess
                                    options={{ preserveScroll: true }}
                                    className="grid gap-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <textarea
                                                name="body"
                                                rows={3}
                                                required
                                                aria-label="Comment"
                                                placeholder="Add a comment"
                                                className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                                            />
                                            {errors.body && (
                                                <p className="text-sm text-destructive">
                                                    {errors.body}
                                                </p>
                                            )}
                                            <div>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Comment
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        )}
                    </Panel>
                </div>

                <div className="grid content-start gap-4">
                    <Panel>
                        <PanelHeader
                            title="Waits for"
                            description="It can't be done until these are."
                        />
                        {task.dependencies.length === 0 ? (
                            <p className="px-3 py-3 text-sm text-muted-foreground">
                                Nothing.
                            </p>
                        ) : (
                            relatedList(task.dependencies, editable)
                        )}
                        {editable && candidates.length > 0 && (
                            <PanelBody className="border-t">
                                <Form
                                    {...link.form(route)}
                                    options={{ preserveScroll: true }}
                                    className="flex gap-2"
                                >
                                    {({ errors, processing }) => (
                                        <div className="grid flex-1 gap-1">
                                            <div className="flex gap-2">
                                                <Select
                                                    name="depends_on_id"
                                                    aria-label="Task to wait for"
                                                    required
                                                    defaultValue=""
                                                    placeholder="Choose a task"
                                                    options={candidates}
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    Add
                                                </Button>
                                            </div>
                                            {errors.depends_on_id && (
                                                <p className="text-sm text-destructive">
                                                    {errors.depends_on_id}
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </Form>
                            </PanelBody>
                        )}
                    </Panel>

                    {task.dependents.length > 0 && (
                        <Panel>
                            <PanelHeader title="Waiting on this" />
                            {relatedList(task.dependents, false)}
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader
                            title="Time"
                            description={`${formatMinutes(task.loggedMinutes)} logged`}
                        />
                        {task.time.length === 0 ? (
                            <p className="px-3 py-3 text-sm text-muted-foreground">
                                None yet: log it from the Time tab or My work.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {task.time.map((entry) => (
                                    <li
                                        key={entry.id}
                                        className="flex items-center gap-2 px-3 py-1.5 text-sm"
                                    >
                                        <span className="flex-1">
                                            {entry.person}
                                            <span className="text-xs text-muted-foreground">
                                                {' '}
                                                · {formatDate(entry.workedOn)}
                                            </span>
                                        </span>
                                        <span className="tabular-nums">
                                            {formatMinutes(entry.minutes)}
                                        </span>
                                        {entry.costMinor !== null && (
                                            <span className="w-24 text-right text-xs text-muted-foreground tabular-nums">
                                                {formatMoney(
                                                    entry.costMinor,
                                                    project.currency,
                                                )}
                                            </span>
                                        )}
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
