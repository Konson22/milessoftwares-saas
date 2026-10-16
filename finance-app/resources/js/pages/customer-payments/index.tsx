import { Head, Link } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { PeriodFilter } from '@/components/period-filter';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { sourceLabel } from '@/lib/format';
import { index } from '@/routes/customer-payments';
import { show as showInvoice } from '@/routes/invoices';
import { show as showJournal } from '@/routes/journals';

type Props = {
    from: string;
    to: string;
    totalMinor: number;
    payments: {
        id: string;
        number: string;
        kind: string;
        source: string;
        invoiceId: string | null;
        invoiceNumber: string | null;
        method: string;
        amountMinor: number;
        receivedOn: string;
        account: string | null;
        journalId: string;
    }[];
};

/**
 * Money received from customers in a period, less refunds.
 */
export default function CustomerPayments({
    from,
    to,
    totalMinor,
    payments,
}: Props) {
    return (
        <>
            <Head title="Customer payments" />
            <PageHeader
                title="Customer payments"
                description="Payments taken at your products' counters arrive here automatically."
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
                            <TableHead>Invoice</TableHead>
                            <TableHead>Method</TableHead>
                            <TableHead>Into</TableHead>
                            <TableHead>From</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {payments.length === 0 && (
                            <TableEmpty colSpan={7}>
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
                                    {payment.receivedOn}
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    {payment.invoiceId ? (
                                        <Link
                                            href={showInvoice(
                                                payment.invoiceId,
                                            )}
                                            className="hover:underline"
                                        >
                                            {payment.invoiceNumber}
                                        </Link>
                                    ) : (
                                        (payment.invoiceNumber ?? '—')
                                    )}
                                </TableCell>
                                <TableCell className="space-x-1">
                                    <span>{payment.method}</span>
                                    {payment.kind === 'refund' && (
                                        <StatusBadge tone="warning">
                                            Refund
                                        </StatusBadge>
                                    )}
                                </TableCell>
                                <TableCell>{payment.account}</TableCell>
                                <TableCell>
                                    {sourceLabel(payment.source)}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount
                                        minor={
                                            payment.kind === 'refund'
                                                ? -payment.amountMinor
                                                : payment.amountMinor
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={6}>
                                Received, less refunds
                            </TableCell>
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
