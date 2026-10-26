import type { ComponentProps, ReactNode } from 'react';
import { Label } from '@miles/ui/components/ui/label';
import { cn } from '@miles/ui/lib/utils';
import type { Option } from '@/types';

/**
 * A labelled form control with its hint and validation error.
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
    label: string;
    error?: string;
    hint?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-1.5', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && (
                <p id={`${id}-error`} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * A native select in the look of the UI kit's inputs.
 */
export function Select({
    options,
    placeholder,
    className,
    ...props
}: ComponentProps<'select'> & { options: Option[]; placeholder?: string }) {
    return (
        <select
            className={cn(
                'flex h-8 w-full min-w-0 border border-input bg-card px-2 py-1 text-base outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm',
                className,
            )}
            {...props}
        >
            {placeholder !== undefined && (
                <option value="">{placeholder}</option>
            )}
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

/**
 * Error props for an input: marks it invalid and points at its message.
 */
export function invalid(id: string, error?: string) {
    return error
        ? { 'aria-invalid': true as const, 'aria-describedby': `${id}-error` }
        : {};
}
