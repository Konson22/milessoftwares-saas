import { Head, Link } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import { Amount } from '@/components/amount';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { PeriodFilter } from '@/components/period-filter';
import { Button } from '@miles/ui/components/ui/button';
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
import { useCan } from '@/lib/access';
import { show as showJournal } from '@/routes/journals';
import { create, index } from '@/routes/supplier-payments';
import { show as showSupplier } from '@/routes/suppliers';

type Props = {
    from: string;
    to: string;
    totalMinor: number;
    payments: {
        id: string;
        number: string;
        supplier: string | null;
        supplierId: string;
        paidOn: string;
        amountMinor: number;
        account: string | null;
        reference: string | null;
        journalId: string;
    }[];
};

/**
 * Money paid to suppliers in a period.
 */
export default function SupplierPayments({
    from,
    to,
    totalMinor,
    payments,
}: Props) {
    const can = useCan();

    return (
        <>
            <Head title="Supplier payments" />
            <PageHeader
                title="Supplier payments"
                actions={
                    can('finance.payables.manage') && (
                        <Button asChild>
                            <Link href={create()}>
                                <Banknote className="size-4" aria-hidden />
                                Pay a supplier
                            </Link>
                        </Button>
                    )
                }
            />
            <Panel>
                <PanelToolbar>
                    <PeriodFilter form={index.form()} from={from} to={to} />
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Number</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Supplier</TableHead>
                            <TableHead>Paid from</TableHead>
                            <TableHead>Reference</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {payments.length === 0 && (
                            <TableEmpty colSpan={6}>
                                No payments in this period.
                            </TableEmpty>
                        )}
                        {payments.map((payment) => (
                            <TableRow key={payment.id}>
                                <TableCell className="font-mono text-xs">
                                    <Link
                                        href={showJournal(payment.journalId)}
                                        className="text-primary hover:underline"
                                    >
                                        {payment.number}
                                    </Link>
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {payment.paidOn}
                                </TableCell>
                                <TableCell>
                                    <Link
                                        href={showSupplier(payment.supplierId)}
                                        className="hover:underline"
                                    >
                                        {payment.supplier}
                                    </Link>
                                </TableCell>
                                <TableCell>{payment.account}</TableCell>
                                <TableCell className="text-muted-foreground">
                                    {payment.reference ?? '—'}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={payment.amountMinor} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={5}>Total paid</TableCell>
                            <TableCell className="text-right">
                                <Amount minor={totalMinor} />
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
