import type { ReactNode } from 'react';

/**
 * Logbook's page title: a large bold title, badges beside it and the main
 * actions on the right. No subtitle line (the user's choice, 2026-11-20):
 * `description` is still accepted so pages compile, and is not shown.
 */
export function PageHeader({
    title,
    badges,
    actions,
}: {
    title: ReactNode;
    description?: ReactNode;
    badges?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <div
            data-slot="page-header"
            className="mb-5 flex flex-wrap items-center justify-between gap-3"
        >
            <div className="flex min-w-0 flex-wrap items-center gap-2">
                <h1 className="text-2xl leading-8 font-semibold tracking-tight">
                    {title}
                </h1>
                {badges}
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
