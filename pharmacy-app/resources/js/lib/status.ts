import type { Tone } from '@miles/ui/components/status-badge';

/** Document statuses across the pharmacy, and how they look. */
const STATUS_TONES: Record<string, Tone> = {
    draft: 'neutral',
    ordered: 'info',
    partly_received: 'warning',
    received: 'success',
    cancelled: 'neutral',
    completed: 'success',
    voided: 'danger',
    recorded: 'warning',
    verified: 'info',
    partly_dispensed: 'warning',
    dispensed: 'success',
    rejected: 'danger',
    open: 'info',
    closed: 'neutral',
    pending: 'warning',
    approved: 'success',
    in_transit: 'info',
    quote: 'neutral',
    confirmed: 'info',
    partly_delivered: 'warning',
    delivered: 'success',
};

export function statusTone(status: string): Tone {
    return STATUS_TONES[status] ?? 'neutral';
}
