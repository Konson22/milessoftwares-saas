import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { CurrencySelect } from '@/components/currency-select';
import { Field, Select } from '@/components/field';
import { useMoney } from '@/lib/use-money';
import { store } from '@/routes/suppliers';
import { store as pay } from '@/routes/suppliers/payments';

type Supplier = {
    id: string;
    name: string;
    phone: string | null;
    active: boolean;
    outstandingMinor: number;
};

export default function Suppliers({
    suppliers,
    methods,
    canManage,
    canPay,
}: {
    suppliers: Supplier[];
    methods: { id: string; name: string; kind: string }[];
    canManage: boolean;
    canPay: boolean;
}) {
    const money = useMoney();
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Suppliers" />
            <PageHeader
                title="Suppliers"
                description="A payment recorded here does not settle the bill in Finance. Finance still shows the payable until it is paid there."
            />
            <Panel>
                <PanelHeader title="Accounts" />
                <PanelBody className="grid gap-4">
                    {suppliers.map((supplier) => (
                        <div
                            key={supplier.id}
                            className="grid gap-2 border-b pb-3 text-sm"
                        >
                            <p>
                                {supplier.name} · outstanding{' '}
                                {money(supplier.outstandingMinor)}
                            </p>
                            {canPay && supplier.outstandingMinor > 0 && (
                                <Form
                                    {...pay.form(supplier.id)}
                                    className="grid gap-2 md:grid-cols-4"
                                >
                                    <CurrencySelect
                                        id={`currency-${supplier.id}`}
                                        defaultValue="SSP"
                                    />
                                    <Input
                                        name="amount"
                                        inputMode="decimal"
                                        placeholder="Amount"
                                        aria-label="Amount"
                                        required
                                    />
                                    <Select
                                        name="method_id"
                                        aria-label="Method"
                                        required
                                        placeholder="Method"
                                        options={methods.map((row) => ({
                                            value: row.id,
                                            label: row.name,
                                        }))}
                                    />
                                    <Input
                                        name="reference"
                                        placeholder="Reference"
                                        aria-label="Reference"
                                    />
                                    <Button type="submit" size="sm">
                                        Pay
                                    </Button>
                                </Form>
                            )}
                        </div>
                    ))}
                    {canManage && (
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <Field id="name" label="Name" error={errors.name}>
                                <Input id="name" name="name" required />
                            </Field>
                            <Field id="phone" label="Phone">
                                <Input id="phone" name="phone" />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Add supplier</Button>
                            </div>
                        </Form>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
