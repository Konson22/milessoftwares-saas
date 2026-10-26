import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid } from '@/components/field';
import { index, store, update } from '@/routes/customers';

type Customer = {
    id: string;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    active: boolean;
};

/**
 * Adding or changing a customer.
 */
export default function CustomerForm({
    customer,
}: {
    customer: Customer | null;
}) {
    const action = customer ? update.form(customer.id) : store.form();
    const title = customer ? customer.name : 'Add a customer';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Customers</Link>
                    </Button>
                }
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={customer?.name}
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                <Field
                                    id="phone"
                                    label="Phone"
                                    error={errors.phone}
                                >
                                    <Input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        defaultValue={customer?.phone ?? ''}
                                        {...invalid('phone', errors.phone)}
                                    />
                                </Field>
                                <Field
                                    id="email"
                                    label="Email"
                                    error={errors.email}
                                >
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        defaultValue={customer?.email ?? ''}
                                        {...invalid('email', errors.email)}
                                    />
                                </Field>
                                <Field
                                    id="address"
                                    label="Address"
                                    error={errors.address}
                                    className="sm:col-span-2"
                                    hint="Printed on invoices."
                                >
                                    <Input
                                        id="address"
                                        name="address"
                                        defaultValue={customer?.address ?? ''}
                                        {...invalid('address', errors.address)}
                                    />
                                </Field>
                                {customer && (
                                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                        <input
                                            type="hidden"
                                            name="active"
                                            value="0"
                                        />
                                        <input
                                            type="checkbox"
                                            name="active"
                                            value="1"
                                            defaultChecked={customer.active}
                                        />
                                        Active: new shipments can be booked for
                                        this customer
                                    </label>
                                )}
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {customer ? 'Save' : 'Add customer'}
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
