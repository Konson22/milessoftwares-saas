import type { Tone } from '@miles/ui/components/status-badge';

/** Statuses of shipments, trips, invoices and vehicles: label and look. */
const STATUSES: Record<string, { label: string; tone: Tone }> = {
    // Shipments.
    booked: { label: 'Booked', tone: 'neutral' },
    assigned: { label: 'On a planned trip', tone: 'info' },
    in_transit: { label: 'In transit', tone: 'warning' },
    delivered: { label: 'Delivered', tone: 'success' },
    failed: { label: 'Not delivered', tone: 'danger' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
    // Trips.
    planned: { label: 'Planned', tone: 'info' },
    in_progress: { label: 'On the road', tone: 'warning' },
    completed: { label: 'Completed', tone: 'success' },
    // Invoices.
    issued: { label: 'Unpaid', tone: 'info' },
    paid: { label: 'Paid', tone: 'success' },
    voided: { label: 'Voided', tone: 'neutral' },
    // Vehicles.
    available: { label: 'Available', tone: 'success' },
    maintenance: { label: 'In maintenance', tone: 'warning' },
    retired: { label: 'Retired', tone: 'neutral' },
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
