import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { useMoney } from '@/lib/use-money';
import { show, store } from '@/routes/customers';

type Customer = {
    id: string;
    name: string;
    kind: string;
    phone: string | null;
    creditLimitMinor: number;
    balanceMinor: number;
    active: boolean;
};

export default function Customers({
    customers,
    canManage,
}: {
    customers: Customer[];
    canManage: boolean;
}) {
    const money = useMoney();
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Customers" />
            <PageHeader
                title="Customers"
                description="Credit is the sum of the ledger, shared across stations."
            />
            <Panel>
                <PanelHeader title="Accounts" />
                <PanelBody className="grid gap-4">
                    <ul className="text-sm">
                        {customers.map((customer) => (
                            <li key={customer.id}>
                                <Link
                                    href={show(customer.id)}
                                    className="underline"
                                >
                                    {customer.name}
                                </Link>{' '}
                                owes {money(customer.balanceMinor)} of{' '}
                                {money(customer.creditLimitMinor)}
                            </li>
                        ))}
                    </ul>
                    {canManage && (
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field id="name" label="Name" error={errors.name}>
                                <Input id="name" name="name" required />
                            </Field>
                            <Field id="kind" label="Kind">
                                <Select
                                    id="kind"
                                    name="kind"
                                    defaultValue="individual"
                                    options={[
                                        {
                                            value: 'individual',
                                            label: 'Individual',
                                        },
                                        {
                                            value: 'corporate',
                                            label: 'Corporate',
                                        },
                                        { value: 'ngo', label: 'NGO' },
                                        {
                                            value: 'government',
                                            label: 'Government',
                                        },
                                        { value: 'fleet', label: 'Fleet' },
                                    ]}
                                />
                            </Field>
                            <Field id="phone" label="Phone">
                                <Input id="phone" name="phone" />
                            </Field>
                            <Field
                                id="credit_limit"
                                label="Credit limit"
                                error={errors.credit_limit}
                            >
                                <Input
                                    id="credit_limit"
                                    name="credit_limit"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Button type="submit">Add customer</Button>
                        </Form>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
