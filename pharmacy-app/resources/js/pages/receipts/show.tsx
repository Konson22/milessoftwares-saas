import { Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
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
import { useCanHere } from '@/lib/access';
import { formatMoney } from '@/lib/money';
import { show as showOrder } from '@/routes/purchase-orders';
import { index } from '@/routes/receipts';
import {
    create as returnGoods,
    show as showReturn,
} from '@/routes/supplier-returns';

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
            returned: number;
            unitCostMinor: number;
            lineTotalMinor: number;
        }[];
    };
    returns: {
        id: string;
        number: string;
        returnedOn: string;
        totalMinor: number;
        reason: string;
    }[];
    canReturn: boolean;
};

export default function ShowReceipt({ receipt, returns, canReturn }: Props) {
    const { currency } = usePage().props;
    const canHere = useCanHere();
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
                    <>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Goods received</Link>
                        </Button>
                        {canReturn &&
                            canHere('pharmacy.purchasing.receive') && (
                                <Button size="sm" asChild>
                                    <Link href={returnGoods(receipt.id)}>
                                        Return goods
                                    </Link>
                                </Button>
                            )}
                    </>
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
                                Returned
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
                                <TableCell className="text-right text-muted-foreground tabular-nums">
                                    {line.returned > 0 ? line.returned : ''}
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
                                colSpan={6}
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

            {returns.length > 0 && (
                <Panel className="mt-5">
                    <PanelHeader title="Returned to the supplier" />
                    <ul className="divide-y text-sm">
                        {returns.map((supplierReturn) => (
                            <li
                                key={supplierReturn.id}
                                className="flex flex-wrap items-center gap-x-4 px-4 py-2.5"
                            >
                                <Link
                                    href={showReturn(supplierReturn.id)}
                                    className="font-medium hover:underline"
                                >
                                    {supplierReturn.number}
                                </Link>
                                <span className="text-muted-foreground tabular-nums">
                                    {supplierReturn.returnedOn}
                                </span>
                                <span className="text-muted-foreground">
                                    {supplierReturn.reason}
                                </span>
                                <span className="ml-auto tabular-nums">
                                    {money(supplierReturn.totalMinor)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </>
    );
}
