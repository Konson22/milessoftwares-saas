import { Form } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';

/**
 * One field and a button: actions that need a reason or note on record.
 */
export function ReasonForm({
    action,
    field,
    label,
    placeholder = 'Reason',
}: {
    action: string;
    field: string;
    label: string;
    placeholder?: string;
}) {
    return (
        <Form
            action={action}
            method="post"
            options={{ preserveScroll: true }}
            className="flex flex-wrap items-start gap-2"
        >
            {({ errors, processing }) => (
                <>
                    <div>
                        <Input
                            name={field}
                            placeholder={placeholder}
                            required
                            aria-label={`${placeholder} to ${label.toLowerCase()}`}
                            className="h-8 w-48"
                        />
                        <InputError message={errors[field]} />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        variant="ghost"
                        disabled={processing}
                    >
                        {label}
                    </Button>
                </>
            )}
        </Form>
    );
}
