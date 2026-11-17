import { Form, Link } from '@inertiajs/react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Field, Select } from '@/components/field';
import type { AttachmentRow, WorkFrame } from '@/components/partner-work';
import {
    Attachments,
    Facts,
    TextArea,
    WorkPage,
} from '@/components/partner-work';
import { Priority, Status } from '@/components/status';
import { formatDate } from '@/lib/status';
import type { Option } from '@/types';
import type { SharedTaskRow } from './tasks';

type Comment = {
    id: string;
    body: string;
    by: string;
    for: string;
    at: string | null;
};

/**
 * One shared task: what it asks, who works on it, the conversation and the
 * files, and moving it along (a partner) or reviewing it (the owner).
 */
export default function SharedTask({
    frame,
    task,
    comments,
    attachments,
    canWork,
    canReview,
    statuses,
    urls,
}: {
    frame: WorkFrame;
    task: Omit<SharedTaskRow, 'url'> & {
        description: string | null;
        startOn: string | null;
    };
    comments: Comment[];
    attachments: (AttachmentRow & { for: string })[];
    canWork: boolean;
    canReview: boolean;
    statuses: Option[];
    urls: {
        comment: string;
        attach: string;
        progress: string | null;
        review: string | null;
        index: string;
        ownerTask: string | null;
    };
}) {
    return (
        <WorkPage
            frame={frame}
            title={`${task.number} · ${task.title}`}
            actions={
                <div className="flex gap-2">
                    {urls.ownerTask && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={urls.ownerTask}>Open in the board</Link>
                        </Button>
                    )}
                    <Button variant="outline" size="sm" asChild>
                        <Link href={urls.index}>← Shared tasks</Link>
                    </Button>
                </div>
            }
        >
            <Panel>
                <PanelBody className="grid gap-4">
                    <Facts
                        items={[
                            ['Status', <Status key="s" status={task.status} />],
                            [
                                'Priority',
                                <Priority key="p" priority={task.priority} />,
                            ],
                            [
                                'Who works on it',
                                task.givenTo ??
                                    (task.shared ? 'Shared with all' : '—'),
                            ],
                            [
                                'Due',
                                task.dueOn
                                    ? `${formatDate(task.dueOn)}${task.overdue ? ' · overdue' : ''}`
                                    : '—',
                            ],
                            ['Milestone', task.milestone ?? '—'],
                        ]}
                    />
                    {task.description && (
                        <p className="text-sm whitespace-pre-line">
                            {task.description}
                        </p>
                    )}
                </PanelBody>
            </Panel>

            {urls.progress && canWork && (
                <Panel>
                    <PanelHeader
                        title="Progress"
                        description="Send it for review when it is done on your side: the project owner accepts it as done, or returns it with a note."
                    />
                    <PanelBody>
                        <Form
                            action={urls.progress}
                            method="post"
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="status"
                                        label="Move to"
                                        error={errors.status}
                                    >
                                        <Select
                                            id="status"
                                            name="status"
                                            defaultValue={
                                                task.status === 'done'
                                                    ? 'review'
                                                    : task.status
                                            }
                                            options={statuses}
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Update
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {canReview && urls.review && (
                <Panel>
                    <PanelHeader
                        title="Waiting for your review"
                        description={`${task.givenTo} says it is done. Accept it, or return it with what is missing.`}
                    />
                    <PanelBody>
                        <Form
                            action={urls.review}
                            method="post"
                            options={{ preserveScroll: true }}
                            className="grid gap-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="note"
                                        label="Note"
                                        error={errors.note ?? errors.review}
                                        hint="Needed to return it."
                                    >
                                        <TextArea
                                            id="note"
                                            name="note"
                                            rows={3}
                                        />
                                    </Field>
                                    <div className="flex gap-2">
                                        <Button
                                            type="submit"
                                            name="decision"
                                            value="accept"
                                            disabled={processing}
                                        >
                                            Accept as done
                                        </Button>
                                        <Button
                                            type="submit"
                                            name="decision"
                                            value="return"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Return
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                <PanelHeader
                    title="Comments"
                    description="Everyone who sees this task reads them."
                />
                <PanelBody className="grid gap-3">
                    {comments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No comments yet.
                        </p>
                    ) : (
                        <ol className="grid gap-3">
                            {comments.map((comment) => (
                                <li key={comment.id} className="text-sm">
                                    <div className="text-xs text-muted-foreground">
                                        {comment.by} · {comment.for}
                                        {comment.at &&
                                            ` · ${formatDate(comment.at)}`}
                                    </div>
                                    <p className="whitespace-pre-line">
                                        {comment.body}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    )}
                    {canWork && (
                        <Form
                            action={urls.comment}
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="grid gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="body"
                                        label="Add a comment"
                                        error={errors.body}
                                    >
                                        <TextArea
                                            id="body"
                                            name="body"
                                            rows={3}
                                            required
                                        />
                                    </Field>
                                    <div>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Comment
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </PanelBody>
            </Panel>

            <Attachments
                files={attachments.map((file) => ({
                    ...file,
                    by: `${file.by} · ${file.for}`,
                }))}
                uploadUrl={canWork ? urls.attach : null}
            />
        </WorkPage>
    );
}
