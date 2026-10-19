import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';

type Props = {
    action: string;
    formToken: string;
    submitLabel: string;
    children?: (errors: Record<string, string>) => ReactNode;
};

/**
 * Shared fields for the demo and contact forms, plus the spam-protection
 * fields: a signed timestamp and a honeypot that people never see. The
 * honeypot is hidden from sight, screen readers and keyboard focus.
 */
export default function LeadForm({
    action,
    formToken,
    submitLabel,
    children,
}: Props) {
    return (
        <Form
            action={action}
            method="post"
            className="space-y-5"
            noValidate={false}
        >
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="form_token" value={formToken} />
                    <div
                        aria-hidden="true"
                        className="absolute -left-[10000px] h-px w-px overflow-hidden"
                    >
                        <label htmlFor="website">Leave this field empty</label>
                        <input
                            id="website"
                            name="website"
                            type="text"
                            tabIndex={-1}
                            autoComplete="off"
                        />
                    </div>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field
                            name="name"
                            label="Your name"
                            autoComplete="name"
                            required
                            error={errors.name}
                        />
                        <Field
                            name="email"
                            label="Work email"
                            type="email"
                            autoComplete="email"
                            required
                            error={errors.email}
                        />
                        <Field
                            name="company"
                            label="Organization"
                            autoComplete="organization"
                            error={errors.company}
                        />
                        <Field
                            name="phone"
                            label="Phone"
                            type="tel"
                            autoComplete="tel"
                            error={errors.phone}
                        />
                    </div>

                    {children?.(errors)}

                    <div className="grid gap-2">
                        <Label htmlFor="message">How can we help?</Label>
                        <textarea
                            id="message"
                            name="message"
                            rows={5}
                            maxLength={5000}
                            className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                        />
                        <InputError message={errors.message} />
                    </div>

                    <InputError
                        message={
                            errors.form_token
                                ? 'Please reload the page and try again.'
                                : undefined
                        }
                    />

                    <Button
                        type="submit"
                        disabled={processing}
                        className="h-12 rounded-full bg-linear-to-r from-[#cf440c] to-[#b93a0b] px-7 font-bold text-white shadow-lg shadow-site-accent/25 hover:from-[#b93a0b] hover:to-[#a3320a]"
                    >
                        {submitLabel}
                    </Button>
                </>
            )}
        </Form>
    );
}

export function Field({
    name,
    label,
    type = 'text',
    autoComplete,
    required = false,
    error,
}: {
    name: string;
    label: string;
    type?: string;
    autoComplete?: string;
    required?: boolean;
    error?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={name}>
                {label}
                {required && <span aria-hidden="true"> *</span>}
            </Label>
            <Input
                id={name}
                name={name}
                type={type}
                autoComplete={autoComplete}
                required={required}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${name}-error` : undefined}
            />
            {error && (
                <p
                    id={`${name}-error`}
                    className="text-sm text-red-600 dark:text-red-400"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
