import { Form, Head, Link } from '@inertiajs/react';
import { CheckCircle2, MapPin, Pencil, XCircle } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { Status } from '@/components/status';
import { formatDate, formatDateTime, statusLabel } from '@/lib/status';
import { useMoney } from '@/lib/use-money';
import { show as showInvoice } from '@/routes/invoices';
import { cancel, deliver, edit, fail, index, track } from '@/routes/shipments';
import { show as showTrip } from '@/routes/trips';
import { store as loadOntoTrip } from '@/routes/trips/shipments';

type Event = {
    id: string;
    status: string;
    location: string | null;
    note: string | null;
    recordedBy: string;
    occurredAt: string;
};

type Shipment = {
    id: string;
    number: string;
    status: string;
    pickupAddress: string;
    deliveryAddress: string;
    recipientName: string;
    recipientPhone: string | null;
    goods: string;
    packages: number;
    weightKg: number | null;
    chargeMinor: number;
    pickupOn: string | null;
    editable: boolean;
    customer: { id: string; name: string; phone: string | null };
    bookedBy: string;
    bookedAt: string;
    trip: {
        id: string;
        number: string;
        status: string;
        vehicle: string;
        driver: string;
        driverPhone: string | null;
    } | null;
    invoice: { id: string; number: string; status: string } | null;
    deliveredAt: string | null;
    receivedBy: string | null;
    deliveryNote: string | null;
    cancelReason: string | null;
    events: Event[];
};

type Props = {
    shipment: Shipment;
    trips: { id: string; label: string }[];
    can: { manage: boolean; deliver: boolean; plan: boolean };
};

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </>
    );
}

