import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { CurrencySelect } from '@/components/currency-select';
import { Field, Select } from '@/components/field';
import { show, store } from '@/routes/purchases';

type Order = {
    id: string;
    number: string;
    supplier: string;
    status: string;
    orderedOn: string;
};

export default function Purchases({
    orders,
    suppliers,
    products,
    canManage,
}: {
    orders: Order[];
    suppliers: { id: string; name: string }[];
    products: { id: string; name: string }[];
    tanks: { id: string; code: string; fuel_product_id: string }[];
    canManage: boolean;
}) {
    const { errors, currency } = usePage().props;

    return (
        <>
            <Head title="Purchases" />
            <PageHeader
                title="Purchases"
                description="The measured quantity enters the tank. Finance receives the bill."
            />
            <Panel className="mb-4">
                <PanelBody>
                    <ul className="text-sm">
                        {orders.map((order) => (
                            <li
                                key={order.id}
                                className="flex items-center gap-2"
                            >
                                <Link
                                    href={show(order.id)}
                                    className="underline"
                                >
                                    {order.number}
                                </Link>
                                <span>
                                    {order.supplier} · {order.orderedOn}
                                </span>
                                <StatusBadge tone="info">
                                    {order.status}
                                </StatusBadge>
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
            {canManage && (
                <Panel>
                    <PanelHeader title="New order" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="supplier_id"
                                label="Supplier"
                                error={errors.supplier_id}
                            >
                                <Select
                                    id="supplier_id"
                                    name="supplier_id"
                                    required
                                    placeholder="Supplier"
                                    options={suppliers.map((row) => ({
                                        value: row.id,
                                        label: row.name,
                                    }))}
                                />
                            </Field>
                            <Field id="fuel_product_id" label="Grade">
                                <Select
                                    id="fuel_product_id"
                                    name="lines[0][fuel_product_id]"
                                    required
                                    placeholder="Grade"
                                    options={products.map((row) => ({
                                        value: row.id,
                                        label: row.name,
                                    }))}
                                />
                            </Field>
                            <Field
                                id="litres"
                                label="Litres"
                                error={errors.lines}
                            >
                                <Input
                                    id="litres"
                                    name="lines[0][litres]"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="unit_cost" label="Cost per litre">
                                <Input
                                    id="unit_cost"
                                    name="lines[0][unit_cost]"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="line_currency" label="Currency">
                                <CurrencySelect
                                    id="line_currency"
                                    name="lines[0][currency]"
                                    defaultValue={currency}
                                />
                            </Field>
                            <Button type="submit">Place order</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
