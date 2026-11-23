import { Link, router, usePage } from '@inertiajs/react';
import { Modal } from '@inertiaui/modal-react';
import {
    ArrowUpRight,
    ClipboardCheck,
    FileText,
    History as HistoryIcon,
    ListTodo,
    MapPin,
    Paperclip,
    Search,
    TriangleAlert,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { ModalButton } from '@/components/dialogs';
import {
    Errors,
    SelectField,
    Status,
    TextArea,
    TextField,
} from '@/components/fields';
import type { EntryRow } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';
import { show as attachmentShow } from '@/routes/attachments';
import { show as entryShow } from '@/routes/entries';
import {
    show as exceptionShow,
    store as exceptionStore,
} from '@/routes/exceptions';
import {
    create as investigationCreate,
    show as investigationShow,
} from '@/routes/investigations';
import { store as evidenceStore } from '@/routes/investigations/evidence';
import { index as reportsIndex } from '@/routes/reports';
import { show as taskShow, store as taskStore } from '@/routes/tasks';
import { show as trackShow } from '@/routes/tracks';

type Answer = {
    name: string;
    label: string;
    type: string;
    text: string | null;
    unit: string | null;
    outOfRange: { direction: string; range: string } | null;
    files: {
        id: string;
        name: string;
        size: number;
        mime: string;
        removed: boolean;
    }[];
};

type Option = { value: string; label: string };

type Props = {
    entry: EntryRow & {
        version: number;
        revision: number;
        reviewer: string | null;
        reviewedAt: string | null;
        answers: Answer[];
        location: {
            lat?: number;
            lng?: number;
            accuracy?: number;
            capturedAt?: string;
        } | null;
        track: string | null;
        logbookId: string;
    };
    resources: { id: string; label: string }[];
    revisions: {
        revision: number;
        submittedAt: string | null;
        submittedBy: string;
        occurredAt: string | null;
    }[];
    reviews: {
        id: string;
        revision: number;
        decision: string;
        comment: string | null;
        reviewer: string;
        decidedAt: string | null;
    }[];
    exceptions: {
        id: string;
        reference: string;
        title: string;
        severity: string;
        status: string;
        field: string | null;
    }[];
    tasks: {
        id: string;
        reference: string;
        title: string;
        status: string;
        assignee: string;
        dueAt: string | null;
    }[];
    history:
        | { id: string; action: string; user: string | null; at: string }[]
        | null;
    members: Option[];
    investigations: Option[];
    can: {
        review: boolean;
        reportException: boolean;
        createTask: boolean;
        investigate: boolean;
    };
};

const SEVERITY: Record<string, { bg: string; fg: string }> = {
    low: { bg: '#eef0f2', fg: '#3d4651' },
    medium: { bg: '#fcefdc', fg: '#8a4b00' },
    high: { bg: '#fbe5e7', fg: '#a10d19' },
    critical: { bg: '#a10d19', fg: '#ffffff' },
};

const actionLabel = (a: string) =>
    a.replace(/^entry\./, '').replace(/[._]/g, ' ');

/**
 * One record, opened from a report without leaving it (a slide-over):
 * everything Logbook knows about the entry, and the next steps, each
 * through the ordinary endpoint and its rules.
 */
export default function Record(props: Props) {
    const { entry, can } = props;

    return (
        <Modal slideover maxWidth="3xl" position="right">
            <div className="grid gap-5">
                <header className="grid gap-1 pr-8">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-sm font-semibold">
                            {entry.reference}
                        </span>
                        <Status value={entry.status} />
                        {entry.revision > 1 && (
                            <span className="text-xs text-muted-foreground">
                                revision {entry.revision}
                            </span>
                        )}
                    </div>
                    <h2 className="text-lg font-semibold">
                        {entry.sheet.code} · {entry.sheet.name}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {entry.logbook} · by {entry.creator} · happened{' '}
                        {formatDateTime(entry.occurredAt)} · recorded{' '}
                        {formatDateTime(entry.submittedAt)}
                        {entry.reviewer &&
                            ` · reviewed by ${entry.reviewer} ${formatDateTime(entry.reviewedAt)}`}
                    </p>
                </header>

                <div className="flex flex-wrap gap-2">
                    <Button asChild size="sm" variant="outline">
                        <Link href={entryShow(entry.id)}>
                            {can.review ? (
                                <>
                                    <ClipboardCheck /> Open to review
                                </>
                            ) : (
                                <>
                                    <ArrowUpRight /> Open entry
                                </>
                            )}
                        </Link>
                    </Button>
                    {can.reportException && <ReportException {...props} />}
                    {can.createTask && <GiveTask {...props} />}
                    {can.investigate && <Investigate {...props} />}
                    {props.resources.map((r) => (
                        <Button key={r.id} asChild size="sm" variant="ghost">
                            <Link
                                href={reportsIndex({
                                    query: { resource: r.id, view: 'timeline' },
                                })}
                            >
                                <Search /> History of {r.label}
                            </Link>
                        </Button>
                    ))}
                </div>

                <Section title="Answers" icon={FileText}>
                    <dl className="divide-y divide-[#f0f0f0]">
                        {entry.answers.map((a) => (
                            <div
                                key={a.name}
                                className="grid gap-1 px-4 py-2.5 sm:grid-cols-[12rem_1fr]"
                            >
                                <dt className="text-sm text-muted-foreground">
                                    {a.label}
                                </dt>
                                <dd className="text-sm">
                                    {a.type === 'file' ? (
                                        a.files.length === 0 ? (
                                            <span className="text-[#9aa1a9]">
                                                –
                                            </span>
                                        ) : (
                                            <ul className="grid gap-1">
                                                {a.files.map((f) => (
                                                    <li key={f.id}>
                                                        {f.removed ? (
                                                            <span className="text-muted-foreground line-through">
                                                                {f.name}
                                                            </span>
                                                        ) : (
                                                            <a
                                                                href={
                                                                    attachmentShow(
                                                                        f.id,
                                                                    ).url
                                                                }
                                                                className="inline-flex items-center gap-1 text-primary underline-offset-2 hover:underline"
                                                            >
                                                                <Paperclip className="size-3.5" />
                                                                {f.name}
                                                            </a>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )
                                    ) : (
                                        <span
                                            className={
                                                a.outOfRange
                                                    ? 'font-semibold text-[#a10d19]'
                                                    : ''
                                            }
                                        >
                                            {a.text ?? (
                                                <span className="font-normal text-[#9aa1a9]">
                                                    –
                                                </span>
                                            )}
                                            {a.text && a.unit && ` ${a.unit}`}
                                        </span>
                                    )}
                                    {a.outOfRange && (
                                        <span className="mt-1 flex items-center gap-1 text-xs font-medium text-[#a10d19]">
                                            <TriangleAlert className="size-3.5" />
                                            {a.outOfRange.direction === 'high'
                                                ? 'Above'
                                                : 'Below'}{' '}
                                            the normal range (
                                            {a.outOfRange.range})
                                        </span>
                                    )}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </Section>

                {entry.location?.lat !== undefined && (
                    <Section title="Where it was written" icon={MapPin}>
                        <p className="px-4 py-3 text-sm">
                            {entry.location.lat?.toFixed(5)},{' '}
                            {entry.location.lng?.toFixed(5)}
                            {entry.location.accuracy !== undefined &&
                                ` · within ${Math.round(entry.location.accuracy)} m`}
                            {entry.track && (
                                <>
                                    {' · '}
                                    <Link
                                        href={trackShow(entry.track)}
                                        className="text-primary hover:underline"
                                    >
                                        on a recorded route
                                    </Link>
                                </>
                            )}
                        </p>
                    </Section>
                )}

                <div className="grid gap-5 md:grid-cols-2">
                    <Section
                        title="Exceptions"
                        icon={TriangleAlert}
                        count={props.exceptions.length}
                    >
                        <Linked
                            empty="None raised on this entry."
                            items={props.exceptions.map((x) => ({
                                id: x.id,
                                href: exceptionShow(x.id).url,
                                top: `${x.reference} · ${x.title}`,
                                bottom: `${x.status.replace('_', ' ')}${x.field ? ` · ${x.field}` : ''}`,
                                badge: (
                                    <span
                                        className="rounded px-1.5 text-[11px] font-semibold capitalize"
                                        style={{
                                            backgroundColor:
                                                SEVERITY[x.severity]?.bg,
                                            color: SEVERITY[x.severity]?.fg,
                                        }}
                                    >
                                        {x.severity}
                                    </span>
                                ),
                            }))}
                        />
                    </Section>
                    <Section
                        title="Tasks"
                        icon={ListTodo}
                        count={props.tasks.length}
                    >
                        <Linked
                            empty="No task follows from this entry."
                            items={props.tasks.map((t) => ({
                                id: t.id,
                                href: taskShow(t.id).url,
                                top: `${t.reference} · ${t.title}`,
                                bottom: `${t.status.replace('_', ' ')} · ${t.assignee}${t.dueAt ? ` · due ${formatDateTime(t.dueAt)}` : ''}`,
                            }))}
                        />
                    </Section>
                </div>

                <div className="grid gap-5 md:grid-cols-2">
                    <Section
                        title="Revisions and reviews"
                        icon={ClipboardCheck}
                    >
                        <ol className="divide-y divide-[#f0f0f0]">
                            {props.revisions.map((r) => (
                                <li
                                    key={r.revision}
                                    className="px-4 py-2 text-sm"
                                >
                                    <span className="font-medium">
                                        Revision {r.revision}
                                    </span>{' '}
                                    sent by {r.submittedBy}
                                    <span className="block text-xs text-muted-foreground">
                                        {formatDateTime(r.submittedAt)}
                                    </span>
                                    {props.reviews
                                        .filter(
                                            (v) => v.revision === r.revision,
                                        )
                                        .map((v) => (
                                            <span
                                                key={v.id}
                                                className="mt-1 block rounded bg-[#f4f6f8] px-2 py-1 text-xs"
                                            >
                                                <span className="font-semibold capitalize">
                                                    {v.decision}
                                                </span>{' '}
                                                by {v.reviewer},{' '}
                                                {formatDateTime(v.decidedAt)}
                                                {v.comment && `: ${v.comment}`}
                                            </span>
                                        ))}
                                </li>
                            ))}
                            {props.revisions.length === 0 && (
                                <li className="px-4 py-3 text-sm text-muted-foreground">
                                    No revision recorded.
                                </li>
                            )}
                        </ol>
                    </Section>
                    {props.history && (
                        <Section title="History" icon={HistoryIcon}>
                            <ol className="max-h-72 divide-y divide-[#f0f0f0] overflow-y-auto">
                                {props.history.map((h) => (
                                    <li
                                        key={h.id}
                                        className="px-4 py-2 text-sm"
                                    >
                                        <span className="font-medium">
                                            {h.user ?? 'Logbook'}
                                        </span>{' '}
                                        {actionLabel(h.action)}
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateTime(h.at)}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </Section>
                    )}
                </div>
            </div>
        </Modal>
    );
}

function Section({
    title,
    icon: Icon,
    count,
    children,
}: {
    title: string;
    icon: ComponentType<{ className?: string }>;
    count?: number;
    children: ReactNode;
}) {
    return (
        <section className="overflow-hidden rounded-lg border bg-card">
            <h3 className="flex items-center gap-2 border-b border-[#ebebeb] px-4 py-2.5 text-sm font-semibold">
                <Icon className="size-4 text-muted-foreground" />
                {title}
                {count !== undefined && (
                    <span className="text-muted-foreground tabular-nums">
                        {count}
                    </span>
                )}
            </h3>
            {children}
        </section>
    );
}

function Linked({
    items,
    empty,
}: {
    items: {
        id: string;
        href: string;
        top: string;
        bottom: string;
        badge?: ReactNode;
    }[];
    empty: string;
}) {
    if (items.length === 0) {
        return (
            <p className="px-4 py-3 text-sm text-muted-foreground">{empty}</p>
        );
    }

    return (
        <ul className="divide-y divide-[#f0f0f0]">
            {items.map((i) => (
                <li key={i.id}>
                    <Link
                        href={i.href}
                        className="grid gap-0.5 px-4 py-2 hover:bg-[#f4f8f7]"
                    >
                        <span className="flex items-center justify-between gap-2 text-sm font-medium">
                            <span className="truncate">{i.top}</span>
                            {i.badge}
                        </span>
                        <span className="text-xs text-muted-foreground capitalize">
                            {i.bottom}
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function useErrors() {
    return usePage().props.errors as Record<string, string>;
}

/** Raises an exception on this entry: the ordinary exception, with its own lifecycle. */
function ReportException({ entry }: Props) {
    const errors = useErrors();
    const [data, setData] = useState({
        title: '',
        severity: 'medium',
        description: '',
    });
    const [busy, setBusy] = useState(false);

    return (
        <ModalButton label="Report exception" variant="outline" maxWidth="lg">
            {(close) => (
                <div className="grid gap-3">
                    <TextField
                        label="What is wrong"
                        value={data.title}
                        onChange={(title) => setData({ ...data, title })}
                        error={errors.title}
                    />
                    <SelectField
                        label="Severity"
                        value={data.severity}
                        onChange={(severity) => setData({ ...data, severity })}
                        options={['low', 'medium', 'high', 'critical'].map(
                            (s) => ({
                                value: s,
                                label: s[0].toUpperCase() + s.slice(1),
                            }),
                        )}
                    />
                    <TextArea
                        label="Details (optional)"
                        value={data.description}
                        onChange={(description) =>
                            setData({ ...data, description })
                        }
                        rows={3}
                    />
                    <Errors
                        errors={errors.stale ? { stale: errors.stale } : {}}
                    />
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={close}>
                            Back
                        </Button>
                        <Button
                            disabled={busy || data.title.trim() === ''}
                            onClick={() =>
                                router.post(
                                    exceptionStore().url,
                                    { ...data, log_entry_id: entry.id },
                                    {
                                        onStart: () => setBusy(true),
                                        onFinish: () => setBusy(false),
                                        onSuccess: close,
                                    },
                                )
                            }
                        >
                            Report
                        </Button>
                    </div>
                </div>
            )}
        </ModalButton>
    );
}

/** Gives a follow-up task through the task rules (tasks.manage only). */
function GiveTask({ entry, members }: Props) {
    const errors = useErrors();
    const [data, setData] = useState({
        title: '',
        assignee_id: '',
        priority: 'normal',
        due_at: '',
    });
    const [busy, setBusy] = useState(false);

    return (
        <ModalButton label="Give a task" variant="outline" maxWidth="lg">
            {(close) => (
                <div className="grid gap-3">
                    <TextField
                        label="What needs doing"
                        value={data.title}
                        onChange={(title) => setData({ ...data, title })}
                        error={errors.title}
                    />
                    <SelectField
                        label="Who does it"
                        value={data.assignee_id}
                        onChange={(assignee_id) =>
                            setData({ ...data, assignee_id })
                        }
                        options={members}
                        empty="Choose a person"
                        error={errors.assignee_id}
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <SelectField
                            label="Priority"
                            value={data.priority}
                            onChange={(priority) =>
                                setData({ ...data, priority })
                            }
                            options={[
                                { value: 'low', label: 'Low' },
                                { value: 'normal', label: 'Normal' },
                                { value: 'high', label: 'High' },
                                { value: 'urgent', label: 'Urgent' },
                            ]}
                        />
                        <TextField
                            label="Due (optional)"
                            type="datetime-local"
                            value={data.due_at}
                            onChange={(due_at) => setData({ ...data, due_at })}
                            error={errors.due_at}
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={close}>
                            Back
                        </Button>
                        <Button
                            disabled={
                                busy ||
                                data.title.trim() === '' ||
                                data.assignee_id === ''
                            }
                            onClick={() =>
                                router.post(
                                    taskStore().url,
                                    {
                                        ...data,
                                        due_at: data.due_at || null,
                                        logbook_id: entry.logbookId,
                                        log_entry_id: entry.id,
                                    },
                                    {
                                        onStart: () => setBusy(true),
                                        onFinish: () => setBusy(false),
                                        onSuccess: close,
                                    },
                                )
                            }
                        >
                            Give task
                        </Button>
                    </div>
                </div>
            )}
        </ModalButton>
    );
}

/** Links this exact revision to an open investigation, or starts one from it. */
function Investigate({ entry, investigations }: Props) {
    const errors = useErrors();
    const [target, setTarget] = useState(investigations[0]?.value ?? '');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <ModalButton label="Investigate" variant="outline" maxWidth="lg">
            {(close) => (
                <div className="grid gap-3">
                    <p className="text-sm text-muted-foreground">
                        The investigation keeps a reference to revision{' '}
                        {entry.revision} of {entry.reference}; the entry itself
                        is not changed.
                    </p>
                    {investigations.length > 0 && (
                        <>
                            <SelectField
                                label="Add to an open investigation"
                                value={target}
                                onChange={setTarget}
                                options={investigations}
                                error={errors.subject_id}
                            />
                            <TextField
                                label="Why it matters (optional)"
                                value={note}
                                onChange={setNote}
                            />
                        </>
                    )}
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button asChild variant="outline">
                            <Link
                                href={investigationCreate({
                                    query: { entry: entry.id },
                                })}
                            >
                                Start a new investigation
                            </Link>
                        </Button>
                        {investigations.length > 0 && (
                            <Button
                                disabled={busy || target === ''}
                                onClick={() =>
                                    router.post(
                                        evidenceStore(target).url,
                                        {
                                            kind: 'entry',
                                            subject_id: entry.id,
                                            revision: entry.revision,
                                            note: note || null,
                                        },
                                        {
                                            onStart: () => setBusy(true),
                                            onFinish: () => setBusy(false),
                                            onSuccess: () => {
                                                close();
                                                router.visit(
                                                    investigationShow(target)
                                                        .url,
                                                );
                                            },
                                        },
                                    )
                                }
                            >
                                Add to it
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </ModalButton>
    );
}
