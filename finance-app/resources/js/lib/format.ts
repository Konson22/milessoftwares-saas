import { usePage } from '@inertiajs/react';
import type { Tone } from '@miles/ui/components/status-badge';
import { formatMoney } from '@/lib/money';

/**
 * Formats minor units in the books' currency, or another one.
 */
export function useMoney(other?: string | null): (minor: number) => string {
    const { currency } = usePage().props;

    return (minor) => formatMoney(minor, other || (currency ?? 'USD'));
}

/**
 * The decimals amounts are typed with (input step), in the books' currency
 * or another one.
 */
export function useAmountStep(other?: string | null): string {
    const { currency } = usePage().props;

    return formatMoney(1, other || (currency ?? 'USD')).includes('.')
        ? '0.01'
        : '1';
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
    'finance.credit_note.received': 'Supplier credit',
    year_close: 'Year-end close',
    year_reopen: 'Year reopened',
    opening_balances: 'Opening balances',
    tax_settlement: 'Tax settlement',
    fx_revaluation: 'Currency revaluation',
    fx_revaluation_reversal: 'Revaluation reversed',
    depreciation: 'Depreciation',
    asset_disposal: 'Asset disposal',
    recurring: 'Recurring journal',
    recurring_reversal: 'Recurring reversed',
};

export function sourceTypeLabel(type: string): string {
    return SOURCE_TYPES[type] ?? type;
}

/** "pharmacy" → "Pharmacy"; entries made in Finance itself → "Finance". */
export function sourceLabel(source: string): string {
    return source.charAt(0).toUpperCase() + source.slice(1);
}
