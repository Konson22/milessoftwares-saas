import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { index, show, store, update } from '@/routes/customers';

type Customer = {
    id: string;
    number: string;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    active: boolean;
};

const FIELDS = [
    ['name', 'Name', 'text', true],
    ['phone', 'Phone', 'tel', false],
    ['email', 'Email', 'email', false],
    ['address', 'Address', 'text', false],
] as const;

/**
 * Registering or editing a customer, so their purchases are kept together
 * across branches. Only the name is needed.
 */
export default function CustomerForm({
    customer,
}: {
    customer: Customer | null;
}) {
    const action = customer ? update.form(customer.id) : store.form();
    const values: Record<string, string | null | undefined> = {
        name: customer?.name,
        phone: customer?.phone,
        email: customer?.email,
        address: customer?.address,
    };

    return (
        <>
            <Head
                title={customer ? `Edit ${customer.name}` : 'Register customer'}
            />
            <PageHeader
                title={customer ? `Edit ${customer.name}` : 'Register customer'}
                description={customer?.number}
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                {FIELDS.map(([name, label, type, required]) => (
                                    <div key={name} className="grid gap-1.5">
                                        <Label htmlFor={name}>{label}</Label>
                                        <Input
                                            id={name}
                                            name={name}
                                            type={type}
                                            required={required}
                                            defaultValue={values[name] ?? ''}
                                            aria-invalid={
                                                errors[name] ? true : undefined
                                            }
                                        />
                                        {errors[name] && (
                                            <p className="text-sm text-destructive">
                                                {errors[name]}
                                            </p>
                                        )}
                                    </div>
                                ))}
                                <div className="sm:col-span-2">
                                    <input
                                        type="hidden"
                                        name="active"
                                        value="0"
                                    />
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="active"
                                            value="1"
                                            defaultChecked={
                                                customer?.active ?? true
                                            }
                                        />
                                        Active (can be chosen at the counter)
                                    </label>
                                </div>
                                <div className="flex gap-3 sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        Save
                                    </Button>
                                    <Button variant="ghost" asChild>
                                        <Link
                                            href={
                                                customer
                                                    ? show(customer.id)
                                                    : index()
                                            }
                                        >
                                            Cancel
                                        </Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>
        </>
    );
}
