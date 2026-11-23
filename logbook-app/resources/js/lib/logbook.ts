import type { Tone } from '@miles/ui/components/status-badge';

export type Option = { value: string; label: string };

export type FieldType =
    | 'text'
    | 'textarea'
    | 'integer'
    | 'decimal'
    | 'date'
    | 'time'
    | 'datetime'
    | 'select_one'
    | 'select_multiple'
    | 'yes_no'
    | 'file'
    | 'user'
    | 'resource';

/** A template field, as app/Logbook/Fields.php keeps it. */
export type FieldDef = {
    name: string;
    label: string;
    type: FieldType;
    required: boolean;
    help?: string;
    placeholder?: string;
    default?: string | number | boolean;
    choices?: { value: string; label: string }[];
    min?: string | number;
    max?: string | number;
    /** The normal range: a reading outside it is recorded and flagged. */
    expected_min?: string | number;
    expected_max?: string | number;
    unit?: string;
    max_length?: number;
    /** Shown only when an earlier yes/no or choice field has one of these answers. */
    show_if?: { field: string; in: string[] };
    /** A reading outside the normal range opens an exception. */
    raise_exception?: boolean;
    /** Answers that open an exception (yes/no or single choice). */
    exception_values?: string[];
    exception_severity?: 'low' | 'medium' | 'high' | 'critical';
    /** Resource fields: only resources of this type. */
    resource_type?: string;
    /** ADR-0031: the field naming the entry's asset, and the assets linked to the sheet. */
    subject?: boolean;
    resource_ids?: string[];
    /** Readings that add up (litres, hours); levels such as temperatures do not. */
    summable?: boolean;
};

/** A resource as pickers show it. */
export type ResourceOption = Option & { type: string };

/** Field types a later field's condition can name. */
export const CONDITIONAL_TYPES: FieldType[] = [
    'yes_no',
    'select_one',
    'select_multiple',
];

/**
 * Whether a field shows, given the answers so far: the same rule as
 * Fields::visible() on the server (a hidden field is cleared on save).
 */
export function fieldVisible(
    field: FieldDef,
    values: Record<string, unknown>,
    fields: FieldDef[],
): boolean {
    const condition = field.show_if;
    if (!condition) {
        return true;
    }
    const parent = fields.find((f) => f.name === condition.field);
    if (parent && !fieldVisible(parent, values, fields)) {
        return false;
    }
    const answer = values[condition.field];
    if (typeof answer === 'boolean') {
        return condition.in.includes(answer ? 'yes' : 'no');
    }
    if (Array.isArray(answer)) {
        return answer.some((a) => condition.in.includes(String(a)));
    }
    if (typeof answer === 'string' || typeof answer === 'number') {
        return condition.in.includes(String(answer));
    }

    return false;
}

export type FileRef = {
    id: string;
    name: string;
    size: number;
    mime: string | null;
    removed: boolean;
};

export type Answer = {
    name: string;
    label: string;
    type: FieldType;
    text: string | null;
    unit: string | null;
    /** Set when the reading is outside the field's normal range. */
    outOfRange: { direction: 'low' | 'high'; range: string } | null;
    files: FileRef[];
};

