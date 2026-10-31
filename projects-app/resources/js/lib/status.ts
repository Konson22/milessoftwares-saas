import type { Tone } from '@miles/ui/components/status-badge';

/** Statuses of projects, tasks and risks, project health and task priority: label and look. */
const STATUSES: Record<string, { label: string; tone: Tone }> = {
    // Projects.
    planning: { label: 'Planning', tone: 'neutral' },
    active: { label: 'Active', tone: 'info' },
    on_hold: { label: 'On hold', tone: 'warning' },
    completed: { label: 'Completed', tone: 'success' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
    // Tasks.
    todo: { label: 'To do', tone: 'neutral' },
    in_progress: { label: 'In progress', tone: 'info' },
    review: { label: 'In review', tone: 'warning' },
    done: { label: 'Done', tone: 'success' },
    // Risks.
    open: { label: 'Open', tone: 'danger' },
    mitigating: { label: 'Mitigating', tone: 'warning' },
    closed: { label: 'Closed', tone: 'neutral' },
    // Health.
    on_track: { label: 'On track', tone: 'success' },
    at_risk: { label: 'At risk', tone: 'warning' },
    off_track: { label: 'Off track', tone: 'danger' },
};

const PRIORITIES: Record<string, { label: string; tone: Tone }> = {
    low: { label: 'Low', tone: 'neutral' },
    medium: { label: 'Medium', tone: 'info' },
    high: { label: 'High', tone: 'warning' },
    urgent: { label: 'Urgent', tone: 'danger' },
};

export const TASK_STATUSES = ['todo', 'in_progress', 'review', 'done'] as const;

export const PROJECT_STATUSES = [
    'planning',
    'active',
    'on_hold',
    'completed',
    'cancelled',
] as const;

export function statusTone(status: string): Tone {
    return STATUSES[status]?.tone ?? 'neutral';
}

export function statusLabel(status: string): string {
    return STATUSES[status]?.label ?? status;
}

export function priorityTone(priority: string): Tone {
    return PRIORITIES[priority]?.tone ?? 'neutral';
}

export function priorityLabel(priority: string): string {
    return PRIORITIES[priority]?.label ?? priority;
}

/** "2026-10-26" → "26 Oct 2026", in the reader's locale. */
export function formatDate(date: string): string {
    return new Date(`${date.slice(0, 10)}T00:00:00`).toLocaleDateString([], {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** "2026-10-26" → "26 Oct". */
export function formatDay(date: string): string {
    return new Date(`${date.slice(0, 10)}T00:00:00`).toLocaleDateString([], {
        day: 'numeric',
        month: 'short',
    });
}

/** An ISO timestamp → "26 Oct 2026, 14:05". */
export function formatDateTime(timestamp: string): string {
    return new Date(timestamp).toLocaleString([], {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** 90 → "1:30". */
export function formatMinutes(minutes: number): string {
    return `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}`;
}

/** Today in the reader's time zone, "2026-10-26". */
export function todayIso(): string {
    const now = new Date();

    return new Date(now.getTime() - now.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
}
