import { cn } from '@/shared/lib/utils';

type StatusBadgeProps = {
    status: string;
    className?: string;
};

const statusStyles: Record<string, string> = {
    active: 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800',
    inactive: 'bg-muted text-muted-foreground border-border',
    archived:
        'bg-amber-50 text-amber-900 border-amber-200 dark:bg-amber-950/40 dark:text-amber-200 dark:border-amber-800',
};

const dotStyles: Record<string, string> = {
    active: 'bg-emerald-600 dark:bg-emerald-400',
    inactive: 'bg-muted-foreground',
    archived: 'bg-amber-600 dark:bg-amber-400',
};

function labelFor(status: string) {
    if (!status) {
        return status;
    }

    return status.charAt(0).toUpperCase() + status.slice(1);
}

export function StatusBadge({ status, className }: StatusBadgeProps) {
    const key = status.toLowerCase();
    const styles = statusStyles[key] ?? statusStyles.inactive;
    const dot = dotStyles[key] ?? dotStyles.inactive;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium',
                styles,
                className,
            )}
        >
            <span className={cn('size-1.5 rounded-full', dot)} aria-hidden />
            {labelFor(status)}
        </span>
    );
}
