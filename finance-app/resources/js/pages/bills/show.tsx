import { Head, Link } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import { Amount } from '@/components/amount';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { sourceLabel, statusLabel, statusTone } from '@/lib/format';
import { show as showJournal } from '@/routes/journals';
import { create as pay } from '@/routes/supplier-payments';
import { show as showSupplier } from '@/routes/suppliers';

type Props = {
    bill: {
        id: string;
        number: string;
        supplier: string | null;
        supplierId: string;
        supplierReference: string | null;
        source: string;
        billDate: string;
        dueOn: string | null;
        totalMinor: number;
        taxMinor: number;
        paidMinor: number;
        outstandingMinor: number;
        status: string;
        memo: string | null;
        journalId: string | null;
        lines: {
            description: string;
            quantity: number | null;
            amount_minor: number;
            account: string | null;
        }[];
    };
    payments: {
        number: string;
        paidOn: string;
        amountMinor: number;
        journalId: string;
    }[];
    credits: {
        number: string;
        creditedOn: string;
        amountMinor: number;
        reason: string;
        journalId: string | null;
    }[];
};

/**
 * One supplier bill: its lines, payments and credits against it, and what
 * is left.
 */
export default function ShowBill({ bill, payments, credits }: Props) {
    const can = useCan();

    return (
        <>
            <Head title={`Bill ${bill.number}`} />
            <PageHeader
                title={`Bill ${bill.number}`}
                badges={
                    <StatusBadge tone={statusTone(bill.status)}>
                        {statusLabel(bill.status)}
                    </StatusBadge>
                }
                description={
                    <>
                        From{' '}
                        <Link
                            href={showSupplier(bill.supplierId)}
                            className="text-primary hover:underline"
                        >
                            {bill.supplier}
                        </Link>
                        {bill.supplierReference && (
                            <> · their reference {bill.supplierReference}</>
                        )}{' '}
                        · via {sourceLabel(bill.source)}
                    </>
                }
                actions={
                    bill.outstandingMinor > 0 &&
                    can('finance.payables.manage') && (
                        <Button asChild>
                            <Link
                                href={pay({
                                    query: { supplier: bill.supplierId },
                                })}
                            >
                                <Banknote className="size-4" aria-hidden />
                                Pay supplier
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="grid gap-5 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Description</TableHead>
                                <TableHead>Account</TableHead>
                                <TableHead className="text-right">
                                    Qty
                                </TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bill.lines.length === 0 && (
                                <TableEmpty colSpan={4}>No lines.</TableEmpty>
                            )}
                            {bill.lines.map((line, index) => (
                                <TableRow key={index}>
                                    <TableCell>{line.description}</TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {line.account ?? 'Inventory'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {line.quantity ?? ''}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={line.amount_minor} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            {bill.taxMinor > 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={3}>Tax</TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={bill.taxMinor} />
                                    </TableCell>
                                </TableRow>
                            )}
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={3}>Total</TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={bill.totalMinor} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </Panel>

                <div className="grid content-start gap-5">
                    <Panel>
                        <PanelHeader title="Summary" />
                        <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-2 p-4 text-sm">
                            <dt className="text-muted-foreground">Bill date</dt>
                            <dd className="tabular-nums">{bill.billDate}</dd>
                            <dt className="text-muted-foreground">Due</dt>
                            <dd className="tabular-nums">
                                {bill.dueOn ?? '—'}
                            </dd>
                            <dt className="text-muted-foreground">Paid</dt>
                            <dd>
                                <Amount minor={bill.paidMinor} />
                            </dd>
                            <dt className="text-muted-foreground">
                                Outstanding
                            </dt>
                            <dd className="font-semibold">
                                <Amount minor={bill.outstandingMinor} />
                            </dd>
                            {bill.journalId && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Posted
                                    </dt>
                                    <dd>
                                        <Link
                                            href={showJournal(bill.journalId)}
                                            className="text-primary hover:underline"
                                        >
                                            View journal
                                        </Link>
                                    </dd>
                                </>
                            )}
                            {bill.memo && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Memo
                                    </dt>
                                    <dd>{bill.memo}</dd>
                                </>
                            )}
                        </dl>
                    </Panel>

                    <Panel>
                        <PanelHeader title="Payments" />
                        {payments.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                No payments yet.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {payments.map((payment) => (
                                    <li
                                        key={payment.number}
                                        className="flex justify-between gap-3 px-4 py-2.5"
                                    >
                                        <Link
                                            href={showJournal(
                                                payment.journalId,
                                            )}
                                            className="font-mono text-xs text-primary hover:underline"
                                        >
                                            {payment.number}
                                        </Link>
                                        <span className="text-muted-foreground tabular-nums">
                                            {payment.paidOn}
                                        </span>
                                        <Amount minor={payment.amountMinor} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    {credits.length > 0 && (
                        <Panel>
                            <PanelHeader
                                title="Credits"
                                description="Goods returned to the supplier"
                            />
                            <ul className="divide-y text-sm">
                                {credits.map((credit) => (
                                    <li
                                        key={credit.number}
                                        className="grid grid-cols-[1fr_auto] gap-x-3 px-4 py-2.5"
                                    >
                                        {credit.journalId ? (
                                            <Link
                                                href={showJournal(
                                                    credit.journalId,
                                                )}
                                                className="font-mono text-xs text-primary hover:underline"
                                            >
                                                {credit.number}
                                            </Link>
                                        ) : (
                                            <span className="font-mono text-xs">
                                                {credit.number}
                                            </span>
                                        )}
                                        <Amount minor={credit.amountMinor} />
                                        <span className="col-span-2 text-xs text-muted-foreground">
                                            {credit.creditedOn} ·{' '}
                                            {credit.reason}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}
                </div>
            </div>
        </>
    );
}
