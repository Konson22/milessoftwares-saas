import { Head, Link } from '@inertiajs/react';
import { Plus, Truck } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Status } from '@/components/status';
import { create, show } from '@/routes/vehicles';

type Vehicle = {
    id: string;
    registration: string;
    kindLabel: string;
    description: string | null;
    capacityKg: number | null;
    odometerKm: number;
    status: string;
    onTrip: string | null;
};

export default function Vehicles({
    vehicles,
    canManage,
}: {
    vehicles: Vehicle[];
    canManage: boolean;
}) {
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Add a vehicle
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Vehicles" />
            <PageHeader
                title="Vehicles"
                description="The fleet, its trips and maintenance."
                actions={add}
            />

            <Panel>
                {vehicles.length === 0 ? (
                    <EmptyState
                        icon={Truck}
                        title="No vehicles yet"
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Registration</TableHead>
                                <TableHead>Vehicle</TableHead>
                                <TableHead className="text-right">
                                    Capacity
                                </TableHead>
                                <TableHead className="text-right">
                                    Odometer
                                </TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {vehicles.map((vehicle) => (
                                <TableRow key={vehicle.id}>
                                    <TableCell>
                                        <Link
                                            href={show(vehicle.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {vehicle.registration}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {vehicle.kindLabel}
                                        {vehicle.description && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {vehicle.description}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {vehicle.capacityKg === null
                                            ? '—'
                                            : `${vehicle.capacityKg.toLocaleString()} kg`}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {vehicle.odometerKm.toLocaleString()} km
                                    </TableCell>
                                    <TableCell>
                                        {vehicle.onTrip ? (
                                            <StatusBadge tone="warning">
                                                On trip {vehicle.onTrip}
                                            </StatusBadge>
                                        ) : (
                                            <Status status={vehicle.status} />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
