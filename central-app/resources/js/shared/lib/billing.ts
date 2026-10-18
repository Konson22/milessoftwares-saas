import type { Tone } from '@miles/ui/components/status-badge';

/**
 * Amounts arrive in minor units (cents) with their ISO currency.
 */
export function formatMoney(minor: number, currency: string): string {
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            currencyDisplay: 'code',
        }).format(minor / 100);
    } catch {
        return `${currency} ${(minor / 100).toFixed(2)}`;
    }
}

/** A calendar date (YYYY-MM-DD) as the viewer reads dates. */
export function formatDate(date: string | null): string {
    if (!date) {
        return '—';
    }

    const [year, month, day] = date.slice(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export const intervalLabel: Record<string, string> = {
    monthly: 'per month',
    yearly: 'per year',
    custom: 'custom terms',
};

export const methodLabel: Record<string, string> = {
    bank_transfer: 'Bank transfer',
    mobile_money: 'Mobile money',
    cash: 'Cash',
    card: 'Card',
    other: 'Other',
};

export const invoiceTone: Record<string, Tone> = {
    open: 'info',
    overdue: 'danger',
    paid: 'success',
    void: 'neutral',
};

export const paymentTone: Record<string, Tone> = {
    reported: 'warning',
    confirmed: 'success',
    rejected: 'danger',
};

export const paymentStatusLabel: Record<string, string> = {
    reported: 'Awaiting confirmation',
    confirmed: 'Confirmed',
    rejected: 'Rejected',
};

export const subscriptionTone: Record<string, Tone> = {
    trialing: 'info',
    active: 'success',
    past_due: 'warning',
    suspended: 'danger',
    expired: 'neutral',
    cancelled: 'neutral',
};

export const requestTone: Record<string, Tone> = {
    pending: 'warning',
    approved: 'success',
    declined: 'danger',
    withdrawn: 'neutral',
};

export function humanize(value: string): string {
    const text = value.replaceAll('_', ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}
