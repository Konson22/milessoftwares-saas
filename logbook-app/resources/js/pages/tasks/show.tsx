import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { ActionButton } from '@/components/actions';
import {
    Detail,
    Errors,
    SelectField,
    Status,
    TextArea,
    TextField,
} from '@/components/fields';
import { Due, History, TaskForm, Toggle, WorkFiles } from '@/components/work';
import type { Option } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';
import type { HistoryEvent, TaskDetail, TaskOptions } from '@/lib/work';
import { reminderLabel } from '@/lib/work';
import { show as entry } from '@/routes/entries';
import { show as exception } from '@/routes/exceptions';
import {
    assign,
    cancel,
    complete,
    index,
    reopen,
    start,
    verify,
} from '@/routes/tasks';
import * as files from '@/routes/tasks/files';

type Props = {
    task: TaskDetail;
    history: HistoryEvent[];
    options: TaskOptions | null;
    myEntries: Option[];
};

export default function TaskPage({ task, history, options, myEntries }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const rev = { revision: task.revision };

    return (
        <>
            <Head title={task.reference} />
            <PageHeader
                title={`${task.reference} · ${task.title}`}
                description={
                    <Link href={index()} className="hover:underline">
                        Back to tasks
                    </Link>
                }
                actions={
                    <div className="flex items-center gap-2">
                        <Status
                            value={task.overdue ? 'overdue' : task.status}
                        />
                        {task.priority !== 'normal' && (
                            <Status value={task.priority} />
                        )}
                    </div>
                }
            />
            <div className="mb-4">
                <Errors
                    errors={Object.fromEntries(
                        Object.entries(errors).filter(([k]) =>
                            [
                                'task',
                                'stale',
                                'decision',
                                'assignee_id',
                            ].includes(k),
                        ),
                    )}
                />
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid gap-6 lg:col-span-2">
                    <Panel>
                        <PanelHeader title="The task" />
                        <PanelBody className="grid gap-4">
                            {task.description && (
                                <p className="text-sm whitespace-pre-line">
                                    {task.description}
                                </p>
                            )}
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <Detail label="Assigned to">
                                    {task.assignee.name}
                                </Detail>
                                <Detail label="Given by">{task.creator}</Detail>
                                <Detail label="Starts">
                                    {task.startsAt
                                        ? formatDateTime(task.startsAt)
                                        : null}
                                </Detail>
                                <Detail label="Due">
                                    <Due
                                        at={task.dueAt}
                                        overdue={task.overdue}
                                    />
                                </Detail>
                                <Detail label="Logbook">{task.logbook}</Detail>
                                <Detail label="Log sheet">
                                    {task.sheet
                                        ? `${task.sheet.code} · ${task.sheet.name}`
                                        : null}
                                </Detail>
                                <Detail label="Resource">
                                    {task.resource}
                                </Detail>
                                <Detail label="About entry">
                                    {task.entry?.id ? (
                                        <Link
                                            href={entry(task.entry.id)}
                                            className="hover:underline"
                                        >
                                            {task.entry.reference}
                                        </Link>
                                    ) : (
                                        task.entry?.reference
                                    )}
                                </Detail>
                                <Detail label="Corrective action for">
                                    {task.exception && (
                                        <Link
                                            href={exception(task.exception.id)}
                                            className="hover:underline"
                                        >
                                            {task.exception.reference} ·{' '}
                                            {task.exception.title}
                                        </Link>
                                    )}
                                </Detail>
                                <Detail label="Reminders">
                                    {task.reminders.length > 0
                                        ? task.reminders
                                              .map(reminderLabel)
                                              .join(', ')
                                        : null}
                                </Detail>
                                <Detail label="Checks">
                                    {[
                                        task.verificationRequired &&
                                            'verified by someone else',
                                        task.evidenceRequired &&
                                            'evidence required',
                                    ]
                                        .filter(Boolean)
                                        .join(', ') || null}
                                </Detail>
                            </dl>
                            <WorkFiles
                                files={task.files}
                                kind="brief"
                                label="Briefing files"
                                uploadUrl={files.store(task.id).url}
                                removeUrl={(id) => files.destroy(id).url}
                                canAdd={task.can.edit}
                                canRemove={task.can.edit}
                            />
                        </PanelBody>
                    </Panel>

                    {(task.can.work || task.completion.by) && (
                        <Panel>
                            <PanelHeader title="The work" />
                            <PanelBody className="grid gap-4">
                                {task.completion.returnComment &&
                                    task.status === 'returned' && (
                                        <p className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                            Returned:{' '}
                                            {task.completion.returnComment}
                                        </p>
                                    )}
                                {task.completion.by && (
                                    <dl className="grid gap-3 sm:grid-cols-2">
                                        <Detail label="Done by">
                                            {task.completion.by}
                                        </Detail>
                                        <Detail label="Completed">
                                            {task.completedAt
                                                ? formatDateTime(
                                                      task.completedAt,
                                                  )
                                                : null}
                                        </Detail>
                                        {task.completionReceivedAt &&
                                            task.completedAt &&
                                            receivedLater(
                                                task.completedAt,
                                                task.completionReceivedAt,
                                            ) && (
                                                <Detail label="Received">
                                                    {formatDateTime(
                                                        task.completionReceivedAt,
                                                    )}{' '}
                                                    (done offline on the phone,
                                                    sent later)
                                                </Detail>
                                            )}
                                        <Detail label="Notes">
                                            {task.completion.notes}
                                        </Detail>
                                        <Detail label="Entry">
                                            {task.completion.entry && (
                                                <Link
                                                    href={entry(
                                                        task.completion.entry
                                                            .id!,
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    {
                                                        task.completion.entry
                                                            .reference
                                                    }
                                                </Link>
                                            )}
                                        </Detail>
                                        {task.completion.verifiedBy && (
                                            <Detail label="Verified by">
                                                {task.completion.verifiedBy} ·{' '}
                                                {formatDateTime(
                                                    task.completion.verifiedAt,
                                                )}
                                            </Detail>
                                        )}
                                    </dl>
                                )}
                                <WorkFiles
                                    files={task.files}
                                    kind="evidence"
                                    label="Evidence"
                                    uploadUrl={files.store(task.id).url}
                                    removeUrl={(id) => files.destroy(id).url}
                                    canAdd={task.can.work}
                                    canRemove={task.can.work}
                                />
                                {task.can.start && (
                                    <div>
                                        <ActionButton
                                            url={start(task.id).url}
                                            label="Start"
                                            variant="outline"
                                            data={rev}
                                        />
                                    </div>
                                )}
                                {task.can.complete && (
                                    <CompleteForm
                                        task={task}
                                        entries={myEntries}
                                    />
                                )}
                            </PanelBody>
                        </Panel>
                    )}

                    {task.cancelled && (
                        <Panel>
                            <PanelBody className="text-sm">
                                Cancelled by {task.cancelled.by} on{' '}
                                {formatDateTime(task.cancelled.at)}:{' '}
                                {task.cancelled.reason}
                            </PanelBody>
                        </Panel>
                    )}
                </div>

                <div className="grid content-start gap-6">
                    {task.can.manage && (
                        <Panel>
                            <PanelHeader title="Follow up" />
                            <PanelBody className="grid gap-3">
                                {task.can.verify && <VerifyForm task={task} />}
                                {task.can.reassign && options && (
                                    <ReassignForm
                                        task={task}
                                        members={options.members}
                                    />
                                )}
                                {task.can.edit && options && (
                                    <Toggle
                                        label="Change the task"
                                        variant="outline"
                                    >
                                        {(close) => (
                                            <TaskForm
                                                options={options}
                                                onDone={close}
                                                initial={{
                                                    id: task.id,
                                                    revision: task.revision,
                                                    title: task.title,
                                                    description:
                                                        task.description,
                                                    priority: task.priority,
                                                    starts_at: task.startsAt,
                                                    due_at: task.dueAt,
                                                    reminders: task.reminders,
                                                    reminder_channels:
                                                        task.reminderChannels,
                                                    verification_required:
                                                        task.verificationRequired,
                                                    evidence_required:
                                                        task.evidenceRequired,
                                                }}
                                            />
                                        )}
                                    </Toggle>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {task.can.cancel && (
                                        <ActionButton
                                            url={cancel(task.id).url}
                                            label="Cancel task"
                                            reason="Why is it cancelled?"
                                            variant="destructive"
                                            data={rev}
                                        />
                                    )}
                                    {task.can.reopen && (
                                        <ActionButton
                                            url={reopen(task.id).url}
                                            label="Reopen"
                                            reason="Why reopen it? (optional)"
                                            reasonOptional
                                            variant="outline"
                                            data={rev}
                                        />
                                    )}
                                </div>
                            </PanelBody>
                        </Panel>
                    )}
                    <Panel>
                        <PanelHeader title="History" />
                        <History events={history} />
                    </Panel>
                </div>
            </div>
        </>
    );
}

function CompleteForm({
    task,
    entries,
}: {
    task: TaskDetail;
    entries: Option[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [notes, setNotes] = useState('');
    const [entryId, setEntryId] = useState('');
    const [at, setAt] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <form
            className="grid gap-3 rounded-md border p-3"
            onSubmit={(e) => {
                e.preventDefault();
                setBusy(true);
                router.post(
                    complete(task.id).url,
                    {
                        notes: notes || null,
                        completion_entry_id: entryId || null,
                        completed_at: at || null,
                        revision: task.revision,
                    },
                    { preserveScroll: true, onFinish: () => setBusy(false) },
                );
            }}
        >
            <TextArea
                label="What was done"
                value={notes}
                onChange={setNotes}
                error={errors.notes}
                rows={2}
            />
            <div className="grid gap-3 sm:grid-cols-2">
                <SelectField
                    label="Entry recording it (optional)"
                    value={entryId}
                    onChange={setEntryId}
                    options={entries}
                    empty="None"
                    error={errors.completion_entry_id}
                />
                <TextField
                    label="Done at (if not now)"
                    type="datetime-local"
                    value={at}
                    onChange={setAt}
                    error={errors.completed_at}
                />
            </div>
            {errors.evidence && (
                <p className="text-xs text-destructive">{errors.evidence}</p>
            )}
            <div>
                <Button type="submit" disabled={busy}>
                    {task.verificationRequired
                        ? 'Send for verification'
                        : 'Mark complete'}
                </Button>
            </div>
        </form>
    );
}

function VerifyForm({ task }: { task: TaskDetail }) {
    const errors = usePage().props.errors as Record<string, string>;
    const [comment, setComment] = useState('');
    const send = (decision: 'verified' | 'returned') =>
        router.post(
            verify(task.id).url,
            { decision, comment: comment || null, revision: task.revision },
            { preserveScroll: true },
        );

    return (
        <div className="grid gap-2 rounded-md border p-3">
            <p className="text-sm font-medium">Verify the work</p>
            <TextArea
                label="Comment (needed to return it)"
                value={comment}
                onChange={setComment}
                error={errors.comment}
                rows={2}
            />
            <div className="flex gap-2">
                <Button size="sm" onClick={() => send('verified')}>
                    Verify
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => send('returned')}
                >
                    Return
                </Button>
            </div>
        </div>
    );
}

function ReassignForm({
    task,
    members,
}: {
    task: TaskDetail;
    members: Option[];
}) {
    const [assignee, setAssignee] = useState(task.assignee.id);

    return (
        <div className="flex items-end gap-2">
            <SelectField
                label="Reassign to"
                value={assignee}
                onChange={setAssignee}
                options={members}
                className="flex-1"
            />
            <Button
                size="sm"
                variant="outline"
                disabled={assignee === task.assignee.id}
                onClick={() =>
                    router.post(
                        assign(task.id).url,
                        { assignee_id: assignee, revision: task.revision },
                        { preserveScroll: true },
                    )
                }
            >
                Reassign
            </Button>
        </div>
    );
}

/** More than five minutes between doing the work and the server hearing of it. */
function receivedLater(doneAt: string, receivedAt: string): boolean {
    return (
        new Date(receivedAt).getTime() - new Date(doneAt).getTime() > 5 * 60_000
    );
}
