import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';

/**
 * A button that opens a short note, then submits it as `note`. For steps
 * that record why: delivering, sending back, approving, rejecting.
 */
export function NoteAction({
    form,
    label,
    prompt,
    required = false,
    variant = 'outline',
}: {
    form: { action: string; method: 'post' };
    label: string;
    prompt: string;
    required?: boolean;
    variant?: 'default' | 'outline';
}) {
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <Button
                type="button"
                variant={variant}
                size="sm"
                onClick={() => setOpen(true)}
            >
                {label}
            </Button>
        );
    }

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={() => setOpen(false)}
            className="grid w-full gap-2"
        >
            {({ errors, processing }) => (
                <>
                    <label className="grid gap-1 text-sm">
                        <span className="text-muted-foreground">{prompt}</span>
                        <textarea
                            name="note"
                            rows={2}
                            maxLength={5000}
                            required={required}
                            className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </label>
                    {Object.values(errors).map((error) => (
                        <p
                            key={error}
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            {error}
                        </p>
                    ))}
                    <span className="flex gap-2">
                        <Button
                            type="submit"
                            size="sm"
                            variant={variant}
                            disabled={processing}
                        >
                            {label}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setOpen(false)}
                        >
                            Close
                        </Button>
                    </span>
                </>
            )}
        </Form>
    );
}

/**
 * A step with nothing to add: one button, and what stopped it if anything did.
 */
export function StepAction({
    form,
    label,
    variant = 'outline',
}: {
    form: { action: string; method: 'post' };
    label: string;
    variant?: 'default' | 'outline' | 'ghost';
}) {
    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            className="inline-flex items-center gap-2"
        >
            {({ errors, processing }) => (
                <>
                    <Button
                        type="submit"
                        size="sm"
                        variant={variant}
                        disabled={processing}
                    >
                        {label}
                    </Button>
                    {Object.values(errors).map((error) => (
                        <span
                            key={error}
                            role="alert"
                            className="text-xs text-destructive"
                        >
                            {error}
                        </span>
                    ))}
                </>
            )}
        </Form>
    );
}
