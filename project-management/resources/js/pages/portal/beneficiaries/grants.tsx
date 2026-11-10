import { Head, Link } from '@inertiajs/react';
import { HandCoins } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { formatDate, statusLabel, statusTone } from '@/lib/status';

type Grant = {
    id: string;
    reference: string;
    title: string;
    status: string;
    currency: string;
    amountMinor: number;
    receivedMinor: number;
    startOn: string | null;
    endOn: string | null;
    reportsOwed: number;
    beneficiary: string;
    url: string;
};

type Payment = {
    grant: string;
    currency: string;
    dueOn: string;
    amountMinor: number;
    receivedOn: string | null;
    beneficiary: string;
    overdue: boolean;
    url: string;
};

type Total = {
    currency: string;
    awardedMinor: number;
    receivedMinor: number;
    scheduledMinor: number;
};

/**
 * The grants beneficiaries share with this donor and their payments. Totals
 * are per currency: amounts in different currencies are never added up.
 */
export default function DonorGrants({
    grants,
    payments,
    totals,
}: {
    grants: Grant[];
    payments: Payment[];
    totals: Total[];
}) {
    return (
        <>
            <Head title="Grants and payments" />
            <PageHeader
                title="Grants and payments"
                description="The grants your beneficiaries share with you, and the payments they schedule against them. Received is what the beneficiary has recorded."
            />

            {grants.length === 0 ? (
                <Panel>
                    <EmptyState icon={HandCoins} title="No shared grants yet">
                        A grant shows here once a beneficiary records it against
                        you and shares it.
                    </EmptyState>
                </Panel>
            ) : (
                <div className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {totals.map((total) => (
                            <Panel key={total.currency}>
                                <PanelBody className="grid gap-1 text-sm">
                                    <p className="text-xs text-muted-foreground">
                                        Awarded in {total.currency}
                                    </p>
                                    <p className="text-lg font-semibold tabular-nums">
                                        {formatMoney(
                                            total.awardedMinor,
                                            total.currency,
                                        )}
                                    </p>
                                    <p className="text-muted-foreground tabular-nums">
                                        {formatMoney(
                                            total.receivedMinor,
                                            total.currency,
                                        )}{' '}
                                        received ·{' '}
                                        {formatMoney(
                                            total.scheduledMinor,
                                            total.currency,
                                        )}{' '}
                                        still scheduled
                                    </p>
                                </PanelBody>
                            </Panel>
                        ))}
                    </div>

                    <Panel>
                        <PanelHeader title="Grants" />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Grant</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Period</TableHead>
                                    <TableHead className="text-right">
                                        Awarded
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Received
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {grants.map((grant) => (
                                    <TableRow key={grant.id}>
                                        <TableCell>
                                            <Link
                                                href={grant.url}
                                                className="font-medium underline-offset-2 hover:underline"
                                            >
                                                {grant.reference} {grant.title}
                                            </Link>
                                            <span className="block text-xs text-muted-foreground">
                                                {grant.beneficiary}
                                                {grant.reportsOwed > 0 &&
                                                    ` · ${grant.reportsOwed} report${grant.reportsOwed === 1 ? '' : 's'} owed`}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                tone={statusTone(grant.status)}
                                            >
                                                {statusLabel(grant.status)}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {grant.startOn
                                                ? formatDate(grant.startOn)
                                                : '—'}{' '}
                                            to{' '}
                                            {grant.endOn
                                                ? formatDate(grant.endOn)
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                grant.amountMinor,
                                                grant.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                grant.receivedMinor,
                                                grant.currency,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>

                    <Panel>
                        <PanelHeader title="Payments" />
                        {payments.length === 0 ? (
                            <PanelBody className="text-sm text-muted-foreground">
                                No payments scheduled yet.
                            </PanelBody>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Due</TableHead>
                                        <TableHead>Grant</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {payments.map((payment, position) => (
                                        <TableRow key={position}>
                                            <TableCell>
                                                {formatDate(payment.dueOn)}
                                            </TableCell>
                                            <TableCell>
                                                <Link
                                                    href={payment.url}
                                                    className="underline-offset-2 hover:underline"
                                                >
                                                    {payment.grant}
                                                </Link>
                                                <span className="block text-xs text-muted-foreground">
                                                    {payment.beneficiary}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {payment.receivedOn ? (
                                                    <StatusBadge tone="success">
                                                        Received{' '}
                                                        {formatDate(
                                                            payment.receivedOn,
                                                        )}
                                                    </StatusBadge>
                                                ) : payment.overdue ? (
                                                    <StatusBadge tone="warning">
                                                        Overdue
                                                    </StatusBadge>
                                                ) : (
                                                    <StatusBadge tone="info">
                                                        Scheduled
                                                    </StatusBadge>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    payment.amountMinor,
                                                    payment.currency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Panel>
                </div>
            )}
        </>
    );
}
