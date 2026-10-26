import { Form, Head, Link } from '@inertiajs/react';
import {
    CheckCircle2,
    Coins,
    Gauge,
    Send,
    Trash2,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, invalid, Select } from '@/components/field';
import { Status } from '@/components/status';
import { formatDate, formatDateTime } from '@/lib/status';
import { useMoney } from '@/lib/use-money';
import { show as showShipment } from '@/routes/shipments';
import { cancel, complete, dispatch, index } from '@/routes/trips';
import { destroy as removeCost, store as addCost } from '@/routes/trips/costs';
import { destroy as unload, store as load } from '@/routes/trips/shipments';
import type { Option } from '@/types';

type Trip = {
    id: string;
    number: string;
    status: string;
    plannedOn: string;
    route: string | null;
    vehicle: { id: string; label: string; odometerKm: number };
    driver: { name: string; phone: string | null };
    startOdometerKm: number | null;
    endOdometerKm: number | null;
    distanceKm: number | null;
    dispatchedAt: string | null;
    completedAt: string | null;
    cancelReason: string | null;
    shipments: {
        id: string;
        number: string;
        customer: string;
        recipient: string;
        deliveryAddress: string;
        goods: string;
        status: string;
        chargeMinor: number;
    }[];
    costs: {
        id: string;
        category: string;
        description: string | null;
        amountMinor: number;
        incurredOn: string;
        recordedBy: string;
    }[];
    revenueMinor: number;
    costMinor: number;
    marginMinor: number;
};

type Props = {
    trip: Trip;
    loadable: {
        id: string;
        number: string;
        customer: string;
        deliveryAddress: string;
        goods: string;
        weightKg: number | null;
    }[];
    costCategories: Option[];
    today: string;
    can: { plan: boolean; deliver: boolean };
};

