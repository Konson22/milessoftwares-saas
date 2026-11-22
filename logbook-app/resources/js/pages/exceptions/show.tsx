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
import {
    Due,
    History,
    TaskForm,
    TaskTable,
    Toggle,
    WorkFiles,
} from '@/components/work';
import type { Option } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';
import type {
    ExceptionRow,
    HistoryEvent,
    TaskOptions,
    TaskRow,
    WorkFile,
} from '@/lib/work';
import { show as entry } from '@/routes/entries';
import {
    assign,
    close,
    index,
    reopen,
    resolve,
    start,
} from '@/routes/exceptions';
import * as files from '@/routes/exceptions/files';

type Props = {
    exception: ExceptionRow & {
        description: string | null;
        field: string | null;
        recordedValue: string | null;
        expected: string | null;
        resource: string | null;
        raisedBy: string;
        resolutionNotes: string | null;
        resolvedBy: string | null;
        closedAt: string | null;
        closedBy: string | null;
        entry: { id: string; reference: string; sheet: string } | null;
        files: WorkFile[];
    };
    tasks: TaskRow[];
    history: HistoryEvent[];
    can: {
        manage: boolean;
        assign: boolean;
        work: boolean;
        start: boolean;
        close: boolean;
        reopen: boolean;
        task: boolean;
    };
    members: Option[];
    taskOptions: TaskOptions | null;
};

