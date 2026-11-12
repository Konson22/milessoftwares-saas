import { StatusBadge } from '@miles/ui/components/status-badge';

const LABELS: Record<
    string,
    { label: string; tone: 'warning' | 'info' | 'success' }
> = {
    draft: { label: 'Draft', tone: 'warning' },
    approved: { label: 'Approved', tone: 'info' },
    paid: { label: 'Paid', tone: 'success' },
};

export function RunStatus({ status }: { status: string }) {
    const { label, tone } = LABELS[status] ?? LABELS.draft;

    return <StatusBadge tone={tone}>{label}</StatusBadge>;
}
