import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import { store as storeBay } from '@/routes/service/bays';
import { issue } from '@/routes/service/lines';
import { store as storeOffering } from '@/routes/service/offerings';
import {
    parts,
    payments,
    status,
    store as storeOrder,
} from '@/routes/service/orders';
import { store as storeVehicle } from '@/routes/service/vehicles';

type Line = {
    id: string;
    description: string;
    issued: boolean;
    kind: string;
};

type Order = {
    id: string;
    number: string;
    status: string;
    technician: string | null;
    lines: Line[];
};

export default function Service({
    bays,
    offerings,
    orders,
    customers,
    vehicles,
    stores,
    products,
}: {
    bays: {
        id: string;
        name: string;
        code: string;
        status: string;
        supervisor: string | null;
    }[];
    offerings: { id: string; name: string; code: string }[];
    orders: Order[];
    customers: { id: string; name: string }[];
    vehicles: { id: string; customer_id: string; registration: string }[];
    stores: { id: string; name: string }[];
    products: { id: string; name: string }[];
}) {
    const { errors } = usePage().props;
    const openJobs = orders.filter(
        (order) => order.status === 'open' || order.status === 'in_progress',
    ).length;

    return (
        <>
            <Head title="Service bay" />
            <PageHeader
                title="Service bay"
                description="Parts stay in the store until a line is issued. Issuing the same line twice does not move stock again."
            />
            <p className="mb-4 text-sm text-muted-foreground">
                {bays.length} bay{bays.length === 1 ? '' : 's'} · {openJobs}{' '}
                open job{openJobs === 1 ? '' : 's'} · {offerings.length}{' '}
                services
            </p>
            <div className="grid gap-4 lg:grid-cols-2">
                <Panel>
                    <PanelHeader title="Bays" />
                    <PanelBody className="grid gap-3">
                        <ul className="text-sm">
                            {bays.map((bay) => (
                                <li key={bay.id}>
                                    {bay.code} · {bay.name} · {bay.status}
                                    {bay.supervisor
                                        ? ` · ${bay.supervisor}`
                                        : ''}
                                </li>
                            ))}
                        </ul>
                        <Form
                            {...storeBay.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="bay_name"
                                label="Name"
                                error={errors.name}
                            >
                                <Input id="bay_name" name="name" required />
                            </Field>
                            <Field
                                id="bay_code"
                                label="Code"
                                error={errors.code}
                            >
                                <Input id="bay_code" name="code" required />
                            </Field>
                            <Field id="supervisor" label="Supervisor">
                                <Input id="supervisor" name="supervisor" />
                            </Field>
                            <div className="flex items-end">
                                <Button type="submit">Save bay</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader title="Services" />
                    <PanelBody className="grid gap-3">
                        <ul className="text-sm">
                            {offerings.map((offering) => (
                                <li key={offering.id}>
                                    {offering.code} · {offering.name}
                                </li>
                            ))}
                        </ul>
                        <Form
                            {...storeOffering.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="offering_name"
                                label="Name"
                                error={errors.name}
                            >
                                <Input
                                    id="offering_name"
                                    name="name"
                                    required
                                />
                            </Field>
                            <Field
                                id="offering_code"
                                label="Code"
                                error={errors.code}
                            >
                                <Input
                                    id="offering_code"
                                    name="code"
                                    required
                                />
                            </Field>
                            <Field
                                id="price"
                                label="Price"
                                error={errors.price}
                            >
                                <Input
                                    id="price"
                                    name="price"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <div className="flex items-end">
                                <Button type="submit">Save service</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader title="Vehicle" />
                    <PanelBody>
                        <Form
                            {...storeVehicle.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="vehicle_customer_id"
                                label="Customer"
                                error={errors.customer_id}
                            >
                                <Select
                                    id="vehicle_customer_id"
                                    name="customer_id"
                                    options={customers.map((customer) => ({
                                        value: customer.id,
                                        label: customer.name,
                                    }))}
                                />
                            </Field>
                            <Field
                                id="registration"
                                label="Registration"
                                error={errors.registration}
                            >
                                <Input
                                    id="registration"
                                    name="registration"
                                    required
                                />
                            </Field>
                            <Field id="details" label="Details">
                                <Input id="details" name="details" />
                            </Field>
                            <div className="flex items-end">
                                <Button type="submit">Save vehicle</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader title="Open a work order" />
                    <PanelBody>
                        <Form
                            {...storeOrder.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="service_bay_id"
                                label="Bay"
                                error={errors.service_bay_id}
                            >
                                <Select
                                    id="service_bay_id"
                                    name="service_bay_id"
                                    options={bays.map((bay) => ({
                                        value: bay.id,
                                        label: `${bay.code} · ${bay.name}`,
                                    }))}
                                />
                            </Field>
                            <Field
                                id="order_customer_id"
                                label="Customer"
                                error={errors.customer_id}
                            >
                                <Select
                                    id="order_customer_id"
                                    name="customer_id"
                                    options={customers.map((customer) => ({
                                        value: customer.id,
                                        label: customer.name,
                                    }))}
                                />
                            </Field>
                            <Field
                                id="vehicle_id"
                                label="Vehicle"
                                error={errors.vehicle_id}
                            >
                                <Select
                                    id="vehicle_id"
                                    name="vehicle_id"
                                    options={vehicles.map((vehicle) => ({
                                        value: vehicle.id,
                                        label: vehicle.registration,
                                    }))}
                                />
                            </Field>
                            <Field id="technician" label="Technician">
                                <Input id="technician" name="technician" />
                            </Field>
                            <Field id="odometer" label="Odometer">
                                <Input
                                    id="odometer"
                                    name="odometer"
                                    inputMode="numeric"
                                />
                            </Field>
                            <div className="flex items-end">
                                <Button type="submit">Open order</Button>
                            </div>
                        </Form>
                    </PanelBody>
                </Panel>
            </div>
            <div className="mt-4 grid gap-4">
                {orders.map((order) => (
                    <Panel key={order.id}>
                        <PanelHeader
                            title={`${order.number} · ${order.status}`}
                            description={order.technician ?? undefined}
                        />
                        <PanelBody className="grid gap-3">
                            <ul className="text-sm">
                                {order.lines.map((line) => (
                                    <li
                                        key={line.id}
                                        className="flex items-center justify-between gap-3"
                                    >
                                        <span>
                                            {line.description}
                                            {line.kind === 'part'
                                                ? line.issued
                                                    ? ' · issued'
                                                    : ' · not issued'
                                                : ''}
                                        </span>
                                        {line.kind === 'part' &&
                                            !line.issued && (
                                                <Form {...issue.form(line.id)}>
                                                    <Button
                                                        type="submit"
                                                        variant="outline"
                                                        size="sm"
                                                    >
                                                        Issue part
                                                    </Button>
                                                </Form>
                                            )}
                                    </li>
                                ))}
                            </ul>
                            <Form
                                {...parts.form(order.id)}
                                className="grid gap-3 md:grid-cols-4"
                            >
                                <Field id={`store-${order.id}`} label="Store">
                                    <Select
                                        id={`store-${order.id}`}
                                        name="store_id"
                                        options={stores.map((store) => ({
                                            value: store.id,
                                            label: store.name,
                                        }))}
                                    />
                                </Field>
                                <Field id={`product-${order.id}`} label="Part">
                                    <Select
                                        id={`product-${order.id}`}
                                        name="product_id"
                                        options={products.map((product) => ({
                                            value: product.id,
                                            label: product.name,
                                        }))}
                                    />
                                </Field>
                                <Field id={`qty-${order.id}`} label="Quantity">
                                    <Input
                                        id={`qty-${order.id}`}
                                        name="quantity"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Field id={`amount-${order.id}`} label="Amount">
                                    <Input
                                        id={`amount-${order.id}`}
                                        name="amount"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Button type="submit">Add part</Button>
                            </Form>
                            <Form
                                {...status.form(order.id)}
                                className="grid gap-3 md:grid-cols-3"
                            >
                                <Field
                                    id={`status-${order.id}`}
                                    label="Next status"
                                >
                                    <Select
                                        id={`status-${order.id}`}
                                        name="status"
                                        options={[
                                            {
                                                value: 'in_progress',
                                                label: 'In progress',
                                            },
                                            {
                                                value: 'completed',
                                                label: 'Completed',
                                            },
                                            {
                                                value: 'invoiced',
                                                label: 'Invoiced',
                                            },
                                            {
                                                value: 'closed',
                                                label: 'Closed',
                                            },
                                            {
                                                value: 'cancelled',
                                                label: 'Cancelled',
                                            },
                                        ]}
                                    />
                                </Field>
                                <Field id={`labor-${order.id}`} label="Labor">
                                    <Input
                                        id={`labor-${order.id}`}
                                        name="labor"
                                        inputMode="decimal"
                                        defaultValue="0"
                                    />
                                </Field>
                                <div className="flex items-end">
                                    <Button type="submit">Update order</Button>
                                </div>
                            </Form>
                            <Form
                                {...payments.form(order.id)}
                                className="grid gap-3 md:grid-cols-3"
                            >
                                <Field id={`pay-${order.id}`} label="Payment">
                                    <Input
                                        id={`pay-${order.id}`}
                                        name="amount"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Field id={`kind-${order.id}`} label="Method">
                                    <Select
                                        id={`kind-${order.id}`}
                                        name="kind"
                                        defaultValue="cash"
                                        options={[
                                            { value: 'cash', label: 'Cash' },
                                            {
                                                value: 'mobile_money',
                                                label: 'Mobile money',
                                            },
                                            {
                                                value: 'bank_transfer',
                                                label: 'Bank',
                                            },
                                        ]}
                                    />
                                </Field>
                                <div className="flex items-end">
                                    <Button type="submit">
                                        Record payment
                                    </Button>
                                </div>
                            </Form>
                        </PanelBody>
                    </Panel>
                ))}
            </div>
        </>
    );
}