export default function TripShow({
    trip,
    loadable,
    costCategories,
    today,
    can,
}: Props) {
    const money = useMoney();
    const [chosen, setChosen] = useState<string[]>([]);
    const planned = trip.status === 'planned';
    const onTheRoad = trip.status === 'in_progress';
    const costsOpen = can.plan && trip.status !== 'cancelled';

    const toggle = (id: string) =>
        setChosen((ids) =>
            ids.includes(id)
                ? ids.filter((other) => other !== id)
                : [...ids, id],
        );

    return (
        <>
            <Head title={`Trip ${trip.number}`} />
            <PageHeader
                title={`Trip ${trip.number}`}
                description={[
                    formatDate(trip.plannedOn),
                    trip.route,
                    trip.vehicle.label,
                    trip.driver.name +
                        (trip.driver.phone ? ` (${trip.driver.phone})` : ''),
                ]
                    .filter(Boolean)
                    .join(' · ')}
                badges={<Status status={trip.status} />}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Trips</Link>
                    </Button>
                }
            />

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Earned (delivered)"
                    value={money(trip.revenueMinor)}
                    icon={Wallet}
                    accent="emerald"
                />
                <StatCard
                    label="Costs"
                    value={money(trip.costMinor)}
                    icon={Coins}
                    accent="amber"
                />
                <StatCard
                    label="Margin"
                    value={money(trip.marginMinor)}
                    icon={TrendingUp}
                    accent={trip.marginMinor < 0 ? 'red' : 'blue'}
                />
                <StatCard
                    label="Distance"
                    value={
                        trip.distanceKm === null
                            ? '—'
                            : `${trip.distanceKm.toLocaleString()} km`
                    }
                    hint={
                        trip.startOdometerKm !== null
                            ? `From ${trip.startOdometerKm.toLocaleString()} km`
                            : undefined
                    }
                    icon={Gauge}
                    accent="slate"
                />
            </div>

            {(trip.dispatchedAt || trip.completedAt || trip.cancelReason) && (
                <p className="mt-3 text-sm text-muted-foreground">
                    {trip.dispatchedAt &&
                        `Dispatched ${formatDateTime(trip.dispatchedAt)}. `}
                    {trip.completedAt &&
                        `Completed ${formatDateTime(trip.completedAt)}. `}
                    {trip.cancelReason && `Cancelled: ${trip.cancelReason}`}
                </p>
            )}

            {can.plan && (planned || onTheRoad) && (
                <Panel className="mt-4">
                    <PanelBody className="flex flex-wrap items-end gap-6">
                        {planned && (
                            <Form
                                {...dispatch.form(trip.id)}
                                className="flex flex-wrap items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="start_odometer_km"
                                            label="Odometer at departure (km)"
                                            error={errors.start_odometer_km}
                                        >
                                            <Input
                                                id="start_odometer_km"
                                                name="start_odometer_km"
                                                type="number"
                                                min={0}
                                                defaultValue={
                                                    trip.vehicle.odometerKm
                                                }
                                                className="w-44"
                                                {...invalid(
                                                    'start_odometer_km',
                                                    errors.start_odometer_km,
                                                )}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                trip.shipments.length === 0
                                            }
                                        >
                                            <Send
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Dispatch
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                        {onTheRoad && (
                            <Form
                                {...complete.form(trip.id)}
                                className="flex flex-wrap items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="end_odometer_km"
                                            label="Odometer on return (km)"
                                            error={errors.end_odometer_km}
                                        >
                                            <Input
                                                id="end_odometer_km"
                                                name="end_odometer_km"
                                                type="number"
                                                min={trip.startOdometerKm ?? 0}
                                                className="w-44"
                                                {...invalid(
                                                    'end_odometer_km',
                                                    errors.end_odometer_km,
                                                )}
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
                                            Complete trip
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                        {planned && (
                            <Form
                                {...cancel.form(trip.id)}
                                className="ml-auto flex flex-wrap items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="cancel_reason"
                                            label="Cancel because"
                                            error={errors.reason}
                                        >
                                            <Input
                                                id="cancel_reason"
                                                name="reason"
                                                required
                                                className="w-56"
                                                {...invalid(
                                                    'cancel_reason',
                                                    errors.reason,
                                                )}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Cancel trip
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            )}

            <Panel className="mt-4">
                <PanelHeader
                    title="Shipments"
                    description={
                        onTheRoad && can.deliver
                            ? 'Open a shipment to record its delivery.'
                            : undefined
                    }
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Shipment</TableHead>
                            <TableHead>Customer</TableHead>
                            <TableHead>Deliver to</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="text-right">Charge</TableHead>
                            {can.plan && planned && <TableHead />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {trip.shipments.length === 0 && (
                            <TableEmpty colSpan={6}>
                                No shipments on this trip yet.
                            </TableEmpty>
                        )}
                        {trip.shipments.map((shipment) => (
                            <TableRow key={shipment.id}>
                                <TableCell>
                                    <Link
                                        href={showShipment(shipment.id)}
                                        className="font-medium hover:underline"
                                    >
                                        {shipment.number}
                                    </Link>
                                    <p className="text-xs text-muted-foreground">
                                        {shipment.goods}
                                    </p>
                                </TableCell>
                                <TableCell>{shipment.customer}</TableCell>
                                <TableCell>
                                    {shipment.deliveryAddress}
                                    <p className="text-xs text-muted-foreground">
                                        {shipment.recipient}
                                    </p>
                                </TableCell>
                                <TableCell>
                                    <Status status={shipment.status} />
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(shipment.chargeMinor)}
                                </TableCell>
                                {can.plan && planned && (
                                    <TableCell className="text-right">
                                        <Form
                                            {...unload.form({
                                                trip: trip.id,
                                                shipment: shipment.id,
                                            })}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Take off
                                                </Button>
                                            )}
                                        </Form>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>

            {can.plan && (planned || onTheRoad) && (
                <Panel className="mt-4">
                    <PanelHeader
                        title="Load shipments"
                        description="Booked shipments waiting for a trip."
                    />
                    {loadable.length === 0 ? (
                        <PanelBody className="text-sm text-muted-foreground">
                            No shipments are waiting.
                        </PanelBody>
                    ) : (
                        <Form
                            {...load.form(trip.id)}
                            onSuccess={() => setChosen([])}
                        >
                            {({ errors, processing }) => (
                                <>
                                    {chosen.map((id) => (
                                        <input
                                            key={id}
                                            type="hidden"
                                            name="shipment_ids[]"
                                            value={id}
                                        />
                                    ))}
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-10">
                                                    <span className="sr-only">
                                                        Load
                                                    </span>
                                                </TableHead>
                                                <TableHead>Shipment</TableHead>
                                                <TableHead>Customer</TableHead>
                                                <TableHead>
                                                    Deliver to
                                                </TableHead>
                                                <TableHead className="text-right">
                                                    Weight
                                                </TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {loadable.map((shipment) => (
                                                <TableRow key={shipment.id}>
                                                    <TableCell>
                                                        <input
                                                            type="checkbox"
                                                            aria-label={`Load ${shipment.number}`}
                                                            checked={chosen.includes(
                                                                shipment.id,
                                                            )}
                                                            onChange={() =>
                                                                toggle(
                                                                    shipment.id,
                                                                )
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <span className="font-medium">
                                                            {shipment.number}
                                                        </span>
                                                        <p className="text-xs text-muted-foreground">
                                                            {shipment.goods}
                                                        </p>
                                                    </TableCell>
                                                    <TableCell>
                                                        {shipment.customer}
                                                    </TableCell>
                                                    <TableCell>
                                                        {
                                                            shipment.deliveryAddress
                                                        }
                                                    </TableCell>
                                                    <TableCell className="text-right tabular-nums">
                                                        {shipment.weightKg ===
                                                        null
                                                            ? '—'
                                                            : `${shipment.weightKg.toLocaleString()} kg`}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                    <div className="flex items-center gap-3 border-t px-3 py-2">
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                chosen.length === 0
                                            }
                                        >
                                            Load{' '}
                                            {chosen.length > 0
                                                ? chosen.length
                                                : ''}{' '}
                                            onto {trip.number}
                                        </Button>
                                        {errors.shipment_ids && (
                                            <p className="text-sm text-destructive">
                                                {errors.shipment_ids}
                                            </p>
                                        )}
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </Panel>
            )}

            <Panel className="mt-4">
                <PanelHeader
                    title="Trip costs"
                    description={`${money(trip.costMinor)} in all`}
                />
                {costsOpen && (
                    <PanelBody className="border-b">
                        <Form
                            {...addCost.form(trip.id)}
                            resetOnSuccess
                            className="grid gap-3 sm:grid-cols-[12rem_1fr_9rem_9rem_auto] sm:items-end"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="category"
                                        label="For"
                                        error={errors.category}
                                    >
                                        <Select
                                            id="category"
                                            name="category"
                                            defaultValue="fuel"
                                            options={costCategories}
                                        />
                                    </Field>
                                    <Field
                                        id="cost_description"
                                        label="Details"
                                        error={errors.description}
                                    >
                                        <Input
                                            id="cost_description"
                                            name="description"
                                            placeholder="200 litres diesel"
                                        />
                                    </Field>
                                    <Field
                                        id="incurred_on"
                                        label="Date"
                                        error={errors.incurred_on}
                                    >
                                        <Input
                                            id="incurred_on"
                                            name="incurred_on"
                                            type="date"
                                            required
                                            defaultValue={today}
                                            {...invalid(
                                                'incurred_on',
                                                errors.incurred_on,
                                            )}
                                        />
                                    </Field>
                                    <Field
                                        id="amount"
                                        label="Amount"
                                        error={errors.amount}
                                    >
                                        <Input
                                            id="amount"
                                            name="amount"
                                            inputMode="decimal"
                                            required
                                            {...invalid(
                                                'amount',
                                                errors.amount,
                                            )}
                                        />
                                    </Field>
                                    <Button type="submit" disabled={processing}>
                                        Add cost
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                )}
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>For</TableHead>
                            <TableHead>Recorded by</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            {costsOpen && <TableHead />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {trip.costs.length === 0 && (
                            <TableEmpty colSpan={5}>
                                No costs recorded.
                            </TableEmpty>
                        )}
                        {trip.costs.map((cost) => (
                            <TableRow key={cost.id}>
                                <TableCell className="tabular-nums">
                                    {formatDate(cost.incurredOn)}
                                </TableCell>
                                <TableCell>
                                    {cost.category}
                                    {cost.description && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {cost.description}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {cost.recordedBy}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(cost.amountMinor)}
                                </TableCell>
                                {costsOpen && (
                                    <TableCell className="text-right">
                                        <Form
                                            {...removeCost.form({
                                                trip: trip.id,
                                                cost: cost.id,
                                            })}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={processing}
                                                    aria-label="Remove cost"
                                                >
                                                    <Trash2
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                </Button>
                                            )}
                                        </Form>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
