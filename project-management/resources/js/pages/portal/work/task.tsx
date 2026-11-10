import { Form, Head, Link } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { Priority } from '@/components/status';
import { TaskForm } from '@/components/task-form';
import { formatDate, formatDateTime } from '@/lib/status';
import { show as showList } from '@/routes/lists';
import { move, show } from '@/routes/work/tasks';
import {
    destroy as removeItem,
    store as addItem,
    toggle as toggleItem,
} from '@/routes/work/tasks/checklist';
import { store as addComment } from '@/routes/work/tasks/comments';
import { store as addSubtask } from '@/routes/work/tasks/subtasks';
import type { Option, TaskChoices } from '@/types';

type Props = TaskChoices & {
    task: {
        id: string;
        number: string;
        title: string;
        description: string | null;
        status: string;
        statusLabel: string;
        statusValue: string;
        priority: string;
        assignee: string | null;
        assigneeId: string | null;
        milestoneId: string | null;
        startOn: string | null;
        dueOn: string | null;
        overdue: boolean;
        estimateHours: number | null;
        list: { id: string; name: string };
        parent: { id: string; number: string; title: string } | null;
        createdBy: string;
        subtasks: {
            id: string;
            number: string;
            title: string;
            status: string;
            assignee: string | null;
        }[];
        checklist: {
            id: string;
            body: string;
            done: boolean;
            doneBy: string | null;
        }[];
        comments: { id: string; body: string; author: string; at: string }[];
    };
    statusOptions: Option[];
    lists: Option[];
    mentionable: string[];
    canManage: boolean;
};

/**
 * A task that belongs to no project: its status, details, subtasks,
 * checklist and comments.
 */
