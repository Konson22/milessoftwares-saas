import { StatusBadge } from '@miles/ui/components/status-badge';
import { cn } from '@miles/ui/lib/utils';
import {
    priorityLabel,
    priorityTone,
    statusLabel,
    statusTone,
} from '@/lib/status';

export function Status({ status }: { status: string }) {
    return (
        <StatusBadge tone={statusTone(status)}>
            {statusLabel(status)}
        </StatusBadge>
    );
}

export function Priority({ priority }: { priority: string }) {
    return (
        <StatusBadge tone={priorityTone(priority)}>
            {priorityLabel(priority)}
        </StatusBadge>
    );
}

/**
 * How far along, as a bar with the percentage.
 */
export function Progress({
    value,
    className,
}: {
    value: number;
    className?: string;
}) {
    return (
        <div className={cn('flex items-center gap-2', className)}>
            <div
                className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuenow={value}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label="Progress"
            >
                <div
                    className="h-full rounded-full bg-primary"
                    style={{ width: `${Math.min(100, Math.max(0, value))}%` }}
                />
            </div>
            <span className="w-9 text-right text-xs text-muted-foreground tabular-nums">
                {value}%
            </span>
        </div>
    );
}
