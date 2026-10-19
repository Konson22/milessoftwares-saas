import { Head, Link, usePage } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { show } from '@/routes/retail/supplier-returns';

type Props = {
    returns: {
        id: string;
        number: string;
        supplier: string;
        receipt: string;
        returnedOn: string;
        reason: string;
        totalMinor: number;
    }[];
};

/**
 * Goods sent back to suppliers from this branch.
 */
export default function SupplierReturns({ returns }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title="Returns" />
            <PageHeader
                title="Returns to suppliers"
                description="To return goods, open the delivery they came in (Goods received) and choose Return goods."
            />
            <Panel>
                {returns.length === 0 ? (
                    <EmptyState icon={Undo2} title="Nothing returned yet" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Return</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Supplier</TableHead>
                                <TableHead>Delivery</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead className="text-right">
                                    Credit
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {returns.map((supplierReturn) => (
                                <TableRow key={supplierReturn.id}>
                                    <TableCell>
                                        <Link
                                            href={show(supplierReturn.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {supplierReturn.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {supplierReturn.returnedOn}
                                    </TableCell>
                                    <TableCell>
                                        {supplierReturn.supplier}
                                    </TableCell>
                                    <TableCell>
                                        {supplierReturn.receipt}
                                    </TableCell>
                                    <TableCell className="max-w-72 truncate text-muted-foreground">
                                        {supplierReturn.reason}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(supplierReturn.totalMinor)}
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
