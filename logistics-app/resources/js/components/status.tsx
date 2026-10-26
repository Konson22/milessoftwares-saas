import { StatusBadge } from '@miles/ui/components/status-badge';
import { statusLabel, statusTone } from '@/lib/status';

export function Status({ status }: { status: string }) {
    return (
        <StatusBadge tone={statusTone(status)}>
            {statusLabel(status)}
        </StatusBadge>
    );
}
