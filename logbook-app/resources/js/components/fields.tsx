import type { ReactNode } from 'react';
import { useId } from 'react';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import type { Tone } from '@miles/ui/components/status-badge';
import type { Option } from '@/lib/logbook';
import { status } from '@/lib/logbook';

/**
 * Form fields shared by every page: a label, the control and its error.
 * The controls' look (frame, bottom edge, focus) lives in app.css on their
 * data-slot, so every input, select and textarea matches.
 */
export function Field({
    label,
    error,
    hint,
    required,
    htmlFor,
    children,
    className,
}: {
    label: string;
    error?: string;
    hint?: ReactNode;
    required?: boolean;
    htmlFor?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={`grid content-start gap-1 ${className ?? ''}`}>
            <Label
                htmlFor={htmlFor}
                className="text-[13px] leading-5 font-medium text-foreground"
            >
                {label}
                {required && (
                    <span className="text-destructive" aria-hidden>
                        {' '}
                        *
                    </span>
                )}
            </Label>
            {children}
            {hint && !error && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            {error && (
                <p className="flex items-start gap-1 text-xs font-medium text-destructive">
                    {error}
                </p>
            )}
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
    const id = useId();

    return (
        <Field
            label={label}
            error={error}
            hint={hint}
            required={rest.required}
            htmlFor={id}
            className={className}
        >
            <Input
                id={id}
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
    const id = useId();

    return (
        <Field label={label} error={error} htmlFor={id} className={className}>
            <textarea
                id={id}
                data-slot="textarea"
                rows={rows}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={error ? true : undefined}
                className="w-full px-2.5 py-1.5 text-sm"
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
    disabled,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: Option[];
    error?: string;
    hint?: ReactNode;
    empty?: string;
    className?: string;
    disabled?: boolean;
}) {
    const id = useId();

    return (
        <Field
            label={label}
            error={error}
            hint={hint}
            htmlFor={id}
            className={className}
        >
            <select
                id={id}
                data-slot="select"
                data-empty={value === '' ? true : undefined}
                value={value}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={error ? true : undefined}
                className="w-full truncate pl-2.5 text-sm"
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
        <label className="flex cursor-pointer items-center gap-2 text-sm">
            <input
                type="checkbox"
                data-slot="check"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="size-4 cursor-pointer"
            />
            {label}
        </label>
    );
}

export function Status({ value }: { value: string }) {
    const { label, tone } = status(value);

    return <Badge tone={tone}>{label}</Badge>;
}

/** Solid light tints with dark text, as in the design reference. */
const TONES: Record<Tone, string> = {
    neutral: 'bg-[#eef2f6] text-[#334155]',
    info: 'bg-[#e3edfc] text-[#1d4fb8]',
    success: 'bg-[#e1f4ea] text-[#137a4b]',
    warning: 'bg-[#fcf1cf] text-[#8a5a00]',
    danger: 'bg-[#fde6e6] text-[#c01c1c]',
};

/** A small rounded label: a status, a priority, a type or a flag. */
export function Badge({
    tone = 'neutral',
    children,
}: {
    tone?: Tone;
    children: ReactNode;
}) {
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ${TONES[tone]}`}
        >
            {children}
        </span>
    );
}

const PRIORITY_TONES: Record<string, Tone> = {
    low: 'success',
    normal: 'warning',
    high: 'danger',
    urgent: 'danger',
};

/** A task's priority: low green, normal amber, high and urgent red. */
export function Priority({ value }: { value: string }) {
    return (
        <Badge tone={PRIORITY_TONES[value] ?? 'neutral'}>
            {status(value).label}
        </Badge>
    );
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
