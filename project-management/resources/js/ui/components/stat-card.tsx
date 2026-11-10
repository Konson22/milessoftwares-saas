import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '../lib/utils';

type Accent = 'blue' | 'emerald' | 'amber' | 'red' | 'violet' | 'slate';

const ACCENTS: Record<Accent, string> = {
    blue: 'bg-primary/10 text-primary',
    emerald: 'bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-400',
    amber: 'bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-400',
    red: 'bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-400',
    // The logo's teal.
    violet: 'bg-brand-400/15 text-brand-600 dark:text-brand-400',
    slate: 'bg-brand-100 text-brand-600 dark:bg-brand-800 dark:text-brand-300',
};

/**
 * One figure at a glance; the whole card links on when given a link.
 */
export function StatCard({
    label,
    value,
    hint,
    icon: Icon,
    accent = 'blue',
    href,
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    icon?: LucideIcon;
    accent?: Accent;
    href?: NonNullable<InertiaLinkProps['href']>;
}) {
    const body = (
        <>
            <div className="flex items-start justify-between gap-3">
                <p className="text-xs font-medium text-muted-foreground">
                    {label}
                </p>
                {Icon && (
                    <span
                        className={cn(
                            'grid size-7 shrink-0 place-items-center',
                            ACCENTS[accent],
                        )}
                    >
                        <Icon className="size-4" aria-hidden />
                    </span>
                )}
            </div>
            <div className="mt-0.5 text-xl font-semibold tracking-tight tabular-nums">
                {value}
            </div>
            {hint && (
                <div className="mt-1 text-xs text-muted-foreground">{hint}</div>
            )}
        </>
    );
    const className = 'block border bg-card p-3 transition-colors';

    return href ? (
        <Link
            href={href}
            className={cn(
                className,
                'hover:border-primary/40 hover:bg-brand-50/30 dark:hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
            )}
        >
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}
