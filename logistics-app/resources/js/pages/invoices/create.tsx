import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, invalid, Select } from '@/components/field';
import { formatDateTime } from '@/lib/status';
import { useMoney } from '@/lib/use-money';
import { create, index, store } from '@/routes/invoices';

type Shipment = {
    id: string;
    number: string;
    goods: string;
    deliveryAddress: string;
    deliveredAt: string | null;
    chargeMinor: number;
};

type Props = {
    customers: { id: string; name: string }[];
    customerId: string | null;
    shipments: Shipment[];
};

/**
 * Invoicing a customer for shipments delivered and not yet billed.
 */
export default function CreateInvoice({
    customers,
    customerId,
    shipments,
}: Props) {
    return (
        <>
            <Head title="New invoice" />
            <PageHeader
                title="New invoice"
                description="Bill a customer for what was delivered."
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Invoices</Link>
                    </Button>
                }
            />

            <Panel className="max-w-4xl">
                <PanelBody className="border-b">
                    {customers.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Every delivered shipment is invoiced.
                        </p>
                    ) : (
                        <Field
                            id="customer"
                            label="Customer"
                            hint="Customers with delivered shipments not yet invoiced."
                        >
                            <Select
                                id="customer"
                                value={customerId ?? ''}
                                placeholder="Choose a customer"
                                options={customers.map((customer) => ({
                                    value: customer.id,
                                    label: customer.name,
                                }))}
                                onChange={(event) =>
                                    router.get(
                                        create({
                                            query: {
                                                customer:
                                                    event.target.value ||
                                                    undefined,
                                            },
                                        }).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className="max-w-sm"
                            />
                        </Field>
                    )}
                </PanelBody>

                {customerId &&
                    shipments.length > 0 && (
                        // A fresh choice for each customer.
                        <ShipmentPicker
                            key={customerId}
                            customerId={customerId}
                            shipments={shipments}
                        />
                    )}
            </Panel>
        </>
    );
}

/**
 * The customer's delivered shipments, all chosen to start with.
 */
function ShipmentPicker({
    customerId,
    shipments,
}: {
    customerId: string;
    shipments: Shipment[];
}) {
    const money = useMoney();
    const [chosen, setChosen] = useState<string[]>(() =>
        shipments.map((shipment) => shipment.id),
    );
    const total = shipments
        .filter((shipment) => chosen.includes(shipment.id))
        .reduce((sum, shipment) => sum + shipment.chargeMinor, 0);

    const toggle = (id: string) =>
        setChosen((ids) =>
            ids.includes(id)
                ? ids.filter((other) => other !== id)
                : [...ids, id],
        );

    return (
        <Form {...store.form()}>
            {({ errors, processing }) => (
                <>
                    <input
                        type="hidden"
                        name="customer_id"
                        value={customerId}
                    />
                    {chosen.map((id) => (
                        <input
                            key={id}
                            type="hidden"
                            name="shipment_ids[]"
                            value={id}
                        />
                    ))}
                    <PanelHeader
                        title="Delivered shipments"
                        description={`${chosen.length} chosen · ${money(total)}`}
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">
                                    <span className="sr-only">Invoice</span>
                                </TableHead>
                                <TableHead>Shipment</TableHead>
                                <TableHead>Delivered</TableHead>
                                <TableHead className="text-right">
                                    Charge
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shipments.map((shipment) => (
                                <TableRow key={shipment.id}>
                                    <TableCell>
                                        <input
                                            type="checkbox"
                                            aria-label={`Invoice ${shipment.number}`}
                                            checked={chosen.includes(
                                                shipment.id,
                                            )}
                                            onChange={() => toggle(shipment.id)}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <span className="font-medium">
                                            {shipment.number}
                                        </span>
                                        <p className="text-xs text-muted-foreground">
                                            {shipment.goods} ·{' '}
                                            {shipment.deliveryAddress}
                                        </p>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {shipment.deliveredAt
                                            ? formatDateTime(
                                                  shipment.deliveredAt,
                                              )
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(shipment.chargeMinor)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <PanelBody className="flex flex-wrap items-end gap-3 border-t">
                        <Field id="due_on" label="Due on" error={errors.due_on}>
                            <Input
                                id="due_on"
                                name="due_on"
                                type="date"
                                className="w-44"
                                {...invalid('due_on', errors.due_on)}
                            />
                        </Field>
                        <Button
                            type="submit"
                            disabled={processing || chosen.length === 0}
                        >
                            Issue invoice for {money(total)}
                        </Button>
                        {errors.shipment_ids && (
                            <p className="text-sm text-destructive">
                                {errors.shipment_ids}
                            </p>
                        )}
                    </PanelBody>
                </>
            )}
        </Form>
    );
}
