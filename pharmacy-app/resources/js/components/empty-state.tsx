import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * What a page shows when there is nothing yet, and what to do about it.
 */
export function EmptyState({
    icon: Icon,
    title,
    children,
    action,
}: {
    icon: LucideIcon;
    title: string;
    children?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center px-6 py-12 text-center">
            <span className="grid size-11 place-items-center rounded-full bg-muted text-muted-foreground">
                <Icon className="size-5" aria-hidden />
            </span>
            <p className="mt-3 font-medium">{title}</p>
            {children && (
                <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                    {children}
                </p>
            )}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
