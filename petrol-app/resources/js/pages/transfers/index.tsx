import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import {
    approve as approveFuel,
    complete as completeFuel,
    dispatch as dispatchFuel,
    receive as receiveFuel,
    store as requestFuel,
} from '@/routes/transfers/fuel';
import {
    approve as approveStore,
    complete as completeStore,
    dispatch as dispatchStore,
    receive as receiveStore,
    store as requestStore,
} from '@/routes/transfers/store';

function litres(milli: number | null): string {
    if (milli === null) {
        return '—';
    }

    return (milli / 1000).toFixed(3);
}

type FuelMove = {
    id: string;
    number: string;
    status: string;
    requested_milli: number;
    dispatched_milli: number | null;
    received_milli: number | null;
    outgoing: boolean;
    incoming: boolean;
};

type StoreMove = {
    id: string;
    number: string;
    status: string;
    requested_milli: number;
    outgoing: boolean;
    incoming: boolean;
};

export default function Transfers({
    branchId,
    fuel,
    store,
    tanks,
    stores,
    products,
}: {
    branchId: string;
    fuel: FuelMove[];
    store: StoreMove[];
    tanks: { id: string; branch_id: string; code: string; name: string }[];
    stores: { id: string; branch_id: string; code: string; name: string }[];
    products: { id: string; name: string }[];
}) {
    const { errors } = usePage().props;

    return (
        <>
            <Head title="Transfers" />
            <PageHeader
                title="Transfers"
                description="Requested, approved, dispatched, received, then completed. Stock leaves the source on dispatch and arrives on receipt, once each."
            />
            <div className="grid gap-4 lg:grid-cols-2">
                <Panel>
                    <PanelHeader title="Fuel" />
                    <PanelBody className="grid gap-4">
                        <ul className="text-sm">
                            {fuel.map((row) => (
                                <li key={row.id} className="mb-3">
                                    <p>
                                        {row.number} · {row.status} · requested{' '}
                                        {litres(row.requested_milli)} L
                                    </p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {row.status === 'requested' &&
                                            row.outgoing && (
                                                <Form
                                                    {...approveFuel.form(
                                                        row.id,
                                                    )}
                                                >
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        Approve
                                                    </Button>
                                                </Form>
                                            )}
                                        {row.status === 'approved' &&
                                            row.outgoing && (
                                                <Form
                                                    {...dispatchFuel.form(
                                                        row.id,
                                                    )}
                                                    className="flex gap-2"
                                                >
                                                    <Input
                                                        name="litres"
                                                        inputMode="decimal"
                                                        placeholder="Litres"
                                                        required
                                                        className="w-28"
                                                    />
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                    >
                                                        Dispatch
                                                    </Button>
                                                </Form>
                                            )}
                                        {row.status === 'dispatched' &&
                                            row.incoming && (
                                                <Form
                                                    {...receiveFuel.form(
                                                        row.id,
                                                    )}
                                                    className="flex gap-2"
                                                >
                                                    <Input
                                                        name="litres"
                                                        inputMode="decimal"
                                                        placeholder="Received"
                                                        required
                                                        className="w-28"
                                                    />
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                    >
                                                        Receive
                                                    </Button>
                                                </Form>
                                            )}
                                        {row.status === 'received' && (
                                            <Form
                                                {...completeFuel.form(row.id)}
                                            >
                                                <Button type="submit" size="sm">
                                                    Complete
                                                </Button>
                                            </Form>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <Form {...requestFuel.form()} className="grid gap-3">
                            <Field
                                id="source_tank_id"
                                label="Source tank"
                                error={errors.source_tank_id}
                            >
                                <Select
                                    id="source_tank_id"
                                    name="source_tank_id"
                                    options={tanks
                                        .filter(
                                            (tank) =>
                                                tank.branch_id === branchId,
                                        )
                                        .map((tank) => ({
                                            value: tank.id,
                                            label: tank.code,
                                        }))}
                                />
                            </Field>
                            <Field
                                id="destination_tank_id"
                                label="Destination tank"
                                error={errors.destination_tank_id}
                            >
                                <Select
                                    id="destination_tank_id"
                                    name="destination_tank_id"
                                    options={tanks
                                        .filter(
                                            (tank) =>
                                                tank.branch_id !== branchId,
                                        )
                                        .map((tank) => ({
                                            value: tank.id,
                                            label: tank.code,
                                        }))}
                                />
                            </Field>
                            <Field
                                id="fuel_litres"
                                label="Litres"
                                error={errors.litres}
                            >
                                <Input
                                    id="fuel_litres"
                                    name="litres"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field
                                id="transport_ref"
                                label="Transport reference"
                            >
                                <Input
                                    id="transport_ref"
                                    name="transport_ref"
                                />
                            </Field>
                            <Button type="submit">Request fuel transfer</Button>
                        </Form>
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader title="Store" />
                    <PanelBody className="grid gap-4">
                        <ul className="text-sm">
                            {store.map((row) => (
                                <li key={row.id} className="mb-3">
                                    <p>
                                        {row.number} · {row.status} · requested{' '}
                                        {litres(row.requested_milli)}
                                    </p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {row.status === 'requested' &&
                                            row.outgoing && (
                                                <Form
                                                    {...approveStore.form(
                                                        row.id,
                                                    )}
                                                >
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        Approve
                                                    </Button>
                                                </Form>
                                            )}
                                        {row.status === 'approved' &&
                                            row.outgoing && (
                                                <Form
                                                    {...dispatchStore.form(
                                                        row.id,
                                                    )}
                                                    className="flex gap-2"
                                                >
                                                    <Input
                                                        name="quantity"
                                                        inputMode="decimal"
                                                        placeholder="Quantity"
                                                        required
                                                        className="w-28"
                                                    />
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                    >
                                                        Dispatch
                                                    </Button>
                                                </Form>
                                            )}
                                        {row.status === 'dispatched' &&
                                            row.incoming && (
                                                <Form
                                                    {...receiveStore.form(
                                                        row.id,
                                                    )}
                                                    className="flex gap-2"
                                                >
                                                    <Input
                                                        name="quantity"
                                                        inputMode="decimal"
                                                        placeholder="Received"
                                                        required
                                                        className="w-28"
                                                    />
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                    >
                                                        Receive
                                                    </Button>
                                                </Form>
                                            )}
                                        {row.status === 'received' && (
                                            <Form
                                                {...completeStore.form(row.id)}
                                            >
                                                <Button type="submit" size="sm">
                                                    Complete
                                                </Button>
                                            </Form>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <Form {...requestStore.form()} className="grid gap-3">
                            <Field
                                id="source_store_id"
                                label="Source store"
                                error={errors.source_store_id}
                            >
                                <Select
                                    id="source_store_id"
                                    name="source_store_id"
                                    options={stores
                                        .filter(
                                            (row) => row.branch_id === branchId,
                                        )
                                        .map((row) => ({
                                            value: row.id,
                                            label: row.code,
                                        }))}
                                />
                            </Field>
                            <Field
                                id="destination_store_id"
                                label="Destination store"
                                error={errors.destination_store_id}
                            >
                                <Select
                                    id="destination_store_id"
                                    name="destination_store_id"
                                    options={stores.map((row) => ({
                                        value: row.id,
                                        label: row.code,
                                    }))}
                                />
                            </Field>
                            <Field
                                id="product_id"
                                label="Product"
                                error={errors.product_id}
                            >
                                <Select
                                    id="product_id"
                                    name="product_id"
                                    options={products.map((product) => ({
                                        value: product.id,
                                        label: product.name,
                                    }))}
                                />
                            </Field>
                            <Field
                                id="quantity"
                                label="Quantity"
                                error={errors.quantity}
                            >
                                <Input
                                    id="quantity"
                                    name="quantity"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Button type="submit">
                                Request store transfer
                            </Button>
                        </Form>
                    </PanelBody>
                </Panel>
            </div>
        </>
    );
}
