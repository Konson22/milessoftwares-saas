import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import {
    adjust,
    count,
    issue,
    products as storeProduct,
    receive,
    stores as storeStore,
} from '@/routes/stores';

function litres(milli: number): string {
    return (milli / 1000).toFixed(3);
}

export default function StoresPage({
    stores,
    products,
    balances,
    canManage,
    canMove,
}: {
    stores: { id: string; name: string; code: string }[];
    products: {
        id: string;
        name: string;
        code: string;
        unit: string;
        reorder_milli: number | null;
    }[];
    balances: {
        store_id: string;
        product_id: string;
        quantity_milli: number;
        average_cost_minor: number;
    }[];
    canManage: boolean;
    canMove: boolean;
}) {
    const { errors } = usePage().props;
    const [receiveKey] = useState(() => crypto.randomUUID());
    const [issueKey] = useState(() => crypto.randomUUID());
    const [adjustKey] = useState(() => crypto.randomUUID());
    const [countKey] = useState(() => crypto.randomUUID());
    const storeName = Object.fromEntries(
        stores.map((store) => [store.id, store.code]),
    );
    const productName = Object.fromEntries(
        products.map((product) => [product.id, product.name]),
    );

    return (
        <>
            <Head title="Store" />
            <PageHeader
                title="Store"
                description="Non-fuel stock. Fuel tanks are a separate ledger. Balances change only when a movement is posted."
            />
            <Panel className="mb-4">
                <PanelBody>
                    {balances.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No stock posted yet.
                        </p>
                    ) : (
                        <ul className="text-sm">
                            {balances.map((balance) => (
                                <li
                                    key={`${balance.store_id}-${balance.product_id}`}
                                >
                                    {storeName[balance.store_id] ?? 'Store'} ·{' '}
                                    {productName[balance.product_id] ??
                                        'Product'}{' '}
                                    · {litres(balance.quantity_milli)}
                                </li>
                            ))}
                        </ul>
                    )}
                </PanelBody>
            </Panel>
            <div className="grid gap-4 lg:grid-cols-2">
                {canManage && (
                    <Panel>
                        <PanelHeader title="Store and product" />
                        <PanelBody className="grid gap-4">
                            <Form
                                {...storeStore.form()}
                                className="grid gap-3 md:grid-cols-2"
                            >
                                <Field
                                    id="store_name"
                                    label="Store name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="store_name"
                                        name="name"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="store_code"
                                    label="Code"
                                    error={errors.code}
                                >
                                    <Input
                                        id="store_code"
                                        name="code"
                                        required
                                    />
                                </Field>
                                <Button type="submit">Save store</Button>
                            </Form>
                            <Form
                                {...storeProduct.form()}
                                className="grid gap-3 md:grid-cols-2"
                            >
                                <Field
                                    id="product_name"
                                    label="Product"
                                    error={errors.name}
                                >
                                    <Input
                                        id="product_name"
                                        name="name"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="product_code"
                                    label="Code"
                                    error={errors.code}
                                >
                                    <Input
                                        id="product_code"
                                        name="code"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="unit"
                                    label="Unit"
                                    error={errors.unit}
                                >
                                    <Input
                                        id="unit"
                                        name="unit"
                                        defaultValue="each"
                                        required
                                    />
                                </Field>
                                <Field id="category" label="Category">
                                    <Input id="category" name="category" />
                                </Field>
                                <Field id="reorder" label="Reorder level">
                                    <Input
                                        id="reorder"
                                        name="reorder"
                                        inputMode="decimal"
                                    />
                                </Field>
                                <div className="flex items-end">
                                    <Button type="submit">Save product</Button>
                                </div>
                            </Form>
                        </PanelBody>
                    </Panel>
                )}
                {canMove && (
                    <Panel>
                        <PanelHeader title="Receive and issue" />
                        <PanelBody className="grid gap-4">
                            <Form
                                {...receive.form()}
                                className="grid gap-3 md:grid-cols-2"
                            >
                                <input
                                    type="hidden"
                                    name="idempotency_key"
                                    value={receiveKey}
                                />
                                <Field id="receive_store" label="Store">
                                    <Select
                                        id="receive_store"
                                        name="store_id"
                                        options={stores.map((store) => ({
                                            value: store.id,
                                            label: store.name,
                                        }))}
                                    />
                                </Field>
                                <Field id="receive_product" label="Product">
                                    <Select
                                        id="receive_product"
                                        name="product_id"
                                        options={products.map((product) => ({
                                            value: product.id,
                                            label: product.name,
                                        }))}
                                    />
                                </Field>
                                <Field
                                    id="receive_quantity"
                                    label="Quantity"
                                    error={errors.quantity}
                                >
                                    <Input
                                        id="receive_quantity"
                                        name="quantity"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Field
                                    id="cost"
                                    label="Unit cost"
                                    error={errors.cost}
                                >
                                    <Input
                                        id="cost"
                                        name="cost"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Button type="submit">Receive</Button>
                            </Form>
                            <Form
                                {...issue.form()}
                                className="grid gap-3 md:grid-cols-2"
                            >
                                <input
                                    type="hidden"
                                    name="idempotency_key"
                                    value={issueKey}
                                />
                                <Field id="issue_store" label="Store">
                                    <Select
                                        id="issue_store"
                                        name="store_id"
                                        options={stores.map((store) => ({
                                            value: store.id,
                                            label: store.name,
                                        }))}
                                    />
                                </Field>
                                <Field id="issue_product" label="Product">
                                    <Select
                                        id="issue_product"
                                        name="product_id"
                                        options={products.map((product) => ({
                                            value: product.id,
                                            label: product.name,
                                        }))}
                                    />
                                </Field>
                                <Field
                                    id="issue_quantity"
                                    label="Quantity"
                                    error={errors.quantity}
                                >
                                    <Input
                                        id="issue_quantity"
                                        name="quantity"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <div className="flex items-end">
                                    <Button type="submit">Issue</Button>
                                </div>
                            </Form>
                        </PanelBody>
                    </Panel>
                )}
                {canManage && (
                    <Panel>
                        <PanelHeader
                            title="Adjustment"
                            description="An adjustment posts one ledger line. Repeating the same key does not post again."
                        />
                        <PanelBody>
                            <Form
                                {...adjust.form()}
                                className="grid gap-3 md:grid-cols-2"
                            >
                                <input
                                    type="hidden"
                                    name="idempotency_key"
                                    value={adjustKey}
                                />
                                <Field id="adjust_store" label="Store">
                                    <Select
                                        id="adjust_store"
                                        name="store_id"
                                        options={stores.map((store) => ({
                                            value: store.id,
                                            label: store.name,
                                        }))}
                                    />
                                </Field>
                                <Field id="adjust_product" label="Product">
                                    <Select
                                        id="adjust_product"
                                        name="product_id"
                                        options={products.map((product) => ({
                                            value: product.id,
                                            label: product.name,
                                        }))}
                                    />
                                </Field>
                                <Field id="direction" label="Direction">
                                    <Select
                                        id="direction"
                                        name="direction"
                                        defaultValue="increase"
                                        options={[
                                            {
                                                value: 'increase',
                                                label: 'Increase',
                                            },
                                            {
                                                value: 'decrease',
                                                label: 'Decrease',
                                            },
                                        ]}
                                    />
                                </Field>
                                <Field
                                    id="adjust_quantity"
                                    label="Quantity"
                                    error={errors.quantity}
                                >
                                    <Input
                                        id="adjust_quantity"
                                        name="quantity"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <Button type="submit">Adjust</Button>
                            </Form>
                        </PanelBody>
                    </Panel>
                )}
                {canMove && (
                    <Panel>
                        <PanelHeader
                            title="Stock count"
                            description="The count sets the balance to what was counted and records the difference."
                        />
                        <PanelBody>
                            <Form
                                {...count.form()}
                                className="grid gap-3 md:grid-cols-2"
                            >
                                <input
                                    type="hidden"
                                    name="idempotency_key"
                                    value={countKey}
                                />
                                <Field id="count_store" label="Store">
                                    <Select
                                        id="count_store"
                                        name="store_id"
                                        options={stores.map((store) => ({
                                            value: store.id,
                                            label: store.name,
                                        }))}
                                    />
                                </Field>
                                <Field id="count_product" label="Product">
                                    <Select
                                        id="count_product"
                                        name="product_id"
                                        options={products.map((product) => ({
                                            value: product.id,
                                            label: product.name,
                                        }))}
                                    />
                                </Field>
                                <Field
                                    id="count_quantity"
                                    label="Counted quantity"
                                    error={errors.quantity}
                                >
                                    <Input
                                        id="count_quantity"
                                        name="quantity"
                                        inputMode="decimal"
                                        required
                                    />
                                </Field>
                                <div className="flex items-end">
                                    <Button type="submit">Record count</Button>
                                </div>
                            </Form>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}
