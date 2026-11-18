import { Head, Link, usePage } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { index as stock } from '@/routes/stock';
import { create, show } from '@/routes/stock/deliveries';

type Delivery = {
    id: string;
    number: string;
    supplierName: string;
    supplierReference: string | null;
    receivedOn: string;
    receivedBy: string;
    drinks: number;
    totalMinor: number;
};

type Props = { deliveries: Delivery[]; canReceive: boolean };

/**
 * Drinks suppliers delivered to the branch, newest first.
 */
export default function DeliveriesIndex({ deliveries, canReceive }: Props) {
    const { branch, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title="Deliveries" />
            <PageHeader
                title="Deliveries"
                description={`Drinks suppliers delivered to ${branch?.name ?? 'this branch'}.`}
                actions={
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={stock()}>Drinks stock</Link>
                        </Button>
                        {canReceive && (
                            <Button size="sm" asChild>
                                <Link href={create()}>Receive a delivery</Link>
                            </Button>
                        )}
                    </div>
                }
            />

            <Panel>
                {deliveries.length === 0 ? (
                    <EmptyState icon={Truck} title="No deliveries yet">
                        When a supplier brings drinks, receive them here: stock
                        goes up at what they cost.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Delivery</TableHead>
                                <TableHead>Supplier</TableHead>
                                <TableHead>Received</TableHead>
                                <TableHead className="text-right">
                                    Drinks
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {deliveries.map((delivery) => (
                                <TableRow key={delivery.id}>
                                    <TableCell>
                                        <Link
                                            href={show(delivery.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {delivery.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {delivery.supplierName}
                                        {delivery.supplierReference && (
                                            <span className="block text-xs text-muted-foreground">
                                                {delivery.supplierReference}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {delivery.receivedOn} ·{' '}
                                        {delivery.receivedBy}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {delivery.drinks}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {money(delivery.totalMinor)}
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
