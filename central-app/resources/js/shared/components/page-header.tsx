import type { ReactNode } from 'react';
import { cn } from '@/shared/lib/utils';

type PageHeaderProps = {
    title: string;
    /** Accepted so existing pages compile; not shown. */
    description?: string;
    actions?: ReactNode;
    className?: string;
};

export function PageHeader({
    title,
    actions,
    className,
}: PageHeaderProps) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-3',
                className,
            )}
        >
            <div className="min-w-0">
                <h1 className="truncate text-xl font-semibold tracking-tight">
                    {title}
                </h1>
            </div>
            {actions && (
                <div className="flex shrink-0 flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
