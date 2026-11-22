import { Link, router, usePage } from '@inertiajs/react';
import { Paperclip, Repeat, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { ActionButton } from '@/components/actions';
import { ModalButton } from '@/components/dialogs';
import {
    Check,
    Errors,
    Priority,
    SelectField,
    Status,
    TextArea,
    TextField,
} from '@/components/fields';
import { action, fileSize, formatDateTime } from '@/lib/logbook';
import type {
    Channel,
    HistoryEvent,
    LifecycleState,
    SeriesRow,
    TaskOptions,
    TaskRow,
    WorkFile,
} from '@/lib/work';
import {
    CHANNEL_LABELS,
    localInput,
    PRIORITIES,
    recurrenceLabel,
    reminderLabel,
    WEEKDAYS,
} from '@/lib/work';
import { show as showTask, store, update } from '@/routes/tasks';
import * as series from '@/routes/task-series';
import { show as download } from '@/routes/work-files';

type Errs = Record<string, string>;

/** A row of tabs as links (server-side filters). */
export function Tabs({
    items,
}: {
    items: { href: string; label: string; active: boolean }[];
}) {
    return (
        <nav className="mb-5 flex flex-wrap gap-6 border-b">
            {items.map((item) => (
                <Link
                    key={item.label}
                    href={item.href}
                    className={`-mb-px border-b-2 px-1 pb-2.5 text-sm font-medium transition-colors ${
                        item.active
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:border-[#c9d2dc] hover:text-foreground'
                    }`}
                    aria-current={item.active ? 'page' : undefined}
                >
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}

/** When something is due, in words and in colour. */
export function Due({ at, overdue }: { at: string | null; overdue: boolean }) {
    if (!at) {
        return <span className="text-muted-foreground">No due date</span>;
    }

    return (
        <span className={overdue ? 'font-medium text-destructive' : ''}>
            {formatDateTime(at)}
            {overdue && ' · overdue'}
        </span>
    );
}

export function TaskTable({
    tasks,
    showAssignee = true,
}: {
    tasks: TaskRow[];
    showAssignee?: boolean;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="border-b text-left text-xs">
                    <tr>
                        <th className="px-4 py-2 font-medium">Task</th>
                        <th className="px-4 py-2 font-medium">Logbook</th>
                        <th className="px-4 py-2 font-medium">Due</th>
                        <th className="px-4 py-2 font-medium">Priority</th>
                        <th className="px-4 py-2 font-medium">Status</th>
                        {showAssignee && (
                            <th className="px-4 py-2 font-medium">Assignee</th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {tasks.map((t) => (
                        <tr key={t.id} className="hover:bg-muted">
                            <td className="px-4 py-2">
                                <Link
                                    href={showTask(t.id)}
                                    className="font-medium hover:underline"
                                >
                                    {t.title}
                                </Link>{' '}
                                {t.recurring && (
                                    <Repeat
                                        className="inline size-3 text-muted-foreground"
                                        aria-label="Recurring"
                                    />
                                )}
                                <span className="block text-xs text-muted-foreground">
                                    {[t.reference, t.sheet?.code, t.resource]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>
                            </td>
                            <td className="px-4 py-2">{t.logbook ?? '—'}</td>
                            <td
                                className={`px-4 py-2 whitespace-nowrap ${
                                    t.overdue
                                        ? 'font-medium text-destructive'
                                        : ''
                                }`}
                            >
                                {t.dueAt ? (
                                    formatDateTime(t.dueAt)
                                ) : (
                                    <span className="text-muted-foreground">
                                        No due date
                                    </span>
                                )}
                            </td>
                            <td className="px-4 py-2">
                                <Priority value={t.priority} />
                            </td>
                            <td className="px-4 py-2">
                                <Status
                                    value={
                                        t.overdue &&
                                        t.status !== 'awaiting_verification'
                                            ? 'overdue'
                                            : t.status
                                    }
                                />
                            </td>
                            {showAssignee && (
                                <td className="px-4 py-2 whitespace-nowrap">
                                    {t.assignee.name}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** Reminder tokens chosen from the presets. */
export function ReminderPicker({
    value,
    presets,
    onChange,
    error,
}: {
    value: string[];
    presets: string[];
    onChange: (value: string[]) => void;
    error?: string;
}) {
    return (
        <fieldset className="grid gap-1.5 sm:col-span-2">
            <legend className="mb-1 text-sm font-medium">Reminders</legend>
            <div className="flex flex-wrap gap-x-4 gap-y-2">
                {presets.map((token) => (
                    <Check
                        key={token}
                        label={reminderLabel(token)}
                        checked={value.includes(token)}
                        onChange={(on) =>
                            onChange(
                                on
                                    ? [...value, token]
                                    : value.filter((t) => t !== token),
                            )
                        }
                    />
                ))}
            </div>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </fieldset>
    );
}

/**
 * Where reminders go beside the bell. Unticked both: in-app only. The
 * standard (null) is a phone notification for every reminder and an email
 * once the work is overdue.
 */
export function ChannelPicker({
    value,
    onChange,
    error,
}: {
    value: Channel[] | null;
    onChange: (value: Channel[] | null) => void;
    error?: string;
}) {
    const custom = value !== null;

    return (
        <fieldset className="grid gap-1.5 sm:col-span-2">
            <legend className="mb-1 text-sm font-medium">
                Send reminders by
            </legend>
            <div className="flex flex-wrap gap-x-4 gap-y-2">
                <Check
                    label="Standard: phone, and email once overdue"
                    checked={!custom}
                    onChange={(on) => onChange(on ? null : ['push'])}
                />
                {custom &&
                    (Object.keys(CHANNEL_LABELS) as Channel[]).map((c) => (
                        <Check
                            key={c}
                            label={CHANNEL_LABELS[c]}
                            checked={value.includes(c)}
                            onChange={(on) =>
                                onChange(
                                    on
                                        ? [...value, c]
                                        : value.filter((v) => v !== c),
                                )
                            }
                        />
                    ))}
            </div>
            <p className="text-xs text-muted-foreground">
                Reminders always appear under the bell. Each person can still
                turn phone or email off for themselves.
            </p>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </fieldset>
    );
}

/** Pause, resume and end, for a recurring task or a schedule. */
export function LifecycleActions({
    state,
    urls,
    what,
}: {
    state: LifecycleState;
    urls: { pause: string; resume: string; end: string };
    what: string;
}) {
    if (state === 'ended') {
        return null;
    }

    return (
        <>
            {state === 'active' ? (
                <ActionButton
                    url={urls.pause}
                    label="Pause"
                    variant="outline"
                />
            ) : (
                <ActionButton
                    url={urls.resume}
                    label="Resume"
                    variant="outline"
                />
            )}
            <ActionButton
                url={urls.end}
                label="End"
                variant="outline"
                confirm={`End this ${what} for good? What it already made stays in the history.`}
            />
        </>
    );
}

export type TaskInitial = {
    id?: string;
    revision?: number;
    title?: string;
    description?: string | null;
    priority?: string;
    assignee_id?: string;
    logbook_id?: string;
    log_sheet_id?: string;
    log_entry_id?: string;
    exception_id?: string;
    resource_id?: string;
    starts_at?: string | null;
    due_at?: string | null;
    reminders?: string[];
    reminder_channels?: Channel[] | null;
    verification_required?: boolean;
    evidence_required?: boolean;
};

/** Gives a task, or changes one (not who does it: reassign does that). */
export function TaskForm({
    options,
    initial = {},
    onDone,
}: {
    options: TaskOptions;
    initial?: TaskInitial;
    onDone?: () => void;
}) {
    const errors = usePage().props.errors as Errs;
    const editing = initial.id !== undefined;
    const [data, setData] = useState({
        title: initial.title ?? '',
        description: initial.description ?? '',
        priority: initial.priority ?? 'normal',
        assignee_id: initial.assignee_id ?? '',
        logbook_id: initial.logbook_id ?? '',
        log_sheet_id: initial.log_sheet_id ?? '',
        log_entry_id: initial.log_entry_id ?? '',
        exception_id: initial.exception_id ?? '',
        resource_id: initial.resource_id ?? '',
        starts_at: localInput(initial.starts_at ?? null),
        due_at: localInput(initial.due_at ?? null),
        reminders: initial.reminders ?? ['before:60', 'after:0'],
        reminder_channels: (initial.reminder_channels ?? null) as
            | Channel[]
            | null,
        verification_required: initial.verification_required ?? false,
        evidence_required: initial.evidence_required ?? false,
    });
    const [busy, setBusy] = useState(false);
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });
    const sheets = options.sheets.filter(
        (s) => data.logbook_id === '' || s.logbook === data.logbook_id,
    );

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                setBusy(true);
                const payload: Record<string, unknown> = {
                    ...data,
                    revision: initial.revision,
                };
                for (const key of Object.keys(payload)) {
                    if (payload[key] === '') payload[key] = null;
                }
                if (editing) delete payload.assignee_id;
                router.visit(editing ? update(initial.id!).url : store().url, {
                    method: editing ? 'put' : 'post',
                    data: payload as never,
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                    onFinish: () => setBusy(false),
                });
            }}
        >
            <TextField
                label="Title"
                value={data.title}
                onChange={(title) => set({ title })}
                error={errors.title}
                placeholder="e.g. Service generator 2"
                className="sm:col-span-2"
                required
            />
            <TextArea
                label="What to do"
                value={data.description}
                onChange={(description) => set({ description })}
                error={errors.description}
                className="sm:col-span-2"
            />
            {!editing && (
                <SelectField
                    label="Assign to"
                    value={data.assignee_id}
                    onChange={(assignee_id) => set({ assignee_id })}
                    options={options.members}
                    empty="Choose a person…"
                    error={errors.assignee_id}
                />
            )}
            <SelectField
                label="Priority"
                value={data.priority}
                onChange={(priority) => set({ priority })}
                options={PRIORITIES}
                error={errors.priority}
            />
            <SelectField
                label="Logbook"
                value={data.logbook_id}
                onChange={(logbook_id) => set({ logbook_id, log_sheet_id: '' })}
                options={options.logbooks}
                empty="None"
                error={errors.logbook_id}
                hint="Only people who reach this logbook can be given the task."
            />
            <SelectField
                label="Log sheet"
                value={data.log_sheet_id}
                onChange={(log_sheet_id) => set({ log_sheet_id })}
                options={sheets}
                empty="None"
                error={errors.log_sheet_id}
            />
            <SelectField
                label="Resource"
                value={data.resource_id}
                onChange={(resource_id) => set({ resource_id })}
                options={options.resources}
                empty="None"
                error={errors.resource_id}
            />
            <SelectField
                label="Corrective action for"
                value={data.exception_id}
                onChange={(exception_id) => set({ exception_id })}
                options={options.exceptions}
                empty="No exception"
                error={errors.exception_id}
            />
            <TextField
                label={`Starts (${options.timezone})`}
                type="datetime-local"
                value={data.starts_at}
                onChange={(starts_at) => set({ starts_at })}
                error={errors.starts_at}
            />
            <TextField
                label={`Due (${options.timezone})`}
                type="datetime-local"
                value={data.due_at}
                onChange={(due_at) => set({ due_at })}
                error={errors.due_at}
            />
            <ReminderPicker
                value={data.reminders}
                presets={options.reminders}
                onChange={(reminders) => set({ reminders })}
                error={
                    errors.reminders ??
                    Object.entries(errors).find(([k]) =>
                        k.startsWith('reminders.'),
                    )?.[1]
                }
            />
            <ChannelPicker
                value={data.reminder_channels}
                onChange={(reminder_channels) => set({ reminder_channels })}
                error={
                    errors.reminder_channels ??
                    Object.entries(errors).find(([k]) =>
                        k.startsWith('reminder_channels.'),
                    )?.[1]
                }
            />
            <div className="grid gap-2 sm:col-span-2">
                <Check
                    label="Someone else verifies the work before it is complete"
                    checked={data.verification_required}
                    onChange={(verification_required) =>
                        set({ verification_required })
                    }
                />
                <Check
                    label="Evidence (a photo or a document) is required to complete"
                    checked={data.evidence_required}
                    onChange={(evidence_required) => set({ evidence_required })}
                />
            </div>
            <div className="sm:col-span-2">
                <Errors
                    errors={Object.fromEntries(
                        Object.entries(errors).filter(([k]) =>
                            ['task', 'stale'].includes(k),
                        ),
                    )}
                />
                <Button type="submit" disabled={busy} className="mt-2">
                    {editing ? 'Save task' : 'Give task'}
                </Button>
            </div>
        </form>
    );
}

/** Sets up or changes a recurring task. */
export function SeriesForm({
    options,
    initial,
    onDone,
}: {
    options: TaskOptions;
    initial?: SeriesRow;
    onDone?: () => void;
}) {
    const errors = usePage().props.errors as Errs;
    const [data, setData] = useState({
        title: initial?.title ?? '',
        description: initial?.description ?? '',
        priority: initial?.priority ?? 'normal',
        assignee_id: initial?.assignee.id ?? '',
        logbook_id: initial?.logbookId ?? '',
        log_sheet_id: initial?.sheetId ?? '',
        resource_id: initial?.resourceId ?? '',
        frequency: initial?.frequency ?? 'daily',
        weekdays: initial?.weekdays ?? [1],
        day_of_month: String(initial?.dayOfMonth ?? 1),
        start_time: initial?.startTime ?? '08:00',
        due_time: initial?.dueTime ?? '17:00',
        timezone: initial?.timezone ?? options.timezone,
        starts_on: initial?.startsOn ?? new Date().toISOString().slice(0, 10),
        ends_on: initial?.endsOn ?? '',
        reminders: initial?.reminders ?? ['due', 'after:0'],
        reminder_channels: (initial?.reminderChannels ?? null) as
            | Channel[]
            | null,
        verification_required: initial?.verificationRequired ?? false,
        evidence_required: initial?.evidenceRequired ?? false,
    });
    const [busy, setBusy] = useState(false);
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                setBusy(true);
                const payload: Record<string, unknown> = { ...data };
                for (const key of Object.keys(payload)) {
                    if (payload[key] === '') payload[key] = null;
                }
                router.visit(
                    initial
                        ? series.update(initial.id).url
                        : series.store().url,
                    {
                        method: initial ? 'put' : 'post',
                        data: payload as never,
                        preserveScroll: true,
                        onSuccess: () => onDone?.(),
                        onFinish: () => setBusy(false),
                    },
                );
            }}
        >
            <TextField
                label="Title"
                value={data.title}
                onChange={(title) => set({ title })}
                error={errors.title}
                className="sm:col-span-2"
                placeholder="e.g. Daily fire panel check"
                required
            />
            <TextArea
                label="What to do"
                value={data.description}
                onChange={(description) => set({ description })}
                className="sm:col-span-2"
            />
            <SelectField
                label="Assign to"
                value={data.assignee_id}
                onChange={(assignee_id) => set({ assignee_id })}
                options={options.members}
                empty="Choose a person…"
                error={errors.assignee_id}
            />
            <SelectField
                label="Logbook"
                value={data.logbook_id}
                onChange={(logbook_id) => set({ logbook_id, log_sheet_id: '' })}
                options={options.logbooks}
                empty="None"
                error={errors.logbook_id}
            />
            <SelectField
                label="Repeats"
                value={data.frequency}
                onChange={(frequency) =>
                    set({ frequency: frequency as typeof data.frequency })
                }
                options={[
                    { value: 'daily', label: 'Every day' },
                    { value: 'weekly', label: 'Every week' },
                    { value: 'monthly', label: 'Every month' },
                ]}
                error={errors.frequency}
            />
            {data.frequency === 'weekly' && (
                <WeekdayPicker
                    value={data.weekdays}
                    onChange={(weekdays) => set({ weekdays })}
                    error={errors.weekdays}
                />
            )}
            {data.frequency === 'monthly' && (
                <TextField
                    label="Day of the month"
                    value={data.day_of_month}
                    onChange={(day_of_month) => set({ day_of_month })}
                    inputMode="numeric"
                    error={errors.day_of_month}
                    hint="31 means the last day in shorter months."
                />
            )}
            <TextField
                label="Starts at"
                type="time"
                value={data.start_time}
                onChange={(start_time) => set({ start_time })}
                error={errors.start_time}
            />
            <TextField
                label="Due at"
                type="time"
                value={data.due_time}
                onChange={(due_time) => set({ due_time })}
                error={errors.due_time}
            />
            <TextField
                label="First day"
                type="date"
                value={data.starts_on}
                onChange={(starts_on) => set({ starts_on })}
                error={errors.starts_on}
            />
            <TextField
                label="Last day (optional)"
                type="date"
                value={data.ends_on}
                onChange={(ends_on) => set({ ends_on })}
                error={errors.ends_on}
            />
            <TextField
                label="Time zone"
                value={data.timezone}
                onChange={(timezone) => set({ timezone })}
                error={errors.timezone}
            />
            <ReminderPicker
                value={data.reminders}
                presets={options.reminders.filter((t) => !t.startsWith('at:'))}
                onChange={(reminders) => set({ reminders })}
                error={errors.reminders}
            />
            <ChannelPicker
                value={data.reminder_channels}
                onChange={(reminder_channels) => set({ reminder_channels })}
                error={errors.reminder_channels}
            />
            <div className="grid gap-2 sm:col-span-2">
                <Check
                    label="Someone else verifies each one"
                    checked={data.verification_required}
                    onChange={(verification_required) =>
                        set({ verification_required })
                    }
                />
                <Check
                    label="Evidence is required"
                    checked={data.evidence_required}
                    onChange={(evidence_required) => set({ evidence_required })}
                />
                {initial && (
                    <p className="text-xs text-muted-foreground">
                        Changes apply to tasks not made yet. Tasks already made
                        keep their person and times.
                    </p>
                )}
            </div>
            <div className="sm:col-span-2">
                <Button type="submit" disabled={busy}>
                    {initial ? 'Save recurring task' : 'Set up recurring task'}
                </Button>
            </div>
        </form>
    );
}

export function WeekdayPicker({
    value,
    onChange,
    error,
}: {
    value: number[];
    onChange: (value: number[]) => void;
    error?: string;
}) {
    return (
        <fieldset className="grid gap-1.5">
            <legend className="mb-1 text-sm font-medium">On</legend>
            <div className="flex flex-wrap gap-1">
                {WEEKDAYS.map((d) => {
                    const day = Number(d.value);
                    const on = value.includes(day);

                    return (
                        <button
                            key={d.value}
                            type="button"
                            aria-pressed={on}
                            onClick={() =>
                                onChange(
                                    on
                                        ? value.filter((v) => v !== day)
                                        : [...value, day].sort((a, b) => a - b),
                                )
                            }
                            className={`rounded-md border px-2 py-1 text-xs font-medium ${
                                on
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-muted'
                            }`}
                        >
                            {d.label}
                        </button>
                    );
                })}
            </div>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </fieldset>
    );
}

export function SeriesList({
    items,
    options,
}: {
    items: SeriesRow[];
    options: TaskOptions;
}) {
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <ul className="divide-y">
            {items.map((s) => (
                <li key={s.id} className="px-4 py-3 text-sm">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p className="font-medium">{s.title}</p>
                            <p className="text-muted-foreground">
                                {recurrenceLabel(
                                    s.frequency,
                                    s.weekdays,
                                    s.dayOfMonth,
                                )}{' '}
                                · {s.startTime}–{s.dueTime} ({s.timezone}) ·{' '}
                                {s.assignee.name}
                                {s.logbook && ` · ${s.logbook}`}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {s.state === 'active' && s.nextDueAt
                                    ? `Next due ${formatDateTime(s.nextDueAt)}`
                                    : s.state === 'paused' && s.pausedAt
                                      ? `Paused ${formatDateTime(s.pausedAt)}: no tasks are made until resumed`
                                      : s.state === 'ended'
                                        ? `Ended${s.endedAt ? ` ${formatDateTime(s.endedAt)}` : ''}`
                                        : null}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Status value={s.state} />
                            <LifecycleActions
                                state={s.state}
                                what="recurring task"
                                urls={{
                                    pause: series.pause(s.id).url,
                                    resume: series.resume(s.id).url,
                                    end: series.end(s.id).url,
                                }}
                            />
                            {s.state !== 'ended' && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setEditing(
                                            editing === s.id ? null : s.id,
                                        )
                                    }
                                >
                                    {editing === s.id ? 'Close' : 'Change'}
                                </Button>
                            )}
                        </div>
                    </div>
                    {editing === s.id && (
                        <div className="mt-3 rounded-md border p-4">
                            <SeriesForm
                                options={options}
                                initial={s}
                                onDone={() => setEditing(null)}
                            />
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

/** Files on a task or exception: download, upload, remove. */
export function WorkFiles({
    files,
    uploadUrl,
    removeUrl,
    kind,
    canAdd,
    canRemove,
    label,
}: {
    files: WorkFile[];
    uploadUrl: string;
    removeUrl: (id: string) => string;
    kind: 'brief' | 'evidence';
    canAdd: boolean;
    canRemove: boolean;
    label: string;
}) {
    const errors = usePage().props.errors as Errs;
    const [busy, setBusy] = useState(false);
    const mine = files.filter((f) => f.kind === kind);
    if (mine.length === 0 && !canAdd) {
        return null;
    }

    return (
        <div className="grid gap-2">
            <p className="text-sm font-medium">{label}</p>
            {mine.length > 0 && (
                <ul className="grid gap-1">
                    {mine.map((f) => (
                        <li
                            key={f.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Paperclip className="size-3.5 text-muted-foreground" />
                            <a
                                href={download(f.id).url}
                                className="hover:underline"
                            >
                                {f.name}
                            </a>
                            <span className="text-xs text-muted-foreground">
                                {fileSize(f.size)}
                            </span>
                            {canRemove && (
                                <button
                                    type="button"
                                    aria-label={`Remove ${f.name}`}
                                    className="text-muted-foreground hover:text-destructive"
                                    onClick={() =>
                                        router.delete(removeUrl(f.id), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <Trash2 className="size-3.5" />
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {canAdd && (
                <label className="text-sm">
                    <span className="sr-only">Add a file</span>
                    <input
                        type="file"
                        disabled={busy}
                        className="text-sm"
                        onChange={(e) => {
                            const file = e.target.files?.[0];
                            if (!file) return;
                            setBusy(true);
                            router.post(
                                uploadUrl,
                                { kind, file },
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onFinish: () => {
                                        setBusy(false);
                                        e.target.value = '';
                                    },
                                },
                            );
                        }}
                    />
                </label>
            )}
            {errors.file && (
                <p className="text-xs text-destructive">{errors.file}</p>
            )}
        </div>
    );
}

/** A record's audit events, oldest first. */
export function History({ events }: { events: HistoryEvent[] }) {
    if (events.length === 0) {
        return (
            <p className="px-4 py-3 text-sm text-muted-foreground">
                Nothing recorded yet.
            </p>
        );
    }

    return (
        <ol className="divide-y">
            {events.map((e) => (
                <li key={e.id} className="px-4 py-2 text-sm">
                    <span className="font-medium">{e.user ?? 'Logbook'}</span>{' '}
                    {action(e.action)}
                    {typeof e.details?.reason === 'string' &&
                        `: ${e.details.reason}`}
                    {typeof e.details?.comment === 'string' &&
                        `: ${e.details.comment}`}
                    <span className="block text-xs text-muted-foreground">
                        {formatDateTime(e.at)}
                    </span>
                </li>
            ))}
        </ol>
    );
}

/** A toggled panel body: a button that opens a form. */
/** Opens a form in a modal (Inertia UI Modal); `children` gets `close`. */
export function Toggle({
    label,
    children,
    variant = 'default',
}: {
    label: string;
    children: (close: () => void) => ReactNode;
    variant?: 'default' | 'outline';
}) {
    return (
        <ModalButton label={label} variant={variant}>
            {children}
        </ModalButton>
    );
}
