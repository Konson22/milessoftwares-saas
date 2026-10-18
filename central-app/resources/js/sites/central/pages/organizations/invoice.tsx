import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer } from 'lucide-react';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@/shared/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/components/ui/table';
import { dashboard } from '@/routes';
import { billing as organizationBilling } from '@/routes/organization';
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
import type {
    InvoiceRow,
    PaymentRow,
} from '@/sites/central/pages/organizations/billing';

type Props = {
    invoice: InvoiceRow & {
        periodStart: string | null;
        periodEnd: string | null;
        notes: string | null;
        voidReason: string | null;
        lines: {
            id: string;
            description: string;
            quantity: number;
            unitPriceMinor: number;
            amountMinor: number;
        }[];
        payments: PaymentRow[];
    };
    billTo: {
        name: string;
        address: string | null;
        email: string | null;
        taxId: string | null;
    };
    issuer: { name: string; brand: string; email: string | null };
    instructions: string[];
    methods: string[];
};

// Printing shows the invoice alone, without the app around it.
const printStyles = `@media print {
    body * { visibility: hidden; }
    #invoice, #invoice * { visibility: visible; }
    #invoice { position: absolute; inset: 0 auto auto 0; width: 100%; border: 0; box-shadow: none; }
}`;

export default function OrganizationInvoice({
    invoice,
    billTo,
    issuer,
    instructions,
    methods,
}: Props) {
    const money = (minor: number) => formatMoney(minor, invoice.currency);

    return (
        <>
            <Head title={`Invoice ${invoice.number}`} />
            <style>{printStyles}</style>

            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                <Button asChild variant="ghost" size="sm" className="-ml-2">
                    <Link href={organizationBilling()}>
                        <ArrowLeft /> Billing
                    </Link>
                </Button>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" onClick={() => window.print()}>
                        <Printer /> Print or save as PDF
                    </Button>
                    {invoice.balanceMinor > 0 && (
                        <ReportPaymentDialog
                            invoice={invoice}
                            methods={methods}
                            size="default"
                        />
                    )}
                </div>
            </div>

            <article
                id="invoice"
                className="mx-auto max-w-4xl rounded-lg border bg-card p-4 sm:p-10"
            >
                <header className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p className="text-lg font-semibold">{issuer.brand}</p>
                        <p className="text-sm text-muted-foreground">
                            {issuer.name}
                        </p>
                        {issuer.email && (
                            <p className="text-sm text-muted-foreground">
                                {issuer.email}
                            </p>
                        )}
                    </div>
                    <div className="text-right">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Invoice
                        </h1>
                        <p className="font-medium">{invoice.number}</p>
                        <StatusBadge
                            tone={invoiceTone[invoice.status]}
                            className="mt-1"
                        >
                            {humanize(invoice.status)}
                        </StatusBadge>
                    </div>
                </header>

                <div className="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Bill to
                        </p>
                        <p className="mt-1 font-medium">{billTo.name}</p>
                        {billTo.address && (
                            <p className="whitespace-pre-line text-muted-foreground">
                                {billTo.address}
                            </p>
                        )}
                        {billTo.email && (
                            <p className="text-muted-foreground">
                                {billTo.email}
                            </p>
                        )}
                        {billTo.taxId && (
                            <p className="text-muted-foreground">
                                Tax ID {billTo.taxId}
                            </p>
                        )}
                    </div>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-1 sm:text-right">
                        <dt className="text-muted-foreground">Issued</dt>
                        <dd>{formatDate(invoice.issuedOn)}</dd>
                        <dt className="text-muted-foreground">Due</dt>
                        <dd className="font-medium">
                            {formatDate(invoice.dueOn)}
                        </dd>
                        {invoice.periodStart && (
                            <>
                                <dt className="text-muted-foreground">
                                    Period
                                </dt>
                                <dd>
                                    {formatDate(invoice.periodStart)} –{' '}
                                    {formatDate(invoice.periodEnd)}
                                </dd>
                            </>
                        )}
                        {invoice.paidAt && (
                            <>
                                <dt className="text-muted-foreground">Paid</dt>
                                <dd>{formatDate(invoice.paidAt)}</dd>
                            </>
                        )}
                    </dl>
                </div>

                <Table className="mt-5">
                    <TableHeader>
                        <TableRow>
                            <TableHead>Description</TableHead>
                            <TableHead className="text-right">Qty</TableHead>
                            <TableHead className="text-right">
                                Unit price
                            </TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {invoice.lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell className="whitespace-normal">
                                    {line.description}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {line.quantity}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.unitPriceMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.amountMinor)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={3} className="text-right">
                                Total
                            </TableCell>
                            <TableCell className="text-right font-semibold tabular-nums">
                                {money(invoice.totalMinor)}
                            </TableCell>
                        </TableRow>
                        <TableRow>
                            <TableCell
                                colSpan={3}
                                className="text-right text-muted-foreground"
                            >
                                Paid
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {money(invoice.paidMinor)}
                            </TableCell>
                        </TableRow>
                        <TableRow>
                            <TableCell colSpan={3} className="text-right">
                                Balance due
                            </TableCell>
                            <TableCell className="text-right font-semibold tabular-nums">
                                {money(invoice.balanceMinor)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>

                {invoice.voidReason && (
                    <p className="mt-4 rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                        This invoice was voided: {invoice.voidReason}
                    </p>
                )}

                {invoice.notes && (
                    <p className="mt-4 text-sm whitespace-pre-line">
                        {invoice.notes}
                    </p>
                )}

                {instructions.length > 0 && invoice.balanceMinor > 0 && (
                    <section className="mt-5 text-sm">
                        <h2 className="font-medium">How to pay</h2>
                        <ul className="mt-1 list-inside list-disc text-muted-foreground">
                            {instructions.map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
                        <p className="mt-1 text-muted-foreground">
                            Quote {invoice.number} as the payment reference.
                        </p>
                    </section>
                )}

                {invoice.payments.length > 0 && (
                    <section className="mt-5">
                        <h2 className="text-sm font-medium">Payments</h2>
                        <ul className="mt-2 divide-y rounded-md border text-sm">
                            {invoice.payments.map((payment) => (
                                <li
                                    key={payment.id}
                                    className="flex flex-wrap items-center gap-3 px-3 py-2"
                                >
                                    <span className="min-w-0 flex-1">
                                        {formatDate(payment.paidOn)} ·{' '}
                                        {methodLabel[payment.method] ??
                                            payment.method}
                                        {payment.reference &&
                                            ` · ${payment.reference}`}
                                    </span>
                                    <span className="tabular-nums">
                                        {money(payment.amountMinor)}
                                    </span>
                                    <StatusBadge
                                        tone={paymentTone[payment.status]}
                                    >
                                        {paymentStatusLabel[payment.status] ??
                                            payment.status}
                                    </StatusBadge>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </article>
        </>
    );
}

OrganizationInvoice.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Billing', href: organizationBilling() },
    ],
};
