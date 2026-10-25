import type { Tone } from '@miles/ui/components/status-badge';

/** Statuses of orders, kitchen tickets, bills and shifts, and how they look. */
const STATUS_TONES: Record<string, Tone> = {
    open: 'info',
    paid: 'success',
    cancelled: 'neutral',
    voided: 'danger',
    closed: 'neutral',
    // Order lines and kitchen tickets.
    new: 'neutral',
    preparing: 'warning',
    ready: 'success',
    served: 'neutral',
};

export function statusTone(status: string): Tone {
    return STATUS_TONES[status] ?? 'neutral';
}
