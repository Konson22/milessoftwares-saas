import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { create as addCustomer } from '@/routes/customers';
import { index, show, store, update } from '@/routes/shipments';

type Shipment = {
    id: string;
    number: string;
    customerId: string;
    pickupAddress: string;
    deliveryAddress: string;
    recipientName: string;
    recipientPhone: string | null;
    goods: string;
    packages: number;
    weightKg: number | null;
    charge: string;
    pickupOn: string | null;
};

type Props = {
    shipment: Shipment | null;
    customers: { id: string; name: string }[];
    customerId: string | null;
};

/**
 * Booking a shipment, or changing one before it is on the road.
 */
export default function ShipmentForm({
    shipment,
    customers,
    customerId,
}: Props) {
    const action = shipment ? update.form(shipment.id) : store.form();
    const title = shipment ? `Shipment ${shipment.number}` : 'Book a shipment';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={shipment ? show(shipment.id) : index()}>
                            {shipment ? '← Shipment' : '← Shipments'}
                        </Link>
                    </Button>
                }
            />

            <Panel className="max-w-3xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="customer_id"
                                    label="Customer"
                                    error={errors.customer_id}
                                    className="sm:col-span-2"
                                >
                                    <Select
                                        id="customer_id"
                                        name="customer_id"
                                        required
                                        defaultValue={customerId ?? ''}
                                        placeholder="Choose the customer"
                                        options={customers.map((customer) => ({
                                            value: customer.id,
                                            label: customer.name,
                                        }))}
                                        {...invalid(
                                            'customer_id',
                                            errors.customer_id,
                                        )}
                                    />
                                    <Link
                                        href={addCustomer()}
                                        className="text-xs text-primary hover:underline"
                                    >
                                        New customer? Add them first.
                                    </Link>
                                </Field>

                                <Field
                                    id="pickup_address"
                                    label="Pick up from"
                                    error={errors.pickup_address}
                                >
                                    <Input
                                        id="pickup_address"
                                        name="pickup_address"
                                        required
                                        defaultValue={shipment?.pickupAddress}
                                        {...invalid(
                                            'pickup_address',
                                            errors.pickup_address,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="delivery_address"
                                    label="Deliver to"
                                    error={errors.delivery_address}
                                >
                                    <Input
                                        id="delivery_address"
                                        name="delivery_address"
                                        required
                                        defaultValue={shipment?.deliveryAddress}
                                        {...invalid(
                                            'delivery_address',
                                            errors.delivery_address,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="recipient_name"
                                    label="Recipient"
                                    error={errors.recipient_name}
                                >
                                    <Input
                                        id="recipient_name"
                                        name="recipient_name"
                                        required
                                        defaultValue={shipment?.recipientName}
                                        {...invalid(
                                            'recipient_name',
                                            errors.recipient_name,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="recipient_phone"
                                    label="Recipient's phone"
                                    error={errors.recipient_phone}
                                >
                                    <Input
                                        id="recipient_phone"
                                        name="recipient_phone"
                                        type="tel"
                                        defaultValue={
                                            shipment?.recipientPhone ?? ''
                                        }
                                        {...invalid(
                                            'recipient_phone',
                                            errors.recipient_phone,
                                        )}
                                    />
                                </Field>

                                <Field
                                    id="goods"
                                    label="Goods"
                                    error={errors.goods}
                                    hint="What is carried: 40 bags of cement, 2 pallets of water…"
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="goods"
                                        name="goods"
                                        required
                                        defaultValue={shipment?.goods}
                                        {...invalid('goods', errors.goods)}
                                    />
                                </Field>
                                <Field
                                    id="packages"
                                    label="Packages"
                                    error={errors.packages}
                                >
                                    <Input
                                        id="packages"
                                        name="packages"
                                        type="number"
                                        min={1}
                                        required
                                        defaultValue={shipment?.packages ?? 1}
                                        {...invalid(
                                            'packages',
                                            errors.packages,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="weight_kg"
                                    label="Weight (kg)"
                                    error={errors.weight_kg}
                                >
                                    <Input
                                        id="weight_kg"
                                        name="weight_kg"
                                        inputMode="decimal"
                                        defaultValue={shipment?.weightKg ?? ''}
                                        {...invalid(
                                            'weight_kg',
                                            errors.weight_kg,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="charge"
                                    label="Charge to the customer"
                                    error={errors.charge}
                                >
                                    <Input
                                        id="charge"
                                        name="charge"
                                        inputMode="decimal"
                                        required
                                        defaultValue={shipment?.charge}
                                        {...invalid('charge', errors.charge)}
                                    />
                                </Field>
                                <Field
                                    id="pickup_on"
                                    label="Pick up on"
                                    error={errors.pickup_on}
                                >
                                    <Input
                                        id="pickup_on"
                                        name="pickup_on"
                                        type="date"
                                        defaultValue={shipment?.pickupOn ?? ''}
                                        {...invalid(
                                            'pickup_on',
                                            errors.pickup_on,
                                        )}
                                    />
                                </Field>

                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {shipment ? 'Save' : 'Book shipment'}
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
