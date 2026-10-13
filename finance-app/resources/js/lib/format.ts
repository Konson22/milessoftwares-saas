import { usePage } from '@inertiajs/react';
import type { Tone } from '@/components/status-badge';
import { formatMoney } from '@/lib/money';

/**
 * Formats minor units in the books' currency.
 */
export function useMoney(): (minor: number) => string {
    const { currency } = usePage().props;

    return (minor) => formatMoney(minor, currency ?? 'USD');
}

/**
 * The decimals amounts are typed with in the books' currency (input step).
 */
export function useAmountStep(): string {
    const { currency } = usePage().props;

    return formatMoney(1, currency ?? 'USD').includes('.') ? '0.01' : '1';
}

const STATUS_LABELS: Record<string, string> = {
    open: 'Open',
    partly_paid: 'Partly paid',
    paid: 'Paid',
    overdue: 'Overdue',
    void: 'Void',
};

export function statusLabel(status: string): string {
    return STATUS_LABELS[status] ?? status;
}

const STATUS_TONES: Record<string, Tone> = {
    open: 'info',
    partly_paid: 'warning',
    paid: 'success',
    overdue: 'danger',
    void: 'neutral',
};

/** How a bill's or invoice's status looks. */
export function statusTone(status: string): Tone {
    return STATUS_TONES[status] ?? 'neutral';
}

const SOURCE_TYPES: Record<string, string> = {
    manual: 'Manual journal',
    reversal: 'Reversal',
    bill: 'Bill',
    invoice: 'Invoice',
    receipt: 'Customer payment',
    supplier_payment: 'Supplier payment',
    money_in: 'Money in',
    money_out: 'Money out',
    transfer: 'Transfer',
    'finance.invoice.issued': 'Sale / invoice',
    'finance.payment.received': 'Payment received',
    'finance.invoice.voided': 'Void',
    'finance.bill.received': 'Supplier bill',
};

export function sourceTypeLabel(type: string): string {
    return SOURCE_TYPES[type] ?? type;
}

/** "pharmacy" → "Pharmacy"; entries made in Finance itself → "Finance". */
export function sourceLabel(source: string): string {
    return source.charAt(0).toUpperCase() + source.slice(1);
}
