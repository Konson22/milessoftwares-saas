import { Form } from '@inertiajs/react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid } from '@/components/field';
import type { pay } from '@/routes/projects/expenses';

/** Recording that approved spending was paid: the date and a reference. */
export function PayForm({
    form,
    id,
    today,
}: {
    form: ReturnType<typeof pay.form>;
    id: string;
    today: string;
}) {
    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            className="flex flex-wrap items-end gap-2"
        >
            {({ errors, processing }) => (
                <>
                    <Field
                        id={`paid-on-${id}`}
                        label="Paid on"
                        error={errors.paid_on}
                    >
                        <Input
                            id={`paid-on-${id}`}
                            name="paid_on"
                            type="date"
                            required
                            max={today}
                            defaultValue={today}
                        />
                    </Field>
                    <Field
                        id={`reference-${id}`}
                        label="Payment reference"
                        error={errors.reference}
                        className="min-w-40 flex-1"
                    >
                        <Input
                            id={`reference-${id}`}
                            name="reference"
                            required
                            {...invalid(`reference-${id}`, errors.reference)}
                        />
                    </Field>
                    <Button type="submit" disabled={processing}>
                        Mark paid
                    </Button>
                </>
            )}
        </Form>
    );
}
