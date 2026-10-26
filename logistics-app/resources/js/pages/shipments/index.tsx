import { Form, Head, Link } from '@inertiajs/react';
import { Package, PackagePlus } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
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
import { create, index, show } from '@/routes/shipments';

type Shipment = {
    id: string;
    number: string;
    customer: string;
    recipient: string;
    pickupAddress: string;
    deliveryAddress: string;
    status: string;
    trip: string | null;
    chargeMinor: number;
    bookedAt: string;
};

type Props = {
    shipments: Shipment[];
    filters: { search: string; status: string; customer: string };
    customers: { id: string; name: string }[];
    canBook: boolean;
};

const STATUS_FILTERS = [
    { value: 'booked', label: 'Waiting for a trip' },
    { value: 'assigned', label: 'On a planned trip' },
    { value: 'in_transit', label: 'In transit' },
    { value: 'failed', label: 'Not delivered' },
    { value: 'delivered', label: 'Delivered' },
    { value: 'cancelled', label: 'Cancelled' },
];

export default function Shipments({
    shipments,
    filters,
    customers,
    canBook,
}: Props) {
    const money = useMoney();
    const book = canBook ? (
        <Button asChild>
            <Link href={create()}>
                <PackagePlus className="size-4" aria-hidden />
                Book a shipment
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Shipments" />
            <PageHeader
                title="Shipments"
                description="From booking to proof of delivery."
                actions={book}
            />

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} className="flex flex-wrap gap-2">
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Number, recipient, goods, place"
                            aria-label="Search shipments"
                        />
                        <Select
                            name="status"
                            aria-label="Status"
                            defaultValue={filters.status}
                            placeholder="Open work"
                            options={STATUS_FILTERS}
                            className="w-44"
                        />
                        <Select
                            name="customer"
                            aria-label="Customer"
                            defaultValue={filters.customer}
                            placeholder="Every customer"
                            options={customers.map((customer) => ({
                                value: customer.id,
                                label: customer.name,
                            }))}
                            className="w-48"
                        />
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>

                {shipments.length === 0 ? (
                    <EmptyState
                        icon={Package}
                        title="No shipments here"
                        action={book}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Shipment</TableHead>
                                <TableHead>Customer</TableHead>
                                <TableHead>Route</TableHead>
                                <TableHead>Trip</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Charge
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shipments.map((shipment) => (
                                <TableRow key={shipment.id}>
                                    <TableCell>
                                        <Link
                                            href={show(shipment.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {shipment.number}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {formatDate(shipment.bookedAt)}
                                        </p>
                                    </TableCell>
                                    <TableCell>
                                        {shipment.customer}
                                        <p className="text-xs text-muted-foreground">
                                            To {shipment.recipient}
                                        </p>
                                    </TableCell>
                                    <TableCell className="max-w-72 text-sm">
                                        <span className="text-muted-foreground">
                                            {shipment.pickupAddress} →{' '}
                                        </span>
                                        {shipment.deliveryAddress}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {shipment.trip ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={shipment.status} />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(shipment.chargeMinor)}
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