export default function ExceptionPage({
    exception: x,
    tasks,
    history,
    can,
    members,
    taskOptions,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const rev = { revision: x.revision };

    return (
        <>
            <Head title={x.reference} />
            <PageHeader
                title={`${x.reference} · ${x.title}`}
                description={
                    <Link href={index()} className="hover:underline">
                        Back to exceptions
                    </Link>
                }
                actions={
                    <div className="flex gap-2">
                        <Status value={x.severity} />
                        <Status value={x.overdue ? 'overdue' : x.status} />
                    </div>
                }
            />
            <div className="mb-4">
                <Errors
                    errors={Object.fromEntries(
                        Object.entries(errors).filter(([k]) =>
                            [
                                'exception',
                                'stale',
                                'status',
                                'assignee_id',
                            ].includes(k),
                        ),
                    )}
                />
            </div>
            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid content-start gap-6 lg:col-span-2">
                    <Panel>
                        <PanelHeader title="What happened" />
                        <PanelBody className="grid gap-4">
                            {x.description && (
                                <p className="text-sm whitespace-pre-line">
                                    {x.description}
                                </p>
                            )}
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <Detail label="Raised by">
                                    {x.raisedBy} · {formatDateTime(x.createdAt)}
                                </Detail>
                                <Detail label="Entry">
                                    {x.entry && (
                                        <Link
                                            href={entry(x.entry.id)}
                                            className="hover:underline"
                                        >
                                            {x.entry.reference} ·{' '}
                                            {x.entry.sheet}
                                        </Link>
                                    )}
                                </Detail>
                                <Detail label="Field">{x.field}</Detail>
                                <Detail label="Recorded">
                                    {x.recordedValue}
                                </Detail>
                                <Detail label="Expected">{x.expected}</Detail>
                                <Detail label="Resource">{x.resource}</Detail>
                                <Detail label="Logbook">{x.logbook}</Detail>
                                <Detail label="Assigned to">
                                    {x.assignee?.name ?? 'Nobody yet'}
                                </Detail>
                                <Detail label="Due">
                                    {x.dueAt && (
                                        <Due at={x.dueAt} overdue={x.overdue} />
                                    )}
                                </Detail>
                            </dl>
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title={`Corrective tasks (${tasks.length})`}
                        />
                        {tasks.length > 0 && <TaskTable tasks={tasks} />}
                        {can.task && taskOptions && (
                            <PanelBody>
                                <Toggle
                                    label="Add a corrective task"
                                    variant="outline"
                                >
                                    {(done) => (
                                        <TaskForm
                                            options={taskOptions}
                                            onDone={done}
                                            initial={{
                                                exception_id: x.id,
                                                title: `Correct: ${x.title}`,
                                                priority:
                                                    x.severity === 'critical'
                                                        ? 'urgent'
                                                        : x.severity === 'high'
                                                          ? 'high'
                                                          : 'normal',
                                            }}
                                        />
                                    )}
                                </Toggle>
                            </PanelBody>
                        )}
                    </Panel>

                    <Panel>
                        <PanelHeader title="Resolution" />
                        <PanelBody className="grid gap-4">
                            {x.resolutionNotes && (
                                <dl className="grid gap-3 sm:grid-cols-2">
                                    <Detail label="Resolved by">
                                        {x.resolvedBy} ·{' '}
                                        {formatDateTime(x.resolvedAt)}
                                    </Detail>
                                    <Detail label="Closed by">
                                        {x.closedBy &&
                                            `${x.closedBy} · ${formatDateTime(x.closedAt)}`}
                                    </Detail>
                                    <div className="sm:col-span-2">
                                        <Detail label="What was done">
                                            {x.resolutionNotes}
                                        </Detail>
                                    </div>
                                </dl>
                            )}
                            <WorkFiles
                                files={x.files}
                                kind="evidence"
                                label="Evidence"
                                uploadUrl={files.store(x.id).url}
                                removeUrl={(id) => files.destroy(id).url}
                                canAdd={can.work}
                                canRemove={can.work}
                            />
                            {can.start && (
                                <div>
                                    <ActionButton
                                        url={start(x.id).url}
                                        label="Start work"
                                        variant="outline"
                                        data={rev}
                                    />
                                </div>
                            )}
                            {can.work && (
                                <ResolveForm id={x.id} revision={x.revision} />
                            )}
                        </PanelBody>
                    </Panel>
                </div>

                <div className="grid content-start gap-6">
                    {can.manage && (
                        <Panel>
                            <PanelHeader title="Follow up" />
                            <PanelBody className="grid gap-3">
                                {can.assign && (
                                    <AssignForm
                                        id={x.id}
                                        revision={x.revision}
                                        members={members}
                                        current={x.assignee?.id ?? ''}
                                    />
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {can.close && (
                                        <ActionButton
                                            url={close(x.id).url}
                                            label="Close"
                                            data={rev}
                                        />
                                    )}
                                    {can.reopen && (
                                        <ActionButton
                                            url={reopen(x.id).url}
                                            label="Reopen"
                                            reason="Why reopen it?"
                                            variant="outline"
                                            data={rev}
                                        />
                                    )}
                                </div>
                                {x.status === 'resolved' && !can.close && (
                                    <p className="text-xs text-muted-foreground">
                                        Someone other than the person who
                                        resolved it closes it.
                                    </p>
                                )}
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

function ResolveForm({ id, revision }: { id: string; revision: number }) {
    const errors = usePage().props.errors as Record<string, string>;
    const [notes, setNotes] = useState('');

    return (
        <form
            className="grid gap-2 rounded-md border p-3"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    resolve(id).url,
                    { resolution_notes: notes, revision },
                    { preserveScroll: true },
                );
            }}
        >
            <TextArea
                label="What was done to resolve it"
                value={notes}
                onChange={setNotes}
                error={errors.resolution_notes}
                rows={3}
            />
            <div>
                <Button type="submit" size="sm" disabled={notes.trim() === ''}>
                    Mark resolved
                </Button>
            </div>
        </form>
    );
}

function AssignForm({
    id,
    revision,
    members,
    current,
}: {
    id: string;
    revision: number;
    members: Option[];
    current: string;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [assignee, setAssignee] = useState(current);
    const [due, setDue] = useState('');

    return (
        <form
            className="grid gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    assign(id).url,
                    { assignee_id: assignee, due_at: due || null, revision },
                    { preserveScroll: true },
                );
            }}
        >
            <SelectField
                label="Assign to"
                value={assignee}
                onChange={setAssignee}
                options={members}
                empty="Choose a person…"
                error={errors.assignee_id}
            />
            <TextField
                label="Due (optional)"
                type="datetime-local"
                value={due}
                onChange={setDue}
                error={errors.due_at}
            />
            <div>
                <Button
                    type="submit"
                    size="sm"
                    variant="outline"
                    disabled={assignee === ''}
                >
                    Assign
                </Button>
            </div>
        </form>
    );
}
