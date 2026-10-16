import { Head, Link, usePage } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
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
import { useCanHere } from '@/lib/access';
import { formatMoney } from '@/lib/money';
import { create, show } from '@/routes/receipts';

type Receipt = {
    id: string;
    number: string;
    supplier: string;
    order: string | null;
    supplierReference: string | null;
    receivedOn: string;
    totalMinor: number;
};

export default function Receipts({ receipts }: { receipts: Receipt[] }) {
    const { branch, currency } = usePage().props;
    const canHere = useCanHere();

    return (
        <>
            <Head title="Goods received" />
            <PageHeader
                title="Goods received"
                description={branch?.name}
                actions={
                    canHere('pharmacy.purchasing.receive') ? (
                        <Button asChild>
                            <Link href={create()}>
                                Receive without an order
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <Panel>
                {receipts.length === 0 ? (
                    <EmptyState
                        icon={PackageCheck}
                        title="Nothing received yet"
                        action={
                            canHere('pharmacy.purchasing.receive') ? (
                                <Button asChild>
                                    <Link href={create()}>
                                        Receive without an order
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Supplier</TableHead>
                                <TableHead>Received</TableHead>
                                <TableHead>Reference</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {receipts.map((receipt) => (
                                <TableRow key={receipt.id}>
                                    <TableCell>
                                        <Link
                                            href={show(receipt.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {receipt.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{receipt.supplier}</TableCell>
                                    <TableCell className="tabular-nums">
                                        {receipt.receivedOn}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {[
                                            receipt.order,
                                            receipt.supplierReference,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            receipt.totalMinor,
                                            currency ?? 'USD',
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
