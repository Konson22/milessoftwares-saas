import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@miles/ui/lib/utils';

/**
 * A card holding one chart: the title with a short note under it, an
 * optional link on the right that never squeezes the title, then the
 * chart. `min-w-0` lets the chart shrink inside a grid column instead of
 * pushing past its neighbour.
 */
export function ChartCard({
    title,
    note,
    link,
    className,
    children,
}: {
    title: string;
    note?: ReactNode;
    link?: { href: string; label: string };
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            data-slot="chart-card"
            className={cn(
                'flex min-w-0 flex-col rounded-[0.625rem] border bg-card shadow-[0_1px_2px_#e6eaf0]',
                className,
            )}
        >
            <header className="flex items-start justify-between gap-4 px-5 pt-4">
                <div className="min-w-0">
                    <h2 className="text-base leading-6 font-semibold">
                        {title}
                    </h2>
                    {note && (
                        <p className="mt-0.5 text-xs leading-5 text-muted-foreground">
                            {note}
                        </p>
                    )}
                </div>
                {link && (
                    <Link
                        href={link.href}
                        className="mt-0.5 shrink-0 text-sm font-medium whitespace-nowrap text-primary hover:underline"
                    >
                        {link.label}
                    </Link>
                )}
            </header>
            <div className="min-w-0 flex-1 px-5 pt-4 pb-5">{children}</div>
        </section>
    );
}
