import { Form, Link, router } from '@inertiajs/react';
import { FileText, X } from 'lucide-react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Select } from '@/components/field';
import { Status } from '@/components/status';
import { formatDate, formatDateTime } from '@/lib/status';
import { show as showTask } from '@/routes/projects/tasks';
import {
    destroy as removeAttachment,
    show as showAttachment,
    store as storeAttachment,
} from '@/routes/projects/tasks/attachments';
import {
    destroy as removeItem,
    store as storeItem,
    toggle as toggleItem,
} from '@/routes/projects/tasks/checklist';
import {
    destroy as removeCollaborator,
    store as storeCollaborator,
} from '@/routes/projects/tasks/collaborators';
import { store as storeSubtask } from '@/routes/projects/tasks/subtasks';
import type { Option } from '@/types';

type TaskRoute = { project: string; task: string };

export type Subtask = {
    id: string;
    number: string;
    title: string;
    status: string;
    assignee: string | null;
    dueOn: string | null;
    overdue: boolean;
};

export type ChecklistItem = {
    id: string;
    body: string;
    done: boolean;
    doneBy: string | null;
};

export type Attachment = {
    id: string;
    name: string;
    size: number;
    uploadedBy: string;
    at: string;
    canRemove: boolean;
};

function fileSize(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/**
 * The smaller tasks a task is broken into. Each is a task of its own, with
 * its own page: this is where they are listed and added.
 */
export function SubtasksPanel({
    route,
    subtasks,
    people,
    canAdd,
}: {
    route: TaskRoute;
    subtasks: Subtask[];
    people: Option[];
    canAdd: boolean;
}) {
    const done = subtasks.filter((item) => item.status === 'done').length;

    return (
        <Panel>
            <PanelHeader
                title="Subtasks"
                description={
                    subtasks.length === 0
                        ? "Break it down: it can't be done until these are."
                        : `${done} of ${subtasks.length} done`
                }
            />
            {subtasks.length > 0 && (
                <ul className="divide-y">
                    {subtasks.map((item) => (
                        <li
                            key={item.id}
                            className="flex items-center gap-2 px-3 py-1.5 text-sm"
                        >
                            <Link
                                href={showTask({
                                    project: route.project,
                                    task: item.id,
                                })}
                                className="min-w-0 flex-1 truncate hover:underline"
                            >
                                <span className="text-muted-foreground">
                                    {item.number}
                                </span>{' '}
                                {item.title}
                            </Link>
                            {item.dueOn && (
                                <span
                                    className={
                                        item.overdue
                                            ? 'text-xs font-medium text-destructive'
                                            : 'text-xs text-muted-foreground'
                                    }
                                >
                                    {formatDate(item.dueOn)}
                                </span>
                            )}
                            <span className="max-w-32 truncate text-xs text-muted-foreground">
                                {item.assignee ?? 'Unassigned'}
                            </span>
                            <Status status={item.status} />
                        </li>
                    ))}
                </ul>
            )}
            {canAdd && (
                <PanelBody className="border-t">
                    <Form
                        {...storeSubtask.form(route)}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="grid gap-1"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="flex flex-wrap gap-2">
                                    <Input
                                        name="title"
                                        required
                                        maxLength={255}
                                        aria-label="Subtask title"
                                        placeholder="Add a subtask"
                                        className="min-w-40 flex-1"
                                    />
                                    <Select
                                        name="assignee_id"
                                        aria-label="Subtask assignee"
                                        defaultValue=""
                                        placeholder="Unassigned"
                                        options={people}
                                        className="w-40"
                                    />
                                    <Input
                                        name="due_on"
                                        type="date"
                                        aria-label="Subtask due date"
                                        className="w-40"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Add
                                    </Button>
                                </div>
                                {errors.title && (
                                    <p className="text-sm text-destructive">
                                        {errors.title}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </PanelBody>
            )}
        </Panel>
    );
}

/**
 * Small steps to tick off. Unlike subtasks, they don't hold the task back.
 */
export function ChecklistPanel({
    route,
    items,
    editable,
}: {
    route: TaskRoute;
    items: ChecklistItem[];
    editable: boolean;
}) {
    const done = items.filter((item) => item.done).length;

    return (
        <Panel>
            <PanelHeader
                title="Checklist"
                description={
                    items.length === 0
                        ? 'Small steps to tick off.'
                        : `${done} of ${items.length} done`
                }
            />
            {items.length > 0 && (
                <ul className="divide-y">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="flex items-center gap-2 px-3 py-1.5 text-sm"
                        >
                            <label className="flex min-w-0 flex-1 items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={item.done}
                                    disabled={!editable}
                                    onChange={() =>
                                        router.post(
                                            toggleItem.url({
                                                ...route,
                                                checklistItem: item.id,
                                            }),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                                <span
                                    className={
                                        item.done
                                            ? 'text-muted-foreground line-through'
                                            : undefined
                                    }
                                    title={
                                        item.doneBy
                                            ? `Ticked by ${item.doneBy}`
                                            : undefined
                                    }
                                >
                                    {item.body}
                                </span>
                            </label>
                            {editable && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Remove ${item.body}`}
                                    onClick={() =>
                                        router.delete(
                                            removeItem.url({
                                                ...route,
                                                checklistItem: item.id,
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
            )}
            {editable && (
                <PanelBody className="border-t">
                    <Form
                        {...storeItem.form(route)}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="grid gap-1"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="flex gap-2">
                                    <Input
                                        name="body"
                                        required
                                        maxLength={255}
                                        aria-label="Checklist item"
                                        placeholder="Add a step"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Add
                                    </Button>
                                </div>
                                {errors.body && (
                                    <p className="text-sm text-destructive">
                                        {errors.body}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </PanelBody>
            )}
        </Panel>
    );
}

/**
 * People on the task besides its lead assignee. It shows in My work for them too.
 */
export function CollaboratorsPanel({
    route,
    collaborators,
    candidates,
    editable,
}: {
    route: TaskRoute;
    collaborators: { id: string; name: string }[];
    candidates: Option[];
    editable: boolean;
}) {
    return (
        <Panel>
            <PanelHeader
                title="Also on this task"
                description="Besides the assignee, who leads it."
            />
            {collaborators.length === 0 ? (
                <p className="px-3 py-3 text-sm text-muted-foreground">
                    Nobody else.
                </p>
            ) : (
                <ul className="divide-y">
                    {collaborators.map((person) => (
                        <li
                            key={person.id}
                            className="flex items-center gap-2 px-3 py-1.5 text-sm"
                        >
                            <span className="flex-1">{person.name}</span>
                            {editable && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Take ${person.name} off this task`}
                                    onClick={() =>
                                        router.delete(
                                            removeCollaborator.url({
                                                ...route,
                                                collaborator: person.id,
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
            )}
            {editable && candidates.length > 0 && (
                <PanelBody className="border-t">
                    <Form
                        {...storeCollaborator.form(route)}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="grid gap-1"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="flex gap-2">
                                    <Select
                                        name="person_id"
                                        aria-label="Person to add"
                                        required
                                        defaultValue=""
                                        placeholder="Choose someone"
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
                                {errors.person_id && (
                                    <p className="text-sm text-destructive">
                                        {errors.person_id}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </PanelBody>
            )}
        </Panel>
    );
}

/**
 * Files on the task: anyone who can see the project can download them.
 */
export function AttachmentsPanel({
    route,
    attachments,
    editable,
}: {
    route: TaskRoute;
    attachments: Attachment[];
    editable: boolean;
}) {
    return (
        <Panel>
            <PanelHeader
                title="Files"
                description="PDF, images, Office documents, CSV or text, up to 10 MB."
            />
            {attachments.length === 0 ? (
                <p className="px-3 py-3 text-sm text-muted-foreground">
                    None yet.
                </p>
            ) : (
                <ul className="divide-y">
                    {attachments.map((file) => (
                        <li
                            key={file.id}
                            className="flex items-center gap-2 px-3 py-1.5 text-sm"
                        >
                            <FileText
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <div className="min-w-0 flex-1">
                                <a
                                    href={
                                        showAttachment({
                                            ...route,
                                            attachment: file.id,
                                        }).url
                                    }
                                    className="block truncate hover:underline"
                                >
                                    {file.name}
                                </a>
                                <p className="text-xs text-muted-foreground">
                                    {fileSize(file.size)} · {file.uploadedBy} ·{' '}
                                    {formatDateTime(file.at)}
                                </p>
                            </div>
                            {editable && file.canRemove && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Remove ${file.name}`}
                                    onClick={() =>
                                        router.delete(
                                            removeAttachment.url({
                                                ...route,
                                                attachment: file.id,
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
            )}
            {editable && (
                <PanelBody className="border-t">
                    <Form
                        {...storeAttachment.form(route)}
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="grid gap-1"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="flex gap-2">
                                    <Input
                                        name="file"
                                        type="file"
                                        required
                                        aria-label="File to attach"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Upload
                                    </Button>
                                </div>
                                {errors.file && (
                                    <p className="text-sm text-destructive">
                                        {errors.file}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </PanelBody>
            )}
        </Panel>
    );
}
