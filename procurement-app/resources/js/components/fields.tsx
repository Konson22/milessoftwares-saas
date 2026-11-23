import type { ReactNode } from 'react';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { StatusBadge } from '@miles/ui/components/status-badge';
import type { Option } from '@/lib/procurement';
import { status } from '@/lib/procurement';

/**
 * Form fields shared by every page: a label, the control and its error.
 */
export function Field({
    label,
    error,
    hint,
    children,
    className,
}: {
    label: string;
    error?: string;
    hint?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={`grid gap-1.5 ${className ?? ''}`}>
            <Label>{label}</Label>
            {children}
            {hint && !error && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

export function TextField({
    label,
    value,
    onChange,
    error,
    hint,
    type = 'text',
    className,
    ...rest
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint?: ReactNode;
    type?: string;
    className?: string;
    placeholder?: string;
    required?: boolean;
    inputMode?: 'decimal' | 'numeric' | 'text';
    min?: string;
}) {
    return (
        <Field label={label} error={error} hint={hint} className={className}>
            <Input
                type={type}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={error ? true : undefined}
                {...rest}
            />
        </Field>
    );
}

export function TextArea({
    label,
    value,
    onChange,
    error,
    rows = 3,
    className,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    rows?: number;
    className?: string;
}) {
    return (
        <Field label={label} error={error} className={className}>
            <textarea
                rows={rows}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
            />
        </Field>
    );
}

export function SelectField({
    label,
    value,
    onChange,
    options,
    error,
    hint,
    empty,
    className,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Option[];
    error?: string;
    hint?: ReactNode;
    empty?: string;
    className?: string;
}) {
    return (
        <Field label={label} error={error} hint={hint} className={className}>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
            >
                {empty !== undefined && <option value="">{empty}</option>}
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </Field>
    );
}

export function Check({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex items-center gap-2 text-sm">
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="size-4"
            />
            {label}
        </label>
    );
}

export function Status({ value }: { value: string }) {
    const { label, tone } = status(value);

    return <StatusBadge tone={tone}>{label}</StatusBadge>;
}

/** A definition list row for detail pages. */
export function Detail({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-0.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children ?? '—'}</dd>
        </div>
    );
}

/** The first validation error, for actions without their own field. */
export function Errors({ errors }: { errors: Record<string, string> }) {
    const messages = Object.values(errors);
    if (messages.length === 0) {
        return null;
    }

    return (
        <div className="rounded-md border border-destructive bg-background px-3 py-2 text-sm text-destructive">
            {messages.map((message) => (
                <p key={message}>{message}</p>
            ))}
        </div>
    );
}
