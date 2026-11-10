import type { ReactNode } from 'react';
import { cn } from '../lib/utils';

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';

// Only the logo's colours: the more a status asks of you, the stronger it is.
const TONES: Record<Tone, string> = {
    neutral: 'border-brand-100 bg-brand-50 text-brand-800',
    info: 'border-brand-200 bg-brand-100 text-brand-700',
    success: 'border-brand-400 bg-white text-brand-700',
    warning: 'border-brand-600 bg-brand-600 text-white',
    danger: 'border-brand-900 bg-brand-900 text-white',
};

/**
 * A small square label: a status, a type or a flag.
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
                'inline-flex items-center gap-1 border px-1.5 py-px text-xs font-medium whitespace-nowrap',
                TONES[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}