export default function ShipmentShow({ shipment, trips, can }: Props) {
    const money = useMoney();
    const [tripId, setTripId] = useState(trips[0]?.id ?? '');
    const onTheRoad = shipment.status === 'in_transit';

    return (
        <>
            <Head title={`Shipment ${shipment.number}`} />
            <PageHeader
                title={`Shipment ${shipment.number}`}
                description={`${shipment.customer.name} · booked ${formatDateTime(shipment.bookedAt)} by ${shipment.bookedBy}`}
                badges={<Status status={shipment.status} />}
                actions={
                    <>
                        {can.manage && shipment.editable && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit(shipment.id)}>
                                    <Pencil className="size-4" aria-hidden />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Shipments</Link>
                        </Button>
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
                <div className="grid content-start gap-4">
                    <Panel>
                        <PanelHeader title="Details" />
                        <PanelBody>
                            <dl className="grid grid-cols-[9rem_1fr] gap-x-4 gap-y-2 text-sm">
                                <Detail label="Pick up from">
                                    {shipment.pickupAddress}
                                    {shipment.pickupOn && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            on {formatDate(shipment.pickupOn)}
                                        </span>
                                    )}
                                </Detail>
                                <Detail label="Deliver to">
                                    {shipment.deliveryAddress}
                                </Detail>
                                <Detail label="Recipient">
                                    {shipment.recipientName}
                                    {shipment.recipientPhone &&
                                        ` · ${shipment.recipientPhone}`}
                                </Detail>
                                <Detail label="Goods">{shipment.goods}</Detail>
                                <Detail label="Packages">
                                    {shipment.packages}
                                    {shipment.weightKg !== null &&
                                        ` · ${shipment.weightKg} kg`}
                                </Detail>
                                <Detail label="Charge">
                                    {money(shipment.chargeMinor)}
                                </Detail>
                                <Detail label="Customer">
                                    {shipment.customer.name}
                                    {shipment.customer.phone &&
                                        ` · ${shipment.customer.phone}`}
                                </Detail>
                                <Detail label="Trip">
                                    {shipment.trip ? (
                                        <>
                                            <Link
                                                href={showTrip(
                                                    shipment.trip.id,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {shipment.trip.number}
                                            </Link>{' '}
                                            · {shipment.trip.vehicle} ·{' '}
                                            {shipment.trip.driver}
                                            {shipment.trip.driverPhone &&
                                                ` (${shipment.trip.driverPhone})`}
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </Detail>
                                <Detail label="Invoice">
                                    {shipment.invoice ? (
                                        <Link
                                            href={showInvoice(
                                                shipment.invoice.id,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {shipment.invoice.number}
                                        </Link>
                                    ) : shipment.status === 'delivered' ? (
                                        'Not invoiced yet'
                                    ) : (
                                        '—'
                                    )}
                                </Detail>
                                {shipment.deliveredAt && (
                                    <Detail label="Proof of delivery">
                                        Received by{' '}
                                        <strong>{shipment.receivedBy}</strong>{' '}
                                        on{' '}
                                        {formatDateTime(shipment.deliveredAt)}
                                        {shipment.deliveryNote && (
                                            <span className="block text-muted-foreground">
                                                {shipment.deliveryNote}
                                            </span>
                                        )}
                                    </Detail>
                                )}
                                {shipment.cancelReason && (
                                    <Detail label="Cancelled">
                                        {shipment.cancelReason}
                                    </Detail>
                                )}
                            </dl>
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title="Tracking"
                            description="Every step, oldest first."
                        />
                        <PanelBody>
                            <ol className="relative grid gap-4 border-l pl-5">
                                {shipment.events.map((event) => (
                                    <li key={event.id} className="relative">
                                        <span
                                            className="absolute top-1.5 -left-[1.6rem] size-2.5 rounded-full border-2 border-background bg-primary"
                                            aria-hidden
                                        />
                                        <p className="text-sm font-medium">
                                            {statusLabel(event.status)}
                                            {event.location && (
                                                <span className="font-normal text-muted-foreground">
                                                    {' '}
                                                    · {event.location}
                                                </span>
                                            )}
                                        </p>
                                        {event.note && (
                                            <p className="text-sm">
                                                {event.note}
                                            </p>
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            {formatDateTime(event.occurredAt)} ·{' '}
                                            {event.recordedBy}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </PanelBody>
                    </Panel>
                </div>

                <div className="grid content-start gap-4">
                    {can.deliver && onTheRoad && (
                        <>
                            <Panel>
                                <PanelHeader
                                    title="Delivered"
                                    description="Proof of delivery."
                                />
                                <PanelBody>
                                    <Form
                                        {...deliver.form(shipment.id)}
                                        className="grid gap-3"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <Field
                                                    id="received_by"
                                                    label="Received by"
                                                    error={errors.received_by}
                                                >
                                                    <Input
                                                        id="received_by"
                                                        name="received_by"
                                                        required
                                                        defaultValue={
                                                            shipment.recipientName
                                                        }
                                                        {...invalid(
                                                            'received_by',
                                                            errors.received_by,
                                                        )}
                                                    />
                                                </Field>
                                                <Field
                                                    id="delivery_note"
                                                    label="Note"
                                                    error={errors.note}
                                                    hint="Condition, signature reference…"
                                                >
                                                    <Input
                                                        id="delivery_note"
                                                        name="note"
                                                    />
                                                </Field>
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    <CheckCircle2
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                    Confirm delivery
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </PanelBody>
                            </Panel>

                            <Panel>
                                <PanelHeader title="Update tracking" />
                                <PanelBody>
                                    <Form
                                        {...track.form(shipment.id)}
                                        resetOnSuccess
                                        className="grid gap-3"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <Field
                                                    id="location"
                                                    label="Where it is now"
                                                    error={errors.location}
                                                >
                                                    <Input
                                                        id="location"
                                                        name="location"
                                                        placeholder="Nimule border post"
                                                        {...invalid(
                                                            'location',
                                                            errors.location,
                                                        )}
                                                    />
                                                </Field>
                                                <Field
                                                    id="track_note"
                                                    label="Note"
                                                    error={errors.note}
                                                >
                                                    <Input
                                                        id="track_note"
                                                        name="note"
                                                        placeholder="Cleared customs"
                                                    />
                                                </Field>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    <MapPin
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                    Add update
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </PanelBody>
                            </Panel>

                            <Panel>
                                <PanelHeader
                                    title="Could not deliver"
                                    description="Booked again when the trip ends."
                                />
                                <PanelBody>
                                    <Form
                                        {...fail.form(shipment.id)}
                                        className="grid gap-3"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <Field
                                                    id="fail_reason"
                                                    label="Why"
                                                    error={errors.reason}
                                                >
                                                    <Input
                                                        id="fail_reason"
                                                        name="reason"
                                                        required
                                                        placeholder="Recipient not available"
                                                        {...invalid(
                                                            'fail_reason',
                                                            errors.reason,
                                                        )}
                                                    />
                                                </Field>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    <XCircle
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                    Mark not delivered
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                </PanelBody>
                            </Panel>
                        </>
                    )}

                    {can.plan && shipment.status === 'booked' && (
                        <Panel>
                            <PanelHeader title="Put on a trip" />
                            <PanelBody>
                                {trips.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No trips are planned or on the road.
                                    </p>
                                ) : (
                                    <Form
                                        {...loadOntoTrip.form(tripId)}
                                        className="grid gap-3"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="shipment_ids[]"
                                                    value={shipment.id}
                                                />
                                                <Field
                                                    id="trip"
                                                    label="Trip"
                                                    error={errors.shipment_ids}
                                                >
                                                    <Select
                                                        id="trip"
                                                        value={tripId}
                                                        onChange={(event) =>
                                                            setTripId(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        options={trips.map(
                                                            (trip) => ({
                                                                value: trip.id,
                                                                label: trip.label,
                                                            }),
                                                        )}
                                                    />
                                                </Field>
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        processing ||
                                                        tripId === ''
                                                    }
                                                >
                                                    Load onto trip
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                )}
                            </PanelBody>
                        </Panel>
                    )}

                    {can.manage && shipment.status === 'booked' && (
                        <Panel>
                            <PanelHeader title="Cancel shipment" />
                            <PanelBody>
                                <Form
                                    {...cancel.form(shipment.id)}
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="cancel_reason"
                                                label="Reason"
                                                error={errors.reason}
                                            >
                                                <Input
                                                    id="cancel_reason"
                                                    name="reason"
                                                    required
                                                    {...invalid(
                                                        'cancel_reason',
                                                        errors.reason,
                                                    )}
                                                />
                                            </Field>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Cancel shipment
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}
                </div>
            </div>
        </>
    );
}
