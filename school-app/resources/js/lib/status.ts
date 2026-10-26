import type { Tone } from '@miles/ui/components/status-badge';

/** Statuses of students, applications, registers and invoices: label and look. */
const STATUSES: Record<string, { label: string; tone: Tone }> = {
    // Students.
    active: { label: 'Enrolled', tone: 'success' },
    suspended: { label: 'Suspended', tone: 'warning' },
    withdrawn: { label: 'Withdrawn', tone: 'neutral' },
    graduated: { label: 'Graduated', tone: 'info' },
    // Applications.
    pending: { label: 'Pending', tone: 'warning' },
    admitted: { label: 'Admitted', tone: 'success' },
    declined: { label: 'Declined', tone: 'neutral' },
    // Registers.
    present: { label: 'Present', tone: 'success' },
    late: { label: 'Late', tone: 'warning' },
    absent: { label: 'Absent', tone: 'danger' },
    excused: { label: 'Excused', tone: 'info' },
    // Invoices.
    issued: { label: 'Unpaid', tone: 'info' },
    paid: { label: 'Paid', tone: 'success' },
    voided: { label: 'Voided', tone: 'neutral' },
};

export function statusTone(status: string): Tone {
    return STATUSES[status]?.tone ?? 'neutral';
}

export function statusLabel(status: string): string {
    return STATUSES[status]?.label ?? status;
}

/** "2026-10-26" → "26 Oct 2026", in the reader's locale. */
export function formatDate(date: string): string {
    return new Date(`${date.slice(0, 10)}T00:00:00`).toLocaleDateString([], {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
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
