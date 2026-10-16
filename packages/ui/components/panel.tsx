import type { ComponentProps, ReactNode } from 'react';
import { cn } from '../lib/utils';

/**
 * The white surface pages put their content on.
 */
export function Panel({ className, ...props }: ComponentProps<'section'>) {
    return (
        <section
            className={cn(
                'overflow-hidden rounded-lg border bg-card text-card-foreground shadow-xs',
                className,
            )}
            {...props}
        />
    );
}

/**
 * A panel's title row, with optional actions on the right.
 */
export function PanelHeader({
    title,
    description,
    actions,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3',
                className,
            )}
        >
            <div>
                <h2 className="text-sm font-semibold">{title}</h2>
                {description && (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}

/**
 * Search and filters above a panel's table.
 */
export function PanelToolbar({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-center gap-3 border-b p-4',
                className,
            )}
            {...props}
        />
    );
}

export function PanelBody({ className, ...props }: ComponentProps<'div'>) {
    return <div className={cn('p-4', className)} {...props} />;
}