export default function WorkTask({
    task,
    statusOptions,
    lists,
    mentionable,
    canManage,
    people,
    milestones,
    priorities,
}: Props) {
    const [editing, setEditing] = useState(false);

    return (
        <>
            <Head title={task.number} />
            <PageHeader
                title={`${task.number} · ${task.title}`}
                description={
                    <>
                        <Link
                            href={showList(task.list.id)}
                            className="hover:underline"
                        >
                            {task.list.name}
                        </Link>
                        {task.parent && (
                            <>
                                {' · under '}
                                <Link
                                    href={show(task.parent.id)}
                                    className="hover:underline"
                                >
                                    {task.parent.number}
                                </Link>
                            </>
                        )}
                        {` · added by ${task.createdBy}`}
                    </>
                }
                actions={
                    canManage && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setEditing((value) => !value)}
                        >
                            {editing ? 'Close' : 'Edit'}
                        </Button>
                    )
                }
            />

            <div className="grid gap-4 lg:grid-cols-3">
                <div className="grid content-start gap-4 lg:col-span-2">
                    {editing ? (
                        <Panel>
                            <PanelHeader title="Edit task" />
                            <PanelBody>
                                <TaskForm
                                    task={task}
                                    listId={task.list.id}
                                    lists={lists}
                                    choices={{ people, milestones, priorities }}
                                    onDone={() => setEditing(false)}
                                />
                            </PanelBody>
                        </Panel>
                    ) : (
                        <Panel>
                            <PanelHeader title="Description" />
                            <PanelBody>
                                <p className="text-sm whitespace-pre-wrap">
                                    {task.description ?? 'No description.'}
                                </p>
                            </PanelBody>
                        </Panel>
                    )}

                    {!task.parent && (
                        <Panel>
                            <PanelHeader title="Subtasks" />
                            {task.subtasks.length > 0 && (
                                <ul className="divide-y text-sm">
                                    {task.subtasks.map((subtask) => (
                                        <li
                                            key={subtask.id}
                                            className="flex flex-wrap items-center gap-2 px-3 py-1.5"
                                        >
                                            <Link
                                                href={show(subtask.id)}
                                                className="min-w-0 flex-1 truncate hover:underline"
                                            >
                                                <span className="text-muted-foreground">
                                                    {subtask.number}
                                                </span>{' '}
                                                {subtask.title}
                                            </Link>
                                            <span className="text-xs text-muted-foreground">
                                                {subtask.status}
                                                {subtask.assignee &&
                                                    ` · ${subtask.assignee}`}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {canManage && (
                                <PanelBody>
                                    <Form
                                        {...addSubtask.form(task.id)}
                                        resetOnSuccess
                                        options={{ preserveScroll: true }}
                                        className="flex items-end gap-2"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <Field
                                                    id="subtask-title"
                                                    label="Add a subtask"
                                                    error={errors.title}
                                                    className="flex-1"
                                                >
                                                    <Input
                                                        id="subtask-title"
                                                        name="title"
                                                        required
                                                        maxLength={255}
                                                    />
                                                </Field>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    Add
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </PanelBody>
                            )}
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader title="Checklist" />
                        {task.checklist.length > 0 && (
                            <ul className="divide-y text-sm">
                                {task.checklist.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-center gap-2 px-3 py-1.5"
                                    >
                                        {canManage ? (
                                            <Form
                                                {...toggleItem.form({
                                                    task: task.id,
                                                    checklistItem: item.id,
                                                })}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="min-w-0 flex-1"
                                            >
                                                <button
                                                    type="submit"
                                                    aria-pressed={item.done}
                                                    className={
                                                        item.done
                                                            ? 'text-left text-muted-foreground line-through'
                                                            : 'text-left'
                                                    }
                                                >
                                                    {item.done ? '☑' : '☐'}{' '}
                                                    {item.body}
                                                </button>
                                            </Form>
                                        ) : (
                                            <span className="min-w-0 flex-1">
                                                {item.done ? '☑' : '☐'}{' '}
                                                {item.body}
                                            </span>
                                        )}
                                        {item.doneBy && (
                                            <span className="text-xs text-muted-foreground">
                                                {item.doneBy}
                                            </span>
                                        )}
                                        {canManage && (
                                            <Form
                                                {...removeItem.form({
                                                    task: task.id,
                                                    checklistItem: item.id,
                                                })}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={`Remove ${item.body}`}
                                                >
                                                    <Trash2
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                </Button>
                                            </Form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {canManage && (
                            <PanelBody>
                                <Form
                                    {...addItem.form(task.id)}
                                    resetOnSuccess
                                    options={{ preserveScroll: true }}
                                    className="flex items-end gap-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="checklist-body"
                                                label="Add an item"
                                                error={errors.body}
                                                className="flex-1"
                                            >
                                                <Input
                                                    id="checklist-body"
                                                    name="body"
                                                    required
                                                    maxLength={255}
                                                />
                                            </Field>
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Add
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        )}
                    </Panel>

                    <Panel>
                        <PanelHeader title="Comments" />
                        {task.comments.length > 0 && (
                            <ul className="divide-y text-sm">
                                {task.comments.map((comment) => (
                                    <li key={comment.id} className="px-3 py-2">
                                        <p className="text-xs text-muted-foreground">
                                            {comment.author} ·{' '}
                                            {formatDateTime(comment.at)}
                                        </p>
                                        <p className="whitespace-pre-wrap">
                                            {comment.body}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {canManage && (
                            <PanelBody>
                                <Form
                                    {...addComment.form(task.id)}
                                    resetOnSuccess
                                    options={{ preserveScroll: true }}
                                    className="grid gap-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="comment-body"
                                                label="Add a comment"
                                                error={errors.body}
                                                hint={
                                                    mentionable.length > 0
                                                        ? `Mention someone with @ and their name, such as @${mentionable[0]}.`
                                                        : undefined
                                                }
                                            >
                                                <textarea
                                                    id="comment-body"
                                                    name="body"
                                                    rows={3}
                                                    required
                                                    maxLength={5000}
                                                    className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                                                />
                                            </Field>
                                            <div>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
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

                <Panel className="self-start">
                    <PanelHeader title="Details" />
                    <PanelBody>
                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">Status</dt>
                            <dd>{task.statusLabel}</dd>
                            <dt className="text-muted-foreground">Priority</dt>
                            <dd>
                                <Priority priority={task.priority} />
                            </dd>
                            <dt className="text-muted-foreground">Assignee</dt>
                            <dd>{task.assignee ?? 'Nobody yet'}</dd>
                            <dt className="text-muted-foreground">Start</dt>
                            <dd>
                                {task.startOn ? formatDate(task.startOn) : '—'}
                            </dd>
                            <dt className="text-muted-foreground">Due</dt>
                            <dd
                                className={
                                    task.overdue
                                        ? 'text-destructive'
                                        : undefined
                                }
                            >
                                {task.dueOn ? formatDate(task.dueOn) : '—'}
                            </dd>
                        </dl>
                        {canManage && (
                            <Form
                                {...move.form(task.id)}
                                options={{ preserveScroll: true }}
                                className="mt-4 flex items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="move-to"
                                            label="Move to"
                                            error={errors.to ?? errors.status}
                                            className="flex-1"
                                        >
                                            <Select
                                                key={task.statusValue}
                                                id="move-to"
                                                name="to"
                                                defaultValue={task.statusValue}
                                                options={statusOptions}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Move
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            </div>
        </>
    );
}
