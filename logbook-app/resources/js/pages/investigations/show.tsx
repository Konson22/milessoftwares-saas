import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    FileDown,
    History as HistoryIcon,
    Link2,
    ListTodo,
    Lock,
    NotebookPen,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { ActionButton } from '@/components/actions';
import { ModalButton } from '@/components/dialogs';
import {
    Detail,
    Errors,
    SelectField,
    TextArea,
    TextField,
} from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import { useOpenRecord } from '@/components/report-views';
import { formatDate, formatDateTime } from '@/lib/logbook';
import { show as exceptionShow } from '@/routes/exceptions';
import {
    close as closeRoute,
    index,
    move,
    pdf,
    reopen,
    update,
} from '@/routes/investigations';
import {
    remove as evidenceRemove,
    store as evidenceStore,
} from '@/routes/investigations/evidence';
import {
    store as noteStore,
    update as noteUpdate,
} from '@/routes/investigations/notes';
import { store as correctiveStore } from '@/routes/investigations/tasks';
import { show as taskShow } from '@/routes/tasks';
import { show as trackShow } from '@/routes/tracks';
import type { InvestigationRow } from './index';
import { InvestigationStatus } from './index';

type Option = { value: string; label: string };

type Evidence = {
    id: string;
    kind: 'entry' | 'exception' | 'task' | 'track';
    role: 'evidence' | 'corrective';
    restricted: boolean;
    label: string | null;
    subjectId: string | null;
    revision: number | null;
    note: string | null;
    addedBy: string;
    addedAt: string;
    removed: { at: string; by: string | null; reason: string | null } | null;
};

type Note = {
    id: string;
    kind: string;
    body: string;
    version: number;
    supersedesId: string | null;
    current: boolean;
    author: string;
    at: string | null;
};

type Props = {
    investigation: InvestigationRow & {
        description: string | null;
        creator: string;
        closureDecision: string | null;
        closedAt: string | null;
        closedBy: string | null;
        reopenedAt: string | null;
        revision: number;
        logbookId: string;
        resourceId: string | null;
        investigatorId: string;
        periodFrom: string | null;
        periodTo: string | null;
    };
    evidence: Evidence[];
    timeline: {
        at: string;
        basis: 'occurred' | 'recorded';
        title: string;
        detail: string;
        subject: { type: Evidence['kind']; id: string };
    }[];
    notes: Note[];
    history: {
        id: string;
        action: string;
        user: string | null;
        details: Record<string, unknown> | null;
        at: string | null;
    }[];
    resources: Option[];
    members: Option[];
    can: { manage: boolean; reopen: boolean; createTask: boolean };
};

const KINDS: { key: string; label: string; hint: string }[] = [
    {
        key: 'observation',
        label: 'Observations',
        hint: 'What was seen or measured.',
    },
    {
        key: 'hypothesis',
        label: 'Hypotheses',
        hint: 'Possible explanations still to check.',
    },
    { key: 'finding', label: 'Findings', hint: 'What the evidence shows.' },
    {
        key: 'conclusion',
        label: 'Conclusions',
        hint: 'What the investigator concludes.',
    },
    {
        key: 'follow_up',
        label: 'Follow-up',
        hint: 'What should happen next.',
    },
];

const KIND_LABEL: Record<Evidence['kind'], string> = {
    entry: 'Entry',
    exception: 'Exception',
    task: 'Task',
    track: 'Route',
};

const actionLabel = (a: string) =>
    a.replace(/^investigation\./, '').replace(/[._]/g, ' ');

/**
 * One investigation: the incident, the records it refers to (each opened
 * with the viewer's own access), what was observed and found, the
 * corrective tasks, and its closure. Every change is kept.
 */
