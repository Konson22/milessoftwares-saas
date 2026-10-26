import { Form, Head, Link } from '@inertiajs/react';
import { Pencil, Wrench } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import { Field, invalid } from '@/components/field';
import { Status } from '@/components/status';
import { formatDate } from '@/lib/status';
import { useMoney } from '@/lib/use-money';
import { show as showTrip } from '@/routes/trips';
import { edit, index } from '@/routes/vehicles';
import { store as recordMaintenance } from '@/routes/vehicles/maintenance';

type Props = {
    vehicle: {
        id: string;
        registration: string;
        kindLabel: string;
        description: string | null;
        capacityKg: number | null;
        odometerKm: number;
        status: string;
    };
    trips: {
        id: string;
        number: string;
        plannedOn: string;
        route: string | null;
        driver: string;
        status: string;
        distanceKm: number | null;
    }[];
    maintenance: {
        id: string;
        performedOn: string;
        description: string;
        garage: string | null;
        odometerKm: number | null;
        costMinor: number;
        recordedBy: string;
    }[];
    maintenanceCostMinor: number;
    canManage: boolean;
};

export default function VehicleShow({
    vehicle,
    trips,
    maintenance,
    maintenanceCostMinor,
    canManage,
}: Props) {
    const money = useMoney();
    const today = new Date().toISOString().slice(0, 10);

    return (
        <>
            <Head title={vehicle.registration} />
            <PageHeader
                title={vehicle.registration}
                description={[
                    vehicle.kindLabel,
                    vehicle.description,
                    vehicle.capacityKg
                        ? `${vehicle.capacityKg.toLocaleString()} kg`
                        : null,
                    `${vehicle.odometerKm.toLocaleString()} km`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                badges={<Status status={vehicle.status} />}
                actions={
                    <>
                        {canManage && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit(vehicle.id)}>
                                    <Pencil className="size-4" aria-hidden />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Vehicles</Link>
                        </Button>
                    </>
                }
            />

            <div className="grid gap-4">
                <Panel>
                    <PanelHeader
                        title="Maintenance"
                        description={`${money(maintenanceCostMinor)} in all`}
                    />
                    {canManage && (
                        <PanelBody className="border-b">
                            <Form
                                {...recordMaintenance.form(vehicle.id)}
                                resetOnSuccess
                                className="grid gap-3 sm:grid-cols-[9rem_1fr_1fr_8rem_8rem_auto] sm:items-end"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="performed_on"
                                            label="Date"
                                            error={errors.performed_on}
                                        >
                                            <Input
                                                id="performed_on"
                                                name="performed_on"
                                                type="date"
                                                required
                                                defaultValue={today}
                                                {...invalid(
                                                    'performed_on',
                                                    errors.performed_on,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="description"
                                            label="What was done"
                                            error={errors.description}
                                        >
                                            <Input
                                                id="description"
                                                name="description"
                                                required
                                                placeholder="Oil and filters"
                                                {...invalid(
                                                    'description',
                                                    errors.description,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="garage"
                                            label="Garage"
                                            error={errors.garage}
                                        >
                                            <Input id="garage" name="garage" />
                                        </Field>
                                        <Field
                                            id="odometer_km"
                                            label="Odometer"
                                            error={errors.odometer_km}
                                        >
                                            <Input
                                                id="odometer_km"
                                                name="odometer_km"
                                                type="number"
                                                min={0}
                                                {...invalid(
                                                    'odometer_km',
                                                    errors.odometer_km,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="cost"
                                            label="Cost"
                                            error={errors.cost}
                                        >
                                            <Input
                                                id="cost"
                                                name="cost"
                                                inputMode="decimal"
                                                required
                                                {...invalid(
                                                    'cost',
                                                    errors.cost,
                                                )}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            <Wrench
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Record
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
                                <TableHead>Work</TableHead>
                                <TableHead className="text-right">
                                    Odometer
                                </TableHead>
                                <TableHead>Recorded by</TableHead>
                                <TableHead className="text-right">
                                    Cost
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {maintenance.length === 0 && (
                                <TableEmpty colSpan={5}>
                                    No maintenance recorded.
                                </TableEmpty>
                            )}
                            {maintenance.map((record) => (
                                <TableRow key={record.id}>
                                    <TableCell className="tabular-nums">
                                        {formatDate(record.performedOn)}
                                    </TableCell>
                                    <TableCell>
                                        {record.description}
                                        {record.garage && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {record.garage}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {record.odometerKm === null
                                            ? '—'
                                            : `${record.odometerKm.toLocaleString()} km`}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {record.recordedBy}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(record.costMinor)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>

                <Panel>
                    <PanelHeader title="Recent trips" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Trip</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Route</TableHead>
                                <TableHead>Driver</TableHead>
                                <TableHead className="text-right">
                                    Distance
                                </TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {trips.length === 0 && (
                                <TableEmpty colSpan={6}>
                                    No trips yet.
                                </TableEmpty>
                            )}
                            {trips.map((trip) => (
                                <TableRow key={trip.id}>
                                    <TableCell>
                                        <Link
                                            href={showTrip(trip.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {trip.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(trip.plannedOn)}
                                    </TableCell>
                                    <TableCell>{trip.route ?? '—'}</TableCell>
                                    <TableCell>{trip.driver}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {trip.distanceKm === null
                                            ? '—'
                                            : `${trip.distanceKm.toLocaleString()} km`}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={trip.status} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            </div>
        </>
    );
}
