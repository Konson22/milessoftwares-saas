import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Route } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Select } from '@/components/field';
import { Status } from '@/components/status';
import { formatDate } from '@/lib/status';
import { useMoney } from '@/lib/use-money';
import { create, index, show } from '@/routes/trips';

type Trip = {
    id: string;
    number: string;
    plannedOn: string;
    route: string | null;
    vehicle: string;
    driver: string;
    status: string;
    shipments: number;
    revenueMinor: number;
    costMinor: number;
};

type Props = {
    trips: Trip[];
    filters: { status: string };
    canPlan: boolean;
};

const STATUS_FILTERS = [
    { value: 'planned', label: 'Planned' },
    { value: 'in_progress', label: 'On the road' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
];

export default function Trips({ trips, filters, canPlan }: Props) {
    const money = useMoney();
    const plan = canPlan ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Plan a trip
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Trips" />
            <PageHeader
                title="Trips"
                description="Vehicles and drivers on the road, and what each trip earned."
                actions={plan}
            />

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} className="flex gap-2">
                        <Select
                            name="status"
                            aria-label="Status"
                            defaultValue={filters.status}
                            placeholder="Planned and on the road"
                            options={STATUS_FILTERS}
                            className="w-56"
                        />
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>

                {trips.length === 0 ? (
                    <EmptyState
                        icon={Route}
                        title="No trips here"
                        action={plan}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Trip</TableHead>
                                <TableHead>Route</TableHead>
                                <TableHead>Vehicle and driver</TableHead>
                                <TableHead className="text-right">
                                    Shipments
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Earned
                                </TableHead>
                                <TableHead className="text-right">
                                    Cost
                                </TableHead>
                                <TableHead className="text-right">
                                    Margin
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {trips.map((trip) => {
                                const margin =
                                    trip.revenueMinor - trip.costMinor;

                                return (
                                    <TableRow key={trip.id}>
                                        <TableCell>
                                            <Link
                                                href={show(trip.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {trip.number}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {formatDate(trip.plannedOn)}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            {trip.route ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {trip.vehicle}
                                            <p className="text-xs text-muted-foreground">
                                                {trip.driver}
                                            </p>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {trip.shipments}
                                        </TableCell>
                                        <TableCell>
                                            <Status status={trip.status} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(trip.revenueMinor)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(trip.costMinor)}
                                        </TableCell>
                                        <TableCell
                                            className={`text-right font-medium tabular-nums ${margin < 0 ? 'text-destructive' : ''}`}
                                        >
                                            {money(margin)}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
