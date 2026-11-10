import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { show, store } from '@/routes/tanks';

type Tank = {
    id: string;
    name: string;
    code: string;
    product: string;
    capacity: string;
    quantity: string;
    status: string;
    low: boolean;
};

export default function Tanks({
    tanks,
    products,
    canManage,
}: {
    tanks: Tank[];
    products: { id: string; name: string }[];
    canManage: boolean;
    canAdjust: boolean;
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Tanks" />
            <PageHeader
                title="Tanks"
                description="Quantity is what the ledger says. A dip is evidence and does not change it."
            />
            <Panel>
                <PanelHeader title="At this station" />
                <PanelBody className="grid gap-4">
                    <ul className="grid gap-2 text-sm">
                        {tanks.map((tank) => (
                            <li
                                key={tank.id}
                                className="flex items-center gap-2"
                            >
                                <Link
                                    href={show(tank.id)}
                                    className="underline"
                                >
                                    {tank.code} {tank.name}
                                </Link>
                                <span>
                                    {tank.quantity} / {tank.capacity} L ·{' '}
                                    {tank.product}
                                </span>
                                {tank.low && (
                                    <StatusBadge tone="warning">
                                        Low
                                    </StatusBadge>
                                )}
                            </li>
                        ))}
                    </ul>
                    {canManage && (
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-3"
                        >
                            <Field id="fuel_product_id" label="Grade">
                                <Select
                                    id="fuel_product_id"
                                    name="fuel_product_id"
                                    required
                                    options={products.map((product) => ({
                                        value: product.id,
                                        label: product.name,
                                    }))}
                                />
                            </Field>
                            <Field id="name" label="Name" error={errors.name}>
                                <Input id="name" name="name" required />
                            </Field>
                            <Field id="code" label="Code" error={errors.code}>
                                <Input id="code" name="code" required />
                            </Field>
                            <Field id="capacity" label="Capacity (L)">
                                <Input
                                    id="capacity"
                                    name="capacity"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field id="status" label="Status">
                                <Select
                                    id="status"
                                    name="status"
                                    defaultValue="active"
                                    options={[
                                        { value: 'active', label: 'Active' },
                                        {
                                            value: 'out_of_service',
                                            label: 'Out of service',
                                        },
                                    ]}
                                />
                            </Field>
                            <div className="self-end">
                                <Button type="submit">Add tank</Button>
                            </div>
                        </Form>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
