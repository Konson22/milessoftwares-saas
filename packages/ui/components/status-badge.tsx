import type { ReactNode } from 'react';
import { cn } from '../lib/utils';

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

const TONES: Record<Tone, string> = {
    neutral: 'border-slate-200 bg-slate-100 text-slate-700',
    info: 'border-blue-200 bg-blue-50 text-blue-700',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
    danger: 'border-red-200 bg-red-50 text-red-700',
};

/**
 * A small rounded label: a status, a type or a flag.
 */
export function StatusBadge({
    tone = 'neutral',
    className,
    children,
}: {
    tone?: Tone;
    className?: string;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                TONES[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}
