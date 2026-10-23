import { Link, usePage } from '@inertiajs/react';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { ReportHeader } from '@/components/report-header';
import { formatMoney } from '@/lib/money';
import { show as receiptShow } from '@/routes/receipts';

type Props = {
    from: string;
    to: string;
    bySupplier: {
        id: string;
        supplier: string;
        receipts: number;
        receivedMinor: number;
        returnedMinor: number;
        netMinor: number;
    }[];
    byItem: {
        id: string;
        item: string;
        quantity: number;
        totalMinor: number;
        averageCostMinor: number;
        lowestCostMinor: number;
        highestCostMinor: number;
    }[];
    receipts: {
        id: string;
        number: string;
        receivedOn: string;
        supplier: string;
        supplierReference: string | null;
        totalMinor: number;
    }[];
    totals: { receivedMinor: number; returnedMinor: number; netMinor: number };
};

/**
 * What the branch bought in the period, from whom, and at what cost.
 */
export default function Purchases({
    from,
    to,
    bySupplier,
    byItem,
    receipts,
    totals,
}: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <ReportHeader current="purchases" from={from} to={to} />

            <Panel className="mb-3">
                <PanelHeader
                    title="By supplier"
                    description="Goods received less goods sent back. What is still owed is in Finance, under payables."
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Supplier</TableHead>
                            <TableHead className="text-right">
                                Deliveries
                            </TableHead>
                            <TableHead className="text-right">
                                Received
                            </TableHead>
                            <TableHead className="text-right">
                                Sent back
                            </TableHead>
                            <TableHead className="text-right">Net</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {bySupplier.length === 0 ? (
                            <TableEmpty colSpan={5}>
                                Nothing received in this period.
                            </TableEmpty>
                        ) : (
                            bySupplier.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell className="font-medium">
                                        {row.supplier}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.receipts}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(row.receivedMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.returnedMinor > 0
                                            ? `−${money(row.returnedMinor)}`
                                            : '–'}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {money(row.netMinor)}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                    {bySupplier.length > 0 && (
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={2}>Total</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(totals.receivedMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    −{money(totals.returnedMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(totals.netMinor)}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    )}
                </Table>
            </Panel>

            <div className="grid gap-3 xl:grid-cols-2">
                <Panel>
                    <PanelHeader
                        title="By medicine"
                        description="Unit cost per base unit, so pack sizes compare."
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Medicine</TableHead>
                                <TableHead className="text-right">
                                    Units
                                </TableHead>
                                <TableHead className="text-right">
                                    Spent
                                </TableHead>
                                <TableHead className="text-right">
                                    Unit cost
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {byItem.length === 0 ? (
                                <TableEmpty colSpan={4}>Nothing.</TableEmpty>
                            ) : (
                                byItem.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell className="font-medium">
                                            {row.item}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.quantity}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(row.totalMinor)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(row.averageCostMinor)}
                                            {row.lowestCostMinor !==
                                                row.highestCostMinor && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {money(row.lowestCostMinor)}{' '}
                                                    –{' '}
                                                    {money(
                                                        row.highestCostMinor,
                                                    )}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Panel>

                <Panel>
                    <PanelHeader title="Deliveries" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Received</TableHead>
                                <TableHead>Supplier</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {receipts.length === 0 ? (
                                <TableEmpty colSpan={4}>Nothing.</TableEmpty>
                            ) : (
                                receipts.map((receipt) => (
                                    <TableRow key={receipt.id}>
                                        <TableCell>
                                            <Link
                                                href={receiptShow(receipt.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {receipt.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {receipt.receivedOn}
                                        </TableCell>
                                        <TableCell>
                                            {receipt.supplier}
                                            {receipt.supplierReference && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {receipt.supplierReference}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(receipt.totalMinor)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Panel>
            </div>
        </>
    );
}
