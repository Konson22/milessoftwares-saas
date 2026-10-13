import { Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/money';
import { show as showOrder } from '@/routes/purchase-orders';
import { index } from '@/routes/receipts';

type Props = {
    receipt: {
        id: string;
        number: string;
        supplier: string;
        order: { id: string; number: string } | null;
        supplierReference: string | null;
        receivedOn: string;
        receivedBy: string;
        totalMinor: number;
        lines: {
            id: string;
            medicine: string;
            batchNumber: string;
            expiresOn: string;
            quantity: number;
            unitCostMinor: number;
            lineTotalMinor: number;
        }[];
    };
};

export default function ShowReceipt({ receipt }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title={receipt.number} />
            <PageHeader
                title={receipt.number}
                description={
                    <>
                        {receipt.supplier} · received {receipt.receivedOn} by{' '}
                        {receipt.receivedBy}
                        {receipt.supplierReference &&
                            ` · ${receipt.supplierReference}`}
                        {receipt.order && (
                            <>
                                {' · '}
                                <Link
                                    href={showOrder(receipt.order.id)}
                                    className="underline"
                                >
                                    {receipt.order.number}
                                </Link>
                            </>
                        )}
                    </>
                }
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Goods received</Link>
                    </Button>
                }
            />

            <Panel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medicine</TableHead>
                            <TableHead>Batch</TableHead>
                            <TableHead>Expires</TableHead>
                            <TableHead className="text-right">
                                Quantity
                            </TableHead>
                            <TableHead className="text-right">
                                Unit cost
                            </TableHead>
                            <TableHead className="text-right">Total</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {receipt.lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell>{line.medicine}</TableCell>
                                <TableCell>{line.batchNumber}</TableCell>
                                <TableCell className="tabular-nums">
                                    {line.expiresOn}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {line.quantity}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.unitCostMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.lineTotalMinor)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell
                                colSpan={5}
                                className="text-right font-medium"
                            >
                                Owed to supplier
                            </TableCell>
                            <TableCell className="text-right font-medium tabular-nums">
                                {money(receipt.totalMinor)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
