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
    | 'user';

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
};

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
