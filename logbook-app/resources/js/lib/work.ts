import type { Option } from '@/lib/logbook';

/**
 * Tasks, exceptions and expected entries as the server presents them
 * (app/Http/Controllers/Concerns/PresentsWork.php).
 */

export type Ref = { id: string; reference: string | null };

export type SheetRef = { id: string; name: string; code: string };

export type TaskRow = {
    id: string;
    reference: string;
    title: string;
    priority: 'low' | 'normal' | 'high' | 'urgent';
    status: string;
    overdue: boolean;
    assignee: { id: string; name: string };
    creator: string;
    logbook: string | null;
    sheet: SheetRef | null;
    resource: string | null;
    startsAt: string | null;
    dueAt: string | null;
    completedAt: string | null;
    recurring: boolean;
    verificationRequired: boolean;
    revision: number;
};

export type WorkFile = {
    id: string;
    kind: 'brief' | 'evidence';
    name: string;
    size: number;
    mime: string | null;
};

export type TaskDetail = TaskRow & {
    description: string | null;
    evidenceRequired: boolean;
    reminders: string[];
    reminderChannels: Channel[] | null;
    /** When the server received the completion (it may have been done offline earlier). */
    completionReceivedAt: string | null;
    entry: Ref | null;
    exception: { id: string; reference: string; title: string } | null;
    completion: {
        notes: string | null;
        entry: Ref | null;
        by: string | null;
        verifiedAt: string | null;
        verifiedBy: string | null;
        returnComment: string | null;
    };
    cancelled: { at: string; by: string; reason: string } | null;
    assignedAt: string | null;
    startedAt: string | null;
    reopenedAt: string | null;
    files: WorkFile[];
    can: {
        manage: boolean;
        work: boolean;
        start: boolean;
        complete: boolean;
        verify: boolean;
        reassign: boolean;
        cancel: boolean;
        reopen: boolean;
        edit: boolean;
    };
};

export type SeriesRow = {
    id: string;
    title: string;
    description: string | null;
    priority: TaskRow['priority'];
    assignee: { id: string; name: string };
    logbookId: string | null;
    logbook: string | null;
    sheetId: string | null;
    resourceId: string | null;
    frequency: 'daily' | 'weekly' | 'monthly';
    weekdays: number[];
    dayOfMonth: number | null;
    startTime: string;
    dueTime: string;
    timezone: string;
    startsOn: string;
    endsOn: string | null;
    reminders: string[];
    verificationRequired: boolean;
    evidenceRequired: boolean;
    reminderChannels: Channel[] | null;
    active: boolean;
    state: LifecycleState;
    pausedAt: string | null;
    endedAt: string | null;
    nextDueAt: string | null;
};

/** Beside in-app (always): where a reminder also goes. Null: the standard choice. */
export type Channel = 'push' | 'email';

/** A recurring task or a schedule: making new work, paused, or ended for good. */
export type LifecycleState = 'active' | 'paused' | 'ended';

export const CHANNEL_LABELS: Record<Channel, string> = {
    push: 'Phone notification',
    email: 'Email',
};

export type TaskOptions = {
    members: Option[];
    logbooks: Option[];
    sheets: (Option & { logbook: string })[];
    resources: Option[];
    exceptions: Option[];
    reminders: string[];
    timezone: string;
};

export type ExceptionRow = {
    id: string;
    reference: string;
    title: string;
    source: 'reading' | 'check' | 'manual';
    severity: 'low' | 'medium' | 'high' | 'critical';
    status: string;
    overdue: boolean;
    logbook: string | null;
    sheet: SheetRef | null;
    assignee: { id: string; name: string } | null;
    dueAt: string | null;
    createdAt: string | null;
    resolvedAt: string | null;
    revision: number;
};

export type OccurrenceRow = {
    id: string;
    state: string;
    occursOn: string;
    opensAt: string;
    dueAt: string;
    closesAt: string;
    sheet: SheetRef;
    logbook: string;
    responsible: string | null;
    entry: Ref | null;
    submittedAt: string | null;
    reviewedAt: string | null;
    /** When the entry came in, apart from what the review says. */
    timeliness: 'on_time' | 'late' | null;
    excuseReason: string | null;
    /** ADR-0031: the asset this reading is for, if any. */
    resourceId?: string | null;
    resource?: string | null;
};

export type ScheduleRow = {
    id: string;
    sheet: SheetRef;
    frequency: 'daily' | 'weekly' | 'monthly';
    weekdays: number[];
    dayOfMonth: number | null;
    opensTime: string;
    dueTime: string;
    /** ADR-0030: every reading of the day (the first is opensTime/dueTime). */
    times: { opens: string; due: string }[];
    lateMinutes: number;
    timezone: string;
    startsOn: string;
    endsOn: string | null;
    responsible: { id: string; name: string } | null;
    /** ADR-0031: the asset this schedule expects readings for. */
    resource?: { id: string; label: string } | null;
    completion: 'submitted' | 'reviewed';
    reminders: string[];
    reminderChannels: Channel[] | null;
    active: boolean;
    state: LifecycleState;
    pausedAt: string | null;
    endedAt: string | null;
};

export type HistoryEvent = {
    id: string;
    action: string;
    user: string | null;
    at: string;
    details: Record<string, unknown> | null;
};

export type NotificationRow = {
    id: string;
    category: 'task' | 'entry' | 'schedule' | 'exception';
    event: string;
    title: string;
    body: string | null;
    link: string;
    read: boolean;
    at: string;
};

export const PRIORITIES: Option[] = [
    { value: 'low', label: 'Low' },
    { value: 'normal', label: 'Normal' },
    { value: 'high', label: 'High' },
    { value: 'urgent', label: 'Urgent' },
];

export const SEVERITIES: Option[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

export const WEEKDAYS: Option[] = [
    { value: '1', label: 'Mon' },
    { value: '2', label: 'Tue' },
    { value: '3', label: 'Wed' },
    { value: '4', label: 'Thu' },
    { value: '5', label: 'Fri' },
    { value: '6', label: 'Sat' },
    { value: '7', label: 'Sun' },
];

/** A reminder token as people read it: before:60 → "1 hour before". */
export function reminderLabel(token: string): string {
    if (token === 'start') return 'When it starts';
    if (token === 'due') return 'When it is due';
    const [kind, value] = token.split(':', 2);
    if (kind === 'at') return `At ${value.replace('T', ' ').slice(0, 16)}`;
    const minutes = Number(value);
    const span =
        minutes === 0
            ? ''
            : minutes % 1440 === 0
              ? `${minutes / 1440} day${minutes === 1440 ? '' : 's'}`
              : minutes % 60 === 0
                ? `${minutes / 60} hour${minutes === 60 ? '' : 's'}`
                : `${minutes} minutes`;
    if (kind === 'before') return `${span} before`;
    if (kind === 'after')
        return minutes === 0 ? 'When overdue' : `${span} overdue`;

    return token;
}

/** "Daily", "Mon, Thu", "Day 15 of each month". */
export function recurrenceLabel(
    frequency: string,
    weekdays: number[],
    dayOfMonth: number | null,
): string {
    if (frequency === 'weekly') {
        return weekdays.length === 0
            ? 'Weekly'
            : weekdays.map((d) => WEEKDAYS[d - 1]?.label ?? d).join(', ');
    }
    if (frequency === 'monthly') {
        return `Day ${dayOfMonth ?? 1} of each month`;
    }

    return 'Daily';
}

/** A datetime-local value (workspace time) from an ISO moment. */
export function localInput(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
