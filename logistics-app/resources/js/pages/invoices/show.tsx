import { Form, Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
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
import { Field, invalid, Select } from '@/components/field';
import { Status } from '@/components/status';
import { formatMoney } from '@/lib/money';
import { formatDate, formatDateTime } from '@/lib/status';
import { index, voidMethod as voidInvoice } from '@/routes/invoices';
import { store as recordPayment } from '@/routes/invoices/payments';
import { show as showShipment } from '@/routes/shipments';
import type { Option } from '@/types';

type Invoice = {
    id: string;
    number: string;
    status: string;
    overdue: boolean;
    currency: string;
    customer: {
        name: string;
        phone: string | null;
        email: string | null;
        address: string | null;
    };
    issuedOn: string;
    dueOn: string | null;
    issuedBy: string;
    totalMinor: number;
    paidMinor: number;
    balanceMinor: number;
    balance: string;
    voidedAt: string | null;
    voidedBy: string | null;
    voidReason: string | null;
    lines: {
        id: string;
        shipmentId: string | null;
        description: string;
        amountMinor: number;
    }[];
    payments: {
        id: string;
        number: string;
        paidOn: string;
        method: string;
        reference: string | null;
        amountMinor: number;
        receivedBy: string;
    }[];
};

type Props = {
    invoice: Invoice;
    paymentMethods: Option[];
    today: string;
    can: { pay: boolean; void: boolean };
};

export default function InvoiceShow({
    invoice,
    paymentMethods,
    today,
    can,
}: Props) {
    const money = (minor: number) => formatMoney(minor, invoice.currency);
    const open = invoice.status === 'issued';

    return (
        <>
            <Head title={`Invoice ${invoice.number}`} />
            <PageHeader
                title={`Invoice ${invoice.number}`}
                description={`${invoice.customer.name} · issued ${formatDate(invoice.issuedOn)} by ${invoice.issuedBy}${invoice.dueOn ? ` · due ${formatDate(invoice.dueOn)}` : ''}`}
                badges={
                    <>
                        <Status status={invoice.status} />
                        {invoice.overdue && (
                            <StatusBadge tone="danger">Overdue</StatusBadge>
                        )}
                    </>
                }
                actions={
                    <>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.print()}
                        >
                            <Printer className="size-4" aria-hidden />
                            Print
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Invoices</Link>
                        </Button>
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
                <div className="grid content-start gap-4">
                    <Panel>
                        <PanelHeader title="Billed to" />
                        <PanelBody className="text-sm">
                            <p className="font-medium">
                                {invoice.customer.name}
                            </p>
                            {invoice.customer.address && (
                                <p>{invoice.customer.address}</p>
                            )}
                            <p className="text-muted-foreground">
                                {[
                                    invoice.customer.phone,
                                    invoice.customer.email,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader title="Shipments" />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invoice.lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell>
                                            {line.shipmentId ? (
                                                <Link
                                                    href={showShipment(
                                                        line.shipmentId,
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    {line.description}
                                                </Link>
                                            ) : (
                                                line.description
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(line.amountMinor)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell className="font-medium">
                                        Total
                                    </TableCell>
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {money(invoice.totalMinor)}
                                    </TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell className="text-muted-foreground">
                                        Paid
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(invoice.paidMinor)}
                                    </TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell className="font-medium">
                                        Balance
                                    </TableCell>
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {money(invoice.balanceMinor)}
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        </Table>
                    </Panel>

                    <Panel>
                        <PanelHeader title="Payments" />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Receipt</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Method</TableHead>
                                    <TableHead>Received by</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invoice.payments.length === 0 && (
                                    <TableEmpty colSpan={5}>
                                        No payments yet.
                                    </TableEmpty>
                                )}
                                {invoice.payments.map((payment) => (
                                    <TableRow key={payment.id}>
                                        <TableCell className="font-medium">
                                            {payment.number}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {formatDate(payment.paidOn)}
                                        </TableCell>
                                        <TableCell>
                                            {payment.method}
                                            {payment.reference && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {payment.reference}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {payment.receivedBy}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(payment.amountMinor)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>

                    {invoice.voidedAt && (
                        <p className="text-sm text-muted-foreground">
                            Voided {formatDateTime(invoice.voidedAt)} by{' '}
                            {invoice.voidedBy}: {invoice.voidReason}
                        </p>
                    )}
                </div>

                <div className="grid content-start gap-4 print:hidden">
                    {can.pay && open && (
                        <Panel>
                            <PanelHeader title="Record a payment" />
                            <PanelBody>
                                <Form
                                    {...recordPayment.form(invoice.id)}
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="amount"
                                                label="Amount"
                                                error={errors.amount}
                                            >
                                                <Input
                                                    id="amount"
                                                    name="amount"
                                                    inputMode="decimal"
                                                    required
                                                    defaultValue={
                                                        invoice.balance
                                                    }
                                                    {...invalid(
                                                        'amount',
                                                        errors.amount,
                                                    )}
                                                />
                                            </Field>
                                            <Field
                                                id="method"
                                                label="Method"
                                                error={errors.method}
                                            >
                                                <Select
                                                    id="method"
                                                    name="method"
                                                    defaultValue="bank_transfer"
                                                    options={paymentMethods}
                                                />
                                            </Field>
                                            <Field
                                                id="reference"
                                                label="Reference"
                                                error={errors.reference}
                                                hint="Transfer or mobile money reference."
                                            >
                                                <Input
                                                    id="reference"
                                                    name="reference"
                                                />
                                            </Field>
                                            <Field
                                                id="paid_on"
                                                label="Paid on"
                                                error={errors.paid_on}
                                            >
                                                <Input
                                                    id="paid_on"
                                                    name="paid_on"
                                                    type="date"
                                                    required
                                                    defaultValue={today}
                                                    {...invalid(
                                                        'paid_on',
                                                        errors.paid_on,
                                                    )}
                                                />
                                            </Field>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Record payment
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}

                    {can.void && open && invoice.paidMinor === 0 && (
                        <Panel>
                            <PanelHeader
                                title="Void invoice"
                                description="Its shipments can then be invoiced again."
                            />
                            <PanelBody>
                                <Form
                                    {...voidInvoice.form(invoice.id)}
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="void_reason"
                                                label="Reason"
                                                error={errors.reason}
                                            >
                                                <Input
                                                    id="void_reason"
                                                    name="reason"
                                                    required
                                                    {...invalid(
                                                        'void_reason',
                                                        errors.reason,
                                                    )}
                                                />
                                            </Field>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Void invoice
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}
                </div>
            </div>
        </>
    );
}
