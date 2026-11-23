import type { Tone } from '@miles/ui/components/status-badge';

/*
 * What every Procurement page shares. Amounts arrive as exact decimal
 * strings in their currency ("4350.00") and are only formatted here.
 */

export type Option = { value: string; label: string };

export type Approval = {
    history: {
        id: string;
        round: number;
        step: number;
        approver: string;
        status: string;
        decidedBy: string | null;
        decidedAt: string | null;
        note: string | null;
    }[];
    waiting: string | null;
    canDecide: boolean;
    refusal: string | null;
};

export type Documents = {
    items: {
        id: string;
        name: string;
        size: number;
        uploadedAt: string | null;
    }[];
    canAdd: boolean;
    type: string;
    id: string;
};

const STATUS: Record<string, { label: string; tone: Tone }> = {
    draft: { label: 'Draft', tone: 'neutral' },
    pending_approval: { label: 'Awaiting approval', tone: 'warning' },
    approved: { label: 'Approved', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'danger' },
    returned: { label: 'Returned', tone: 'warning' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
    closed: { label: 'Closed', tone: 'neutral' },
    issued: { label: 'Issued', tone: 'info' },
    evaluation: { label: 'In evaluation', tone: 'info' },
    awarded: { label: 'Awarded', tone: 'success' },
    partially_received: { label: 'Partly received', tone: 'warning' },
    received: { label: 'Received', tone: 'success' },
    withdrawn: { label: 'Withdrawn', tone: 'neutral' },
    skipped: { label: 'Not needed', tone: 'neutral' },
    pending: { label: 'Waiting', tone: 'warning' },
    active: { label: 'Active', tone: 'success' },
    inactive: { label: 'Inactive', tone: 'neutral' },
    suspended: { label: 'Suspended', tone: 'danger' },
    matched: { label: 'Matched', tone: 'success' },
    awaiting_receipt: { label: 'Awaiting goods', tone: 'warning' },
    recorded: { label: 'Recorded', tone: 'info' },
    voided: { label: 'Voided', tone: 'neutral' },
    unpaid: { label: 'Unpaid', tone: 'warning' },
    paid_externally: { label: 'Paid (recorded by hand)', tone: 'info' },
    passed: { label: 'Passed', tone: 'success' },
    partial: { label: 'Partly accepted', tone: 'warning' },
    failed: { label: 'Failed', tone: 'danger' },
    compliant: { label: 'Compliant', tone: 'success' },
    non_compliant: { label: 'Does not comply', tone: 'danger' },
    not_evaluated: { label: 'Not evaluated', tone: 'neutral' },
};

export function status(key: string): { label: string; tone: Tone } {
    return (
        STATUS[key] ?? {
            label: key.replace(/_/g, ' '),
            tone: 'neutral',
        }
    );
}

export const METHODS: Record<string, string> = {
    direct: 'Direct purchase',
    rfq: 'Request for quotation',
    rfp: 'Request for proposal',
    tender: 'Competitive tender',
    single_source: 'Single source',
    framework: 'Framework agreement',
};

/** "4350.00" → "4,350.00"; null → "—". */
export function amount(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const [whole, fraction] = value.split('.');
    const negative = whole.startsWith('-');
    const digits = negative ? whole.slice(1) : whole;
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return (
        (negative ? '−' : '') +
        grouped +
        (fraction !== undefined ? `.${fraction}` : '')
    );
}

export function money(
    value: string | null | undefined,
    currency: string,
): string {
    return value === null || value === undefined
        ? '—'
        : `${currency} ${amount(value)}`;
}

/** "4.000" → "4"; "2.500" → "2.5". */
export function quantity(value: string): string {
    return value.includes('.') ? value.replace(/\.?0+$/, '') : value;
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

/** Minor units → "4,350.00" with the currency's decimals. */
export function minor(value: number, decimals: number): string {
    const negative = value < 0;
    const digits = String(Math.abs(value)).padStart(decimals + 1, '0');
    const major =
        decimals === 0
            ? digits
            : `${digits.slice(0, -decimals)}.${digits.slice(-decimals)}`;

    return amount((negative ? '-' : '') + major);
}
