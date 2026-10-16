import { Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
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
import { show as showReceipt } from '@/routes/receipts';

type Props = {
    supplierReturn: {
        id: string;
        number: string;
        supplier: string;
        receipt: { id: string; number: string };
        returnedOn: string;
        returnedBy: string;
        reason: string;
        totalMinor: number;
        lines: {
            id: string;
            medicine: string;
            batchNumber: string;
            quantity: number;
            unitCostMinor: number;
            lineTotalMinor: number;
        }[];
    };
};

/**
 * One return to a supplier.
 */
export default function ShowSupplierReturn({ supplierReturn }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title={supplierReturn.number} />
            <PageHeader
                title={`Return ${supplierReturn.number}`}
                description={
                    <>
                        To {supplierReturn.supplier} from{' '}
                        <Link
                            href={showReceipt(supplierReturn.receipt.id)}
                            className="underline"
                        >
                            {supplierReturn.receipt.number}
                        </Link>{' '}
                        · {supplierReturn.returnedOn} by{' '}
                        {supplierReturn.returnedBy} · {supplierReturn.reason}
                    </>
                }
            />
            <Panel>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medicine</TableHead>
                            <TableHead>Batch</TableHead>
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
                        {supplierReturn.lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell>{line.medicine}</TableCell>
                                <TableCell>{line.batchNumber}</TableCell>
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
                                colSpan={4}
                                className="text-right font-medium"
                            >
                                Credit due from supplier
                            </TableCell>
                            <TableCell className="text-right font-medium tabular-nums">
                                {money(supplierReturn.totalMinor)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
