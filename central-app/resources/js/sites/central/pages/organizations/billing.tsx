import { Head, Link } from '@inertiajs/react';
import { Clock, Landmark, ReceiptText, Wallet } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/components/ui/table';
import { dashboard } from '@/routes';
import {
    billing as organizationBilling,
    settings as organizationSettings,
} from '@/routes/organization';
import { show as showInvoice } from '@/routes/organization/invoices';
import { ReportPaymentDialog } from '@/sites/central/components/report-payment-dialog';
import {
    formatDate,
    formatMoney,
    humanize,
    invoiceTone,
    methodLabel,
    paymentStatusLabel,
    paymentTone,
} from '@/shared/lib/billing';

export type InvoiceRow = {
    id: string;
    number: string;
    status: string;
    currency: string;
    totalMinor: number;
    paidMinor: number;
    balanceMinor: number;
    issuedOn: string;
    dueOn: string;
    paidAt: string | null;
};

export type PaymentRow = {
    id: string;
    invoiceId: string;
    invoiceNumber: string | null;
    status: string;
    method: string;
    amountMinor: number;
    currency: string;
    reference: string | null;
    paidOn: string;
    rejectionReason: string | null;
};

type Props = {
    organization: { displayName: string; billingEmail: string | null };
    summary: {
        balances: { currency: string; amountMinor: number }[];
        openInvoices: number;
        overdueInvoices: number;
        nextDue: { dueOn: string; overdue: boolean } | null;
        paymentsAwaitingConfirmation: number;
    };
    invoices: InvoiceRow[];
    payments: PaymentRow[];
    instructions: string[];
    methods: string[];
};

export default function OrganizationBilling({
    organization,
    summary,
    invoices,
    payments,
    instructions,
    methods,
}: Props) {
    return (
        <>
            <Head title={`${organization.displayName} billing`} />

            <PageHeader
                title="Billing"
                description="Invoices from Miles Global Softwares and the payments you have made."
            />

            <div className="space-y-3">
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        label="Balance due"
                        icon={Wallet}
                        accent={summary.overdueInvoices > 0 ? 'red' : 'emerald'}
                        value={
                            summary.balances.length === 0
                                ? 'Nothing due'
                                : summary.balances
                                      .map((balance) =>
                                          formatMoney(
                                              balance.amountMinor,
                                              balance.currency,
                                          ),
                                      )
                                      .join(' · ')
                        }
                        hint={
                            summary.nextDue
                                ? `${summary.nextDue.overdue ? 'Overdue since' : 'Next due'} ${formatDate(summary.nextDue.dueOn)}`
                                : 'You are all paid up'
                        }
                    />
                    <StatCard
                        label="Open invoices"
                        icon={ReceiptText}
                        value={summary.openInvoices}
                        hint={
                            summary.overdueInvoices > 0
                                ? `${summary.overdueInvoices} overdue`
                                : undefined
                        }
                    />
                    <StatCard
                        label="Awaiting confirmation"
                        icon={Clock}
                        accent="amber"
                        value={summary.paymentsAwaitingConfirmation}
                        hint="Payments you reported"
                    />
                </div>

                <div className="grid gap-3 xl:grid-cols-3">
                    <Panel className="xl:col-span-2">
                        <PanelHeader title="Invoices" />
                        {invoices.length === 0 ? (
                            <EmptyState
                                icon={ReceiptText}
                                title="No invoices yet"
                            >
                                When your plan is set up, its invoices will
                                appear here.
                            </EmptyState>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-4">
                                            Invoice
                                        </TableHead>
                                        <TableHead>Due</TableHead>
                                        <TableHead className="text-right">
                                            Total
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Balance
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="pr-4">
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoices.map((invoice) => (
                                        <TableRow key={invoice.id}>
                                            <TableCell className="pl-4">
                                                <Link
                                                    href={showInvoice(
                                                        invoice.id,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {invoice.number}
                                                </Link>
                                                <span className="block text-xs text-muted-foreground">
                                                    Issued{' '}
                                                    {formatDate(
                                                        invoice.issuedOn,
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {formatDate(invoice.dueOn)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    invoice.totalMinor,
                                                    invoice.currency,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    invoice.balanceMinor,
                                                    invoice.currency,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    tone={
                                                        invoiceTone[
                                                            invoice.status
                                                        ]
                                                    }
                                                >
                                                    {humanize(invoice.status)}
                                                </StatusBadge>
                                            </TableCell>
                                            <TableCell className="pr-4 text-right">
                                                {invoice.balanceMinor > 0 && (
                                                    <ReportPaymentDialog
                                                        invoice={invoice}
                                                        methods={methods}
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Panel>

                    <div className="space-y-3">
                        <Panel>
                            <PanelHeader title="How to pay" />
                            <PanelBody className="space-y-3 text-sm">
                                {instructions.length > 0 ? (
                                    <ul className="space-y-2">
                                        {instructions.map((line) => (
                                            <li
                                                key={line}
                                                className="flex gap-2"
                                            >
                                                <Landmark
                                                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                                    aria-hidden
                                                />
                                                {line}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-muted-foreground">
                                        Payment details are printed on each
                                        invoice.
                                    </p>
                                )}
                                <p className="text-muted-foreground">
                                    After paying, use{' '}
                                    <span className="font-medium text-foreground">
                                        Report a payment
                                    </span>{' '}
                                    with your transaction reference so we can
                                    match it.
                                </p>
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="Billing contact"
                                actions={
                                    <Link
                                        href={organizationSettings()}
                                        className="text-sm text-muted-foreground hover:text-foreground"
                                    >
                                        Edit
                                    </Link>
                                }
                            />
                            <PanelBody className="text-sm">
                                {organization.billingEmail ?? (
                                    <span className="text-muted-foreground">
                                        No billing email set.
                                    </span>
                                )}
                            </PanelBody>
                        </Panel>
                    </div>
                </div>

                <Panel>
                    <PanelHeader title="Payments" />
                    {payments.length === 0 ? (
                        <EmptyState icon={Wallet} title="No payments yet" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-4">Date</TableHead>
                                    <TableHead>Invoice</TableHead>
                                    <TableHead>Method</TableHead>
                                    <TableHead>Reference</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    <TableHead className="pr-4">
                                        Status
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payments.map((payment) => (
                                    <TableRow key={payment.id}>
                                        <TableCell className="pl-4">
                                            {formatDate(payment.paidOn)}
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={showInvoice(
                                                    payment.invoiceId,
                                                )}
                                                className="hover:underline"
                                            >
                                                {payment.invoiceNumber}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {methodLabel[payment.method] ??
                                                payment.method}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {payment.reference ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMoney(
                                                payment.amountMinor,
                                                payment.currency,
                                            )}
                                        </TableCell>
                                        <TableCell className="pr-4">
                                            <StatusBadge
                                                tone={
                                                    paymentTone[payment.status]
                                                }
                                            >
                                                {paymentStatusLabel[
                                                    payment.status
                                                ] ?? payment.status}
                                            </StatusBadge>
                                            {payment.rejectionReason && (
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    {payment.rejectionReason}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>
            </div>
        </>
    );
}

OrganizationBilling.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Billing', href: organizationBilling() },
    ],
};
