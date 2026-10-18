import type { ReactNode } from 'react';

/**
 * The page title, what it is about, and its main actions on the right.
 */
export function PageHeader({
    title,
    description,
    badges,
    actions,
}: {
    title: ReactNode;
    description?: ReactNode;
    /** Shown next to the title (status and the like). */
    badges?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-lg font-semibold tracking-tight">
                        {title}
                    </h1>
                    {badges}
                </div>
                {description && (
                    <p className="mt-0.5 text-sm text-muted-foreground">
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
