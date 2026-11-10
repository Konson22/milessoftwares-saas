import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { CurrencySelect } from '@/components/currency-select';
import { Field, Select } from '@/components/field';
import { useMoney } from '@/lib/use-money';
import { show as showSale } from '@/routes/sales';
import { store as pay } from '@/routes/customers/payments';

type Props = {
    customer: {
        id: string;
        name: string;
        kind: string;
        phone: string | null;
        creditLimitMinor: number;
        balanceMinor: number;
        active: boolean;
    };
    entries: { id: string; kind: string; amountMinor: number; at: string }[];
    sales: {
        id: string;
        number: string;
        litres: string;
        totalMinor: number;
        status: string;
    }[];
    methods: { id: string; name: string; kind: string }[];
    openShift: boolean;
    canCollect: boolean;
};

export default function CustomerShow({
    customer,
    entries,
    sales,
    methods,
    openShift,
    canCollect,
}: Props) {
    const money = useMoney();
    const { currency } = usePage().props;

    return (
        <>
            <Head title={customer.name} />
            <PageHeader
                title={customer.name}
                description={`${customer.kind}. Owes ${money(customer.balanceMinor)} of ${money(customer.creditLimitMinor)}.`}
            />
            <Panel className="mb-4">
                <PanelHeader title="Ledger" />
                <PanelBody>
                    <ul className="text-sm">
                        {entries.map((entry) => (
                            <li key={entry.id}>
                                {entry.kind}: {money(entry.amountMinor)}
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
            <Panel className="mb-4">
                <PanelHeader title="Sales at this station" />
                <PanelBody>
                    <ul className="text-sm">
                        {sales.map((sale) => (
                            <li key={sale.id}>
                                <Link
                                    href={showSale(sale.id)}
                                    className="underline"
                                >
                                    {sale.number}
                                </Link>{' '}
                                {sale.litres} L {money(sale.totalMinor)}
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
            {canCollect && (
                <Panel>
                    <PanelHeader
                        title="Collect a payment"
                        description={
                            openShift
                                ? 'Cash needs the open shift.'
                                : 'Open a shift before taking cash.'
                        }
                    />
                    <PanelBody>
                        <Form
                            {...pay.form(customer.id)}
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <Field id="currency" label="Currency">
                                <CurrencySelect
                                    id="currency"
                                    defaultValue={currency}
                                />
                            </Field>
                            <Field id="amount" label="Amount">
                                <Input
                                    id="amount"
                                    name="amount"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="method_id" label="Method">
                                <Select
                                    id="method_id"
                                    name="method_id"
                                    required
                                    placeholder="Method"
                                    options={methods.map((row) => ({
                                        value: row.id,
                                        label: row.name,
                                    }))}
                                />
                            </Field>
                            <Field id="reference" label="Reference">
                                <Input id="reference" name="reference" />
                            </Field>
                            <Button type="submit">Record payment</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
