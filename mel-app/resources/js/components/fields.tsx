import { useState } from 'react';
import type { ReactNode } from 'react';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import type { Option } from '@/lib/mel';

export const selectClass =
    'h-9 w-full rounded-md border bg-transparent px-3 text-sm';

export const textareaClass =
    'min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm';

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
    hint?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={`grid content-start gap-1.5 ${className ?? ''}`}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

export function TextField({
    name,
    label,
    error,
    hint,
    defaultValue,
    type = 'text',
    required = false,
    prefix = '',
    className,
    inputMode,
    placeholder,
}: {
    name: string;
    label: string;
    error?: string;
    hint?: ReactNode;
    defaultValue?: string | number | null;
    type?: string;
    required?: boolean;
    prefix?: string;
    className?: string;
    inputMode?: 'decimal' | 'numeric' | 'text';
    placeholder?: string;
}) {
    const id = `${prefix}${name}`;

    return (
        <Field
            id={id}
            label={label}
            error={error}
            hint={hint}
            className={className}
        >
            <Input
                id={id}
                name={name}
                type={type}
                defaultValue={defaultValue ?? ''}
                required={required}
                inputMode={inputMode}
                placeholder={placeholder}
                aria-invalid={error ? true : undefined}
            />
        </Field>
    );
}

export function TextAreaField({
    name,
    label,
    error,
    hint,
    defaultValue,
    prefix = '',
    className,
    rows = 3,
    required = false,
}: {
    name: string;
    label: string;
    error?: string;
    hint?: ReactNode;
    defaultValue?: string | null;
    prefix?: string;
    className?: string;
    rows?: number;
    required?: boolean;
}) {
    const id = `${prefix}${name}`;

    return (
        <Field
            id={id}
            label={label}
            error={error}
            hint={hint}
            className={className}
        >
            <textarea
                id={id}
                name={name}
                rows={rows}
                defaultValue={defaultValue ?? ''}
                required={required}
                className={textareaClass}
                aria-invalid={error ? true : undefined}
            />
        </Field>
    );
}

export function SelectField({
    name,
    label,
    options,
    error,
    hint,
    defaultValue,
    empty,
    prefix = '',
    className,
}: {
    name: string;
    label: string;
    options: Option[];
    error?: string;
    hint?: ReactNode;
    defaultValue?: string | null;
    /** Label of the "nothing chosen" choice; leave out to require one. */
    empty?: string;
    prefix?: string;
    className?: string;
}) {
    const id = `${prefix}${name}`;

    return (
        <Field
            id={id}
            label={label}
            error={error}
            hint={hint}
            className={className}
        >
            <select
                id={id}
                name={name}
                defaultValue={defaultValue ?? ''}
                className={selectClass}
                aria-invalid={error ? true : undefined}
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

export function CheckboxField({
    name,
    label,
    defaultChecked,
    hint,
    prefix = '',
}: {
    name: string;
    label: string;
    defaultChecked?: boolean;
    hint?: ReactNode;
    prefix?: string;
}) {
    const id = `${prefix}${name}`;
    const [checked, setChecked] = useState(defaultChecked ?? false);

    return (
        <div className="grid gap-1">
            <label htmlFor={id} className="flex items-center gap-2 text-sm">
                {/* One value either way: "1" ticked, "0" not. */}
                <input type="hidden" name={name} value={checked ? '1' : '0'} />
                <input
                    id={id}
                    type="checkbox"
                    checked={checked}
                    onChange={(event) => setChecked(event.target.checked)}
                    className="size-4"
                />
                {label}
            </label>
            {hint && (
                <p className="pl-6 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

/** The first error of a field and its sub-fields ("cells.3.value"). */
export function errorFor(
    errors: Record<string, string | undefined>,
    key: string,
): string | undefined {
    return (
        errors[key] ??
        Object.entries(errors).find(([name]) => name.startsWith(`${key}.`))?.[1]
    );
}
