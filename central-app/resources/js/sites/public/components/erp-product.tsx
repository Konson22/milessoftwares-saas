export type ErpProduct = {
    code: string;
    name: string;
    tagline: string | null;
    description: string | null;
    status: 'planned' | 'pilot' | 'available';
    applications: {
        code: string;
        name: string;
        requirement: 'required' | 'optional';
        listed: boolean;
    }[];
};

/**
 * Release status in words. Generally available products carry no label;
 * nothing else is ever shown as available.
 */
export const erpStatusLabel: Record<ErpProduct['status'], string | null> = {
    available: null,
    pilot: 'Pilot',
    planned: 'Coming soon',
};

/**
 * What a visitor can do next. Products are not yet sold self-serve (a
 * trial covers one application, a product needs several), so every
 * product starts with a conversation.
 */
export const erpCallToAction: Record<ErpProduct['status'], string> = {
    available: 'Talk to us',
    pilot: 'Ask to join the pilot',
    planned: 'Tell me when it launches',
};

export function ErpStatusBadge({ status }: { status: ErpProduct['status'] }) {
    const label = erpStatusLabel[status];

    if (!label) {
        return null;
    }

    return (
        <span
            className={`shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ${status === 'pilot' ? 'bg-site-accent/10 text-site-accent-ink' : 'bg-site-soft text-slate-700 ring-1 ring-site-line'}`}
        >
            {label}
        </span>
    );
}
