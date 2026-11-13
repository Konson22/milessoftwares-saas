import type { Tone } from '@miles/ui/components/status-badge';
import { StatusBadge } from '@miles/ui/components/status-badge';

const LABELS: Record<string, { label: string; tone: Tone }> = {
    draft: { label: 'Draft', tone: 'warning' },
    reviewed: { label: 'Reviewed', tone: 'warning' },
    approved: { label: 'Approved', tone: 'info' },
    part_paid: { label: 'Part paid', tone: 'info' },
    paid: { label: 'Paid', tone: 'success' },
    reversed: { label: 'Reversed', tone: 'neutral' },
};

export function RunStatus({ status }: { status: string }) {
    const { label, tone } = LABELS[status] ?? LABELS.draft;

    return <StatusBadge tone={tone}>{label}</StatusBadge>;
}
