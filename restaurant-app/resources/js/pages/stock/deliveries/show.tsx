import { Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { show as drink } from '@/routes/stock';
import { index } from '@/routes/stock/deliveries';

type Props = {
    delivery: {
        id: string;
        number: string;
        supplierName: string;
        supplierReference: string | null;
        receivedOn: string;
        receivedBy: string;
        note: string | null;
        totalMinor: number;
        lines: {
            id: string;
            itemId: string;
            name: string;
            unit: string;
            quantity: number;
            unitCostMinor: number;
            totalMinor: number;
        }[];
    };
};

/**
 * One delivery note: the drinks it brought and what they cost.
 */
export default function DeliveryShow({ delivery }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title={`Delivery ${delivery.number}`} />
            <PageHeader
                title={`Delivery ${delivery.number}`}
                description={`From ${delivery.supplierName}${delivery.supplierReference ? ` (${delivery.supplierReference})` : ''} · received ${delivery.receivedOn} by ${delivery.receivedBy}`}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Deliveries</Link>
                    </Button>
                }
            />

            {delivery.note && (
                <p className="mb-3 text-sm text-muted-foreground">
                    {delivery.note}
                </p>
            )}

            <Panel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Drink</TableHead>
                            <TableHead className="text-right">
                                Quantity
                            </TableHead>
                            <TableHead className="text-right">
                                Cost of one
                            </TableHead>
                            <TableHead className="text-right">Total</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {delivery.lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell>
                                    <Link
                                        href={drink(line.itemId)}
                                        className="font-medium hover:underline"
                                    >
                                        {line.name}
                                    </Link>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {line.quantity} {line.unit}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.unitCostMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.totalMinor)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={3}>
                                Owed to the supplier
                            </TableCell>
                            <TableCell className="text-right font-semibold tabular-nums">
                                {money(delivery.totalMinor)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