export type EntryRow = {
    id: string;
    reference: string | null;
    status: string;
    reviewRequired: boolean;
    occurredAt: string | null;
    submittedAt: string | null;
    logbook: string;
    sheet: { id: string; name: string; code: string };
    creator: string;
    summary: string;
    /** How many readings are outside their normal range. */
    outOfRange: number;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type WritableSheet = {
    id: string;
    name: string;
    code: string;
    logbook: string;
};

const STATUS: Record<string, { label: string; tone: Tone }> = {
    draft: { label: 'Draft', tone: 'neutral' },
    submitted: { label: 'Awaiting review', tone: 'info' },
    returned: { label: 'Returned', tone: 'warning' },
    reviewed: { label: 'Reviewed', tone: 'success' },
    finalized: { label: 'Recorded', tone: 'success' },
    active: { label: 'Active', tone: 'success' },
    archived: { label: 'Archived', tone: 'neutral' },
    published: { label: 'Published', tone: 'success' },
    // Tasks
    pending: { label: 'To do', tone: 'neutral' },
    in_progress: { label: 'In progress', tone: 'info' },
    awaiting_verification: { label: 'Awaiting verification', tone: 'info' },
    completed: { label: 'Completed', tone: 'success' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
    overdue: { label: 'Overdue', tone: 'danger' },
    // Exceptions
    open: { label: 'Open', tone: 'warning' },
    resolved: { label: 'Resolved', tone: 'info' },
    closed: { label: 'Closed', tone: 'success' },
    low: { label: 'Low', tone: 'neutral' },
    normal: { label: 'Normal', tone: 'neutral' },
    medium: { label: 'Medium', tone: 'warning' },
    high: { label: 'High', tone: 'danger' },
    urgent: { label: 'Urgent', tone: 'danger' },
    critical: { label: 'Critical', tone: 'danger' },
    // Expected entries
    upcoming: { label: 'Upcoming', tone: 'neutral' },
    due: { label: 'Due now', tone: 'info' },
    awaiting_review: { label: 'Awaiting review', tone: 'info' },
    late: { label: 'Late', tone: 'warning' },
    missed: { label: 'Missed', tone: 'danger' },
    excused: { label: 'Excused', tone: 'neutral' },
    inactive: { label: 'Inactive', tone: 'neutral' },
    retired: { label: 'Retired', tone: 'warning' },
    // Recurring tasks and schedules
    paused: { label: 'Paused', tone: 'warning' },
    recording: { label: 'Recording', tone: 'info' },
    ended: { label: 'Ended', tone: 'neutral' },
    on_time: { label: 'On time', tone: 'success' },
};

export function status(key: string): { label: string; tone: Tone } {
    return STATUS[key] ?? { label: key.replace(/_/g, ' '), tone: 'neutral' };
}

export const ENTRY_STATUSES = [
    'draft',
    'submitted',
    'returned',
    'reviewed',
    'finalized',
];

export const ROLE_LABELS: Record<string, string> = {
    contributor: 'Contributor',
    reviewer: 'Reviewer',
    viewer: 'Viewer',
};

const ACTIONS: Record<string, string> = {
    'logbook.created': 'created the logbook',
    'logbook.updated': 'changed the logbook',
    'logbook.access_changed': 'changed who reaches the logbook',
    'logbook.access_granted': 'gave access',
    'logbook.access_revoked': 'removed access',
    'logbook.archived': 'archived the logbook',
    'logbook.restored': 'restored the logbook',
    'sheet.created': 'created the log sheet',
    'sheet.updated': 'changed the sheet settings',
    'sheet.archived': 'archived the log sheet',
    'sheet.restored': 'restored the log sheet',
    'template.draft_opened': 'opened a template draft',
    'template.draft_discarded': 'discarded the template draft',
    'template.published': 'published a template version',
    'entry.created': 'started the entry',
    'entry.submitted': 'submitted the entry',
    'entry.resubmitted': 'resubmitted the corrected entry',
    'entry.reviewed': 'marked the entry reviewed',
    'entry.returned': 'returned the entry for correction',
    'entry.discarded': 'discarded the draft',
    'task.created': 'gave the task',
    'task.updated': 'changed the task',
    'task.reassigned': 'reassigned the task',
    'task.started': 'started the task',
    'task.completed': 'completed the task',
    'task.submitted': 'reported the work for verification',
    'task.verified': 'verified the work',
    'task.returned': 'returned the work',
    'task.cancelled': 'cancelled the task',
    'task.reopened': 'reopened the task',
    'task.file_added': 'added a file',
    'task.file_removed': 'removed a file',
    'task_series.created': 'set up the recurring task',
    'task_series.updated': 'changed the recurring task',
    'exception.raised': 'raised the exception',
    'exception.assigned': 'assigned the exception',
    'exception.started': 'started work on the exception',
    'exception.resolved': 'resolved the exception',
    'exception.closed': 'closed the exception',
    'exception.reopened': 'reopened the exception',
    'exception.file_added': 'added evidence',
    'exception.file_removed': 'removed evidence',
    'schedule.created': 'scheduled the log sheet',
    'schedule.updated': 'changed the schedule',
    'occurrence.excused': 'excused an expected entry',
    'occurrence.missed': 'an expected entry was missed',
    'resource.created': 'added the resource',
    'resource.updated': 'changed the resource',
    'location.created': 'added the location',
    'location.updated': 'changed the location',
};

export function action(key: string): string {
    return ACTIONS[key] ?? key;
}

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(
        value.length === 10 ? `${value}T00:00:00` : value,
    ).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function fileSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** A stable identifier from a label, as Fields::nameFor does on the server. */
export function nameFor(label: string): string {
    const name = label
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 48);

    return /^[a-z]/.test(name) ? name : `f_${name}`.slice(0, 48);
}