export default function ShowInvestigation(props: Props) {
    const { investigation: inv, can } = props;
    const errors = usePage().props.errors as Record<string, string>;
    const hasFinding = props.notes.some(
        (n) => n.kind === 'finding' && n.current,
    );
    const revision = { revision: inv.revision };

    return (
        <>
            <Head title={`${inv.reference} · ${inv.title}`} />
            <PageHeader
                title={
                    <span className="flex flex-wrap items-baseline gap-2">
                        <span className="font-mono text-base text-muted-foreground">
                            {inv.reference}
                        </span>
                        {inv.title}
                    </span>
                }
                badges={
                    <InvestigationStatus
                        status={inv.status}
                        label={inv.statusLabel}
                    />
                }
                actions={
                    <>
                        <Button asChild variant="outline" size="sm">
                            <a href={pdf(inv.id).url}>
                                <FileDown /> PDF
                            </a>
                        </Button>
                        {can.manage && inv.status === 'open' && (
                            <ActionButton
                                url={move(inv.id).url}
                                label="Start investigating"
                                data={{ ...revision, status: 'investigating' }}
                            />
                        )}
                        {can.manage && inv.status === 'investigating' && (
                            <ActionButton
                                url={move(inv.id).url}
                                label="Findings recorded"
                                data={{
                                    ...revision,
                                    status: 'findings_recorded',
                                }}
                                variant={hasFinding ? 'default' : 'outline'}
                            />
                        )}
                        {can.manage && inv.status === 'findings_recorded' && (
                            <>
                                <ActionButton
                                    url={move(inv.id).url}
                                    label="Back to investigating"
                                    variant="outline"
                                    data={{
                                        ...revision,
                                        status: 'investigating',
                                    }}
                                />
                                <CloseForm
                                    id={inv.id}
                                    revision={inv.revision}
                                />
                            </>
                        )}
                        {can.reopen && (
                            <ActionButton
                                url={reopen(inv.id).url}
                                label="Reopen"
                                reason="Why it is reopened"
                                variant="outline"
                                data={revision}
                            />
                        )}
                    </>
                }
            />

            <div className="grid gap-5">
                <Errors
                    errors={Object.fromEntries(
                        ['status', 'stale', 'closure_decision', 'reason']
                            .filter((k) => errors[k])
                            .map((k) => [k, errors[k]]),
                    )}
                />

                {inv.status === 'investigating' &&
                    !hasFinding &&
                    can.manage && (
                        <p className="rounded-md bg-[#eef0f2] px-3 py-2 text-sm text-[#3d4651]">
                            Write at least one finding before marking the
                            findings recorded; closing needs a finding and a
                            closure decision.
                        </p>
                    )}

                <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start">
                    <div className="grid gap-5">
                        <Panel
                            title="Incident"
                            icon={NotebookPen}
                            action={can.manage && <EditDetails {...props} />}
                        >
                            <div className="grid gap-4 px-4 py-4">
                                {inv.description ? (
                                    <p className="text-sm whitespace-pre-line">
                                        {inv.description}
                                    </p>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No description written.
                                    </p>
                                )}
                                <dl className="grid gap-3 sm:grid-cols-3">
                                    <Detail label="Logbook">
                                        {inv.logbook}
                                    </Detail>
                                    <Detail label="Resource">
                                        {inv.resource}
                                    </Detail>
                                    <Detail label="Incident time">
                                        {inv.occurredAt &&
                                            formatDateTime(inv.occurredAt)}
                                    </Detail>
                                    <Detail label="Investigator">
                                        {inv.investigator}
                                    </Detail>
                                    <Detail label="Period looked at">
                                        {inv.periodFrom || inv.periodTo
                                            ? `${inv.periodFrom ? formatDate(inv.periodFrom) : '…'} – ${inv.periodTo ? formatDate(inv.periodTo) : '…'}`
                                            : null}
                                    </Detail>
                                    <Detail label="Opened">
                                        {formatDateTime(inv.createdAt)} by{' '}
                                        {inv.creator}
                                    </Detail>
                                </dl>
                            </div>
                        </Panel>

                        <EvidencePanel {...props} />

                        <Panel
                            title="Timeline of the linked records"
                            icon={CalendarClock}
                        >
                            {props.timeline.length === 0 ? (
                                <p className="px-4 py-4 text-sm text-muted-foreground">
                                    Link records to see when they happened.
                                </p>
                            ) : (
                                <ol className="divide-y divide-[#f0f0f0]">
                                    {props.timeline.map((e, i) => (
                                        <li
                                            key={`${e.subject.id}-${i}`}
                                            className="grid gap-0.5 px-4 py-2 text-sm sm:grid-cols-[11rem_6rem_1fr]"
                                        >
                                            <span className="text-muted-foreground tabular-nums">
                                                {formatDateTime(e.at)}
                                            </span>
                                            <span>
                                                {e.basis === 'occurred' ? (
                                                    <span className="rounded bg-[#16202c] px-1.5 text-[10px] font-semibold text-white">
                                                        HAPPENED
                                                    </span>
                                                ) : (
                                                    <span className="rounded border border-[#9aa1a9] px-1.5 text-[10px] font-semibold">
                                                        RECORDED
                                                    </span>
                                                )}
                                            </span>
                                            <span>
                                                <span className="font-medium">
                                                    {e.title}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    · {e.detail}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </Panel>

                        <NotesPanel {...props} />
                    </div>

                    <div className="grid gap-5">
                        <Panel title="Corrective actions" icon={ListTodo}>
                            <ul className="divide-y divide-[#f0f0f0]">
                                {props.evidence
                                    .filter(
                                        (e) =>
                                            e.role === 'corrective' &&
                                            !e.removed,
                                    )
                                    .map((e) =>
                                        e.restricted ? (
                                            <li
                                                key={e.id}
                                                className="flex items-center gap-2 px-4 py-2 text-sm text-muted-foreground"
                                            >
                                                <Lock className="size-3.5" /> A
                                                task you cannot open
                                            </li>
                                        ) : (
                                            <li key={e.id}>
                                                <Link
                                                    href={taskShow(
                                                        e.subjectId!,
                                                    )}
                                                    className="block px-4 py-2 text-sm hover:bg-[#f4f8f7]"
                                                >
                                                    {e.label}
                                                </Link>
                                            </li>
                                        ),
                                    )}
                            </ul>
                            {props.evidence.every(
                                (e) => e.role !== 'corrective' || e.removed,
                            ) && (
                                <p className="px-4 py-3 text-sm text-muted-foreground">
                                    None given yet.
                                </p>
                            )}
                            {can.createTask && (
                                <div className="border-t border-[#ebebeb] px-4 py-3">
                                    <CorrectiveTask {...props} />
                                </div>
                            )}
                        </Panel>

                        <Panel title="Closure" icon={NotebookPen}>
                            <div className="px-4 py-3 text-sm">
                                {inv.status === 'closed' ? (
                                    <>
                                        <p className="whitespace-pre-line">
                                            {inv.closureDecision}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Closed{' '}
                                            {formatDateTime(inv.closedAt)} by{' '}
                                            {inv.closedBy}
                                        </p>
                                    </>
                                ) : inv.closureDecision ? (
                                    <>
                                        <p className="text-muted-foreground">
                                            Reopened{' '}
                                            {formatDateTime(inv.reopenedAt)}.
                                            The earlier decision was:
                                        </p>
                                        <p className="mt-1 whitespace-pre-line">
                                            {inv.closureDecision}
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-muted-foreground">
                                        Not closed.
                                    </p>
                                )}
                            </div>
                        </Panel>

                        <Panel title="History" icon={HistoryIcon}>
                            <ol className="max-h-96 divide-y divide-[#f0f0f0] overflow-y-auto">
                                {props.history.map((h) => (
                                    <li
                                        key={h.id}
                                        className="px-4 py-2 text-sm"
                                    >
                                        <span className="font-medium">
                                            {h.user ?? 'Logbook'}
                                        </span>{' '}
                                        {actionLabel(h.action)}
                                        {typeof h.details?.reason ===
                                            'string' && `: ${h.details.reason}`}
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateTime(h.at)}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </Panel>
                    </div>
                </div>

                <p className="text-xs text-muted-foreground">
                    <Link href={index()} className="hover:underline">
                        All investigations
                    </Link>
                </p>
            </div>
        </>
    );
}

function Panel({
    title,
    icon: Icon,
    action,
    children,
}: {
    title: string;
    icon: ComponentType<{ className?: string }>;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
            <header className="flex items-center gap-2 border-b border-[#ebebeb] px-4 py-2.5">
                <Icon className="size-4 text-muted-foreground" />
                <h2 className="text-sm font-semibold">{title}</h2>
                <span className="ml-auto">{action}</span>
            </header>
            {children}
        </section>
    );
}

function EvidencePanel({ investigation: inv, evidence, can }: Props) {
    const open = useOpenRecord();
    const errors = usePage().props.errors as Record<string, string>;
    const [kind, setKind] = useState('entry');
    const [reference, setReference] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const shown = evidence.filter((e) => e.role === 'evidence');

    const href = (e: Evidence) =>
        e.kind === 'exception'
            ? exceptionShow(e.subjectId!).url
            : e.kind === 'task'
              ? taskShow(e.subjectId!).url
              : trackShow(e.subjectId!).url;

    return (
        <Panel
            title="Evidence"
            icon={Link2}
            action={
                <span className="text-xs text-muted-foreground">
                    references to the original records
                </span>
            }
        >
            {shown.length === 0 ? (
                <p className="px-4 py-4 text-sm text-muted-foreground">
                    Nothing linked yet. Link records from Reports (open a record
                    and choose Investigate) or by reference below.
                </p>
            ) : (
                <ul className="divide-y divide-[#f0f0f0]">
                    {shown.map((e) => (
                        <li
                            key={e.id}
                            className={`flex flex-wrap items-start gap-3 px-4 py-2.5 ${e.removed ? 'bg-[#f8f9fa]' : ''}`}
                        >
                            <span className="w-20 shrink-0 text-xs font-medium text-muted-foreground uppercase">
                                {KIND_LABEL[e.kind]}
                            </span>
                            <span className="grid min-w-0 flex-1 gap-0.5 text-sm">
                                {e.restricted ? (
                                    <span className="flex items-center gap-1.5 text-muted-foreground">
                                        <Lock className="size-3.5" />
                                        Restricted: you cannot open this record
                                    </span>
                                ) : e.kind === 'entry' ? (
                                    <button
                                        type="button"
                                        onClick={() => open(e.subjectId!)}
                                        className={`text-left font-medium text-primary hover:underline ${e.removed ? 'line-through' : ''}`}
                                    >
                                        {e.label}
                                        {e.revision &&
                                            ` · revision ${e.revision}`}
                                    </button>
                                ) : (
                                    <Link
                                        href={href(e)}
                                        className={`font-medium text-primary hover:underline ${e.removed ? 'line-through' : ''}`}
                                    >
                                        {e.label}
                                    </Link>
                                )}
                                {e.note && (
                                    <span className="text-muted-foreground">
                                        {e.note}
                                    </span>
                                )}
                                <span className="text-xs text-muted-foreground">
                                    added by {e.addedBy},{' '}
                                    {formatDateTime(e.addedAt)}
                                    {e.removed &&
                                        ` · removed by ${e.removed.by ?? '—'}, ${formatDateTime(e.removed.at)}${e.removed.reason ? `: ${e.removed.reason}` : ''}`}
                                </span>
                            </span>
                            {can.manage && !e.removed && (
                                <ActionButton
                                    url={evidenceRemove([inv.id, e.id]).url}
                                    label="Remove"
                                    reason="Why it no longer belongs here (the link stays listed as removed)"
                                    variant="outline"
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {can.manage && (
                <form
                    className="grid gap-3 border-t border-[#ebebeb] px-4 py-3 sm:grid-cols-[9rem_1fr_1fr_auto] sm:items-end"
                    onSubmit={(ev) => {
                        ev.preventDefault();
                        router.post(
                            evidenceStore(inv.id).url,
                            { kind, reference, note: note || null },
                            {
                                preserveScroll: true,
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                                onSuccess: () => {
                                    setReference('');
                                    setNote('');
                                },
                            },
                        );
                    }}
                >
                    <SelectField
                        label="Link a"
                        value={kind}
                        onChange={setKind}
                        options={[
                            { value: 'entry', label: 'Entry' },
                            { value: 'exception', label: 'Exception' },
                            { value: 'task', label: 'Task' },
                        ]}
                    />
                    <TextField
                        label="Reference"
                        value={reference}
                        onChange={setReference}
                        placeholder="GEN-2026-000004"
                        error={errors.reference ?? errors.subject_id}
                    />
                    <TextField
                        label="Why it matters (optional)"
                        value={note}
                        onChange={setNote}
                    />
                    <Button
                        type="submit"
                        disabled={busy || reference.trim() === ''}
                    >
                        Link
                    </Button>
                </form>
            )}
        </Panel>
    );
}

function NotesPanel({ investigation: inv, notes, can }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [kind, setKind] = useState('observation');
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const [showOld, setShowOld] = useState(false);

    return (
        <Panel
            title="Notes and findings"
            icon={NotebookPen}
            action={
                notes.some((n) => !n.current) && (
                    <button
                        type="button"
                        onClick={() => setShowOld(!showOld)}
                        className="text-xs text-primary hover:underline"
                    >
                        {showOld ? 'Hide' : 'Show'} earlier wordings
                    </button>
                )
            }
        >
            <div className="grid gap-4 px-4 py-4">
                {KINDS.map((k) => {
                    const items = notes.filter(
                        (n) => n.kind === k.key && (showOld || n.current),
                    );
                    if (items.length === 0) return null;
                    return (
                        <div key={k.key} className="grid gap-2">
                            <h3 className="text-sm font-semibold">{k.label}</h3>
                            {items.map((n) => (
                                <article
                                    key={n.id}
                                    className={`rounded-md border px-3 py-2 ${n.current ? 'border-[#e3e6e9]' : 'border-dashed border-[#c7ccd1] bg-[#f8f9fa]'}`}
                                >
                                    <p className="text-sm whitespace-pre-line">
                                        {n.body}
                                    </p>
                                    <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        {n.author} · {formatDateTime(n.at)}
                                        {n.version > 1 &&
                                            ` · wording ${n.version}`}
                                        {!n.current && ' · replaced'}
                                        {can.manage && n.current && (
                                            <ReviseNote
                                                investigation={inv.id}
                                                note={n}
                                            />
                                        )}
                                    </p>
                                </article>
                            ))}
                        </div>
                    );
                })}
                {notes.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Nothing written yet. Notes are written by the
                        investigator; Logbook never works out a cause.
                    </p>
                )}
                {can.manage && (
                    <form
                        className="grid gap-3 rounded-md bg-[#f8f9fa] p-3"
                        onSubmit={(ev) => {
                            ev.preventDefault();
                            router.post(
                                noteStore(inv.id).url,
                                { kind, body },
                                {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                    onSuccess: () => setBody(''),
                                },
                            );
                        }}
                    >
                        <div
                            className="flex flex-wrap gap-1"
                            role="group"
                            aria-label="Kind of note"
                        >
                            {KINDS.map((k) => (
                                <button
                                    key={k.key}
                                    type="button"
                                    aria-pressed={kind === k.key}
                                    onClick={() => setKind(k.key)}
                                    title={k.hint}
                                    className={`rounded-full px-3 py-1 text-xs font-medium ${kind === k.key ? 'bg-[#16202c] text-white' : 'bg-card text-[#3d4651] shadow-[0_0_0_1px_#dfe2e5]'}`}
                                >
                                    {k.label.replace(/s$/, '')}
                                </button>
                            ))}
                        </div>
                        <TextArea
                            label={
                                KINDS.find((k) => k.key === kind)?.hint ??
                                'Note'
                            }
                            value={body}
                            onChange={setBody}
                            error={errors.body}
                            rows={3}
                        />
                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={busy || body.trim() === ''}
                            >
                                Add note
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </Panel>
    );
}

function ReviseNote({
    investigation,
    note,
}: {
    investigation: string;
    note: Note;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [body, setBody] = useState(note.body);

    return (
        <ModalButton
            label="Change wording"
            variant="outline"
            maxWidth="lg"
            title="Change the wording"
        >
            {(close) => (
                <div className="grid gap-3">
                    <p className="text-sm text-muted-foreground">
                        The earlier wording is kept and shown as replaced.
                    </p>
                    <TextArea
                        label="Note"
                        value={body}
                        onChange={setBody}
                        error={errors.body}
                        rows={5}
                    />
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={close}>
                            Back
                        </Button>
                        <Button
                            disabled={body.trim() === '' || body === note.body}
                            onClick={() =>
                                router.put(
                                    noteUpdate([investigation, note.id]).url,
                                    { body },
                                    { preserveScroll: true, onSuccess: close },
                                )
                            }
                        >
                            Save new wording
                        </Button>
                    </div>
                </div>
            )}
        </ModalButton>
    );
}

function CloseForm({ id, revision }: { id: string; revision: number }) {
    const errors = usePage().props.errors as Record<string, string>;
    const [text, setText] = useState('');

    return (
        <ModalButton
            label="Close"
            maxWidth="lg"
            title="Close the investigation"
        >
            {(close) => (
                <div className="grid gap-3">
                    <TextArea
                        label="Closure decision: what was decided, and why"
                        value={text}
                        onChange={setText}
                        error={errors.closure_decision}
                        rows={4}
                    />
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={close}>
                            Back
                        </Button>
                        <Button
                            disabled={text.trim().length < 10}
                            onClick={() =>
                                router.post(
                                    closeRoute(id).url,
                                    { closure_decision: text, revision },
                                    { preserveScroll: true, onSuccess: close },
                                )
                            }
                        >
                            Close investigation
                        </Button>
                    </div>
                </div>
            )}
        </ModalButton>
    );
}

function EditDetails({ investigation: inv, resources, members }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        title: inv.title,
        description: inv.description ?? '',
        resource_id: inv.resourceId ?? '',
        occurred_at: inv.occurredAt?.slice(0, 16) ?? '',
        period_from: inv.periodFrom ?? '',
        period_to: inv.periodTo ?? '',
        investigator_id: inv.investigatorId,
    });
    const set = (key: keyof typeof data) => (value: string) =>
        setData({ ...data, [key]: value });

    return (
        <ModalButton
            label="Edit"
            variant="outline"
            maxWidth="2xl"
            title="Incident details"
        >
            {(close) => (
                <div className="grid gap-3">
                    <TextField
                        label="Title"
                        value={data.title}
                        onChange={set('title')}
                        error={errors.title}
                    />
                    <TextArea
                        label="What happened (as reported)"
                        value={data.description}
                        onChange={set('description')}
                        rows={4}
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <SelectField
                            label="Resource"
                            value={data.resource_id}
                            onChange={set('resource_id')}
                            options={resources}
                            empty="None"
                        />
                        <SelectField
                            label="Investigator"
                            value={data.investigator_id}
                            onChange={set('investigator_id')}
                            options={members}
                        />
                        <TextField
                            label="Incident time"
                            type="datetime-local"
                            value={data.occurred_at}
                            onChange={set('occurred_at')}
                        />
                        <span />
                        <TextField
                            label="Period from"
                            type="date"
                            value={data.period_from}
                            onChange={set('period_from')}
                        />
                        <TextField
                            label="Period to"
                            type="date"
                            value={data.period_to}
                            onChange={set('period_to')}
                            error={errors.period_to}
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={close}>
                            Back
                        </Button>
                        <Button
                            disabled={data.title.trim() === ''}
                            onClick={() =>
                                router.put(
                                    update(inv.id).url,
                                    {
                                        ...data,
                                        resource_id: data.resource_id || null,
                                        occurred_at: data.occurred_at || null,
                                        period_from: data.period_from || null,
                                        period_to: data.period_to || null,
                                        revision: inv.revision,
                                    },
                                    { preserveScroll: true, onSuccess: close },
                                )
                            }
                        >
                            Save
                        </Button>
                    </div>
                </div>
            )}
        </ModalButton>
    );
}

function CorrectiveTask({ investigation: inv, members }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        title: '',
        assignee_id: '',
        due_at: '',
        priority: 'normal',
    });

    return (
        <ModalButton
            label="Give a corrective task"
            variant="outline"
            maxWidth="lg"
        >
            {(close) => (
                <div className="grid gap-3">
                    <p className="text-sm text-muted-foreground">
                        An ordinary task in {inv.logbook}, linked here as a
                        corrective action.
                    </p>
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
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={close}>
                            Back
                        </Button>
                        <Button
                            disabled={
                                data.title.trim() === '' ||
                                data.assignee_id === ''
                            }
                            onClick={() =>
                                router.post(
                                    correctiveStore(inv.id).url,
                                    { ...data, due_at: data.due_at || null },
                                    { preserveScroll: true, onSuccess: close },
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
