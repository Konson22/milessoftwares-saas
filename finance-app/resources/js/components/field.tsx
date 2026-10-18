import type { ComponentProps, ReactNode } from 'react';
import { Label } from '@miles/ui/components/ui/label';
import { cn } from '@miles/ui/lib/utils';

/**
 * A labelled form control with its validation message.
 */
export function Field({
    id,
    label,
    error,
    hint,
    className,
    children,
}: {
    id: string;
    label: ReactNode;
    error?: string;
    hint?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-1.5', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && !error && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

/**
 * A native select styled like the inputs.
 */
export function Select({ className, ...props }: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'h-9 w-full min-w-0 rounded-md border border-input bg-card px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 aria-invalid:border-destructive',
                className,
            )}
            {...props}
        />
    );
}
