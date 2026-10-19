import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { uuid } from '@/lib/uuid';
import { edit, index } from '@/routes/retail/customers';
import { store as storePayment } from '@/routes/retail/customers/payments';
import { show as showSale } from '@/routes/retail/sales';

type Props = {
    customer: {
        id: string;
        number: string;
        name: string;
        phone: string | null;
        email: string | null;
        address: string | null;
        creditLimitMinor: number;
        paymentTermsDays: number;
        active: boolean;
    };
    owedMinor: number;
    overdueMinor: number;
    availableMinor: number;
    sales: {
        id: string;
        number: string;
        soldOn: string;
        dueOn: string | null;
        overdue: boolean;
        totalMinor: number;
        returnedMinor: number;
        paidMinor: number;
        owedMinor: number;
        here: boolean;
    }[];
    payments: {
        id: string;
        number: string;
        receivedAt: string;
        method: string;
        reference: string | null;
        amountMinor: number;
        receivedBy: string;
        settled: string[];
    }[];
    paymentMethods: { value: string; label: string }[];
    canManage: boolean;
    canTakePayment: boolean;
    today: string;
};

/**
 * A customer's statement: their sales on account with what came back, was
 * paid and is still owed, and the payments they made. Prints as a
 * statement to hand over.
 */
export default function CustomerStatement({
    customer,
    owedMinor,
    overdueMinor,
    availableMinor,
    sales,
    payments,
    paymentMethods,
    canManage,
    canTakePayment,
    today,
}: Props) {
    const { currency, workspace } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const [submissionId, setSubmissionId] = useState(() => uuid());
    const [method, setMethod] = useState(paymentMethods[0]?.value ?? 'cash');

    return (
        <>
            <Head title={customer.name} />
            <PageHeader
                title={customer.name}
                description={[
                    customer.number,
                    customer.phone,
                    `${customer.paymentTermsDays} days to pay`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                badges={
                    !customer.active ? (
                        <StatusBadge tone="neutral">Inactive</StatusBadge>
                    ) : undefined
                }
                actions={
                    <div className="flex gap-2 print:hidden">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.print()}
                        >
                            <Printer className="size-4" aria-hidden />
                            Print statement
                        </Button>
                        {canManage && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit(customer.id)}>Edit</Link>
                            </Button>
                        )}
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>← Customers</Link>
                        </Button>
                    </div>
                }
            />

            <p className="mb-3 hidden text-sm print:block">
                Statement of account from {workspace?.name} on {today}.
            </p>

            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <StatCard label="Owes" value={money(owedMinor)} accent="blue" />
                <StatCard
                    label="Overdue"
                    value={money(overdueMinor)}
                    accent={overdueMinor > 0 ? 'red' : 'blue'}
                />
                <StatCard
                    label="Can still buy on account"
                    value={money(availableMinor)}
                    hint={`Limit ${money(customer.creditLimitMinor)}`}
                    accent="violet"
                />
            </div>

            {canTakePayment && (
                <Panel className="mb-3 max-w-3xl print:hidden">
                    <PanelHeader
                        title="Take a payment"
                        description="Settles the oldest sales first. Cash goes into your till."
                    />
                    <PanelBody>
                        <Form
                            {...storePayment.form(customer.id)}
                            resetOnSuccess
                            onSuccess={() => setSubmissionId(uuid())}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-start gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="submission_id"
                                        value={submissionId}
                                    />
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="amount">Amount</Label>
                                        <Input
                                            id="amount"
                                            name="amount"
                                            inputMode="decimal"
                                            required
                                            className="w-36"
                                            aria-invalid={
                                                errors.amount ? true : undefined
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="method">Paid by</Label>
                                        <select
                                            id="method"
                                            name="method"
                                            value={method}
                                            onChange={(e) =>
                                                setMethod(e.target.value)
                                            }
                                            className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                        >
                                            {paymentMethods.map((option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    {method !== 'cash' && (
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="reference">
                                                Reference
                                            </Label>
                                            <Input
                                                id="reference"
                                                name="reference"
                                                className="w-44"
                                            />
                                        </div>
                                    )}
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="note">Note</Label>
                                        <Input
                                            id="note"
                                            name="note"
                                            className="w-48"
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        className="self-end"
                                    >
                                        Receive payment
                                    </Button>
                                    {(errors.amount || errors.method) && (
                                        <p className="w-full text-sm text-destructive">
                                            {errors.amount ?? errors.method}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel className="mb-3">
                <PanelHeader title="Sales on account" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Sale</TableHead>
                            <TableHead>Sold</TableHead>
                            <TableHead>Due</TableHead>
                            <TableHead className="text-right">Total</TableHead>
                            <TableHead className="text-right">
                                Returned
                            </TableHead>
                            <TableHead className="text-right">Paid</TableHead>
                            <TableHead className="text-right">Owed</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {sales.length === 0 ? (
                            <TableEmpty colSpan={7}>
                                Nothing bought on account.
                            </TableEmpty>
                        ) : (
                            sales.map((sale) => (
                                <TableRow key={sale.id}>
                                    <TableCell className="font-medium">
                                        {sale.here ? (
                                            <Link
                                                href={showSale(sale.id)}
                                                className="hover:underline"
                                            >
                                                {sale.number}
                                            </Link>
                                        ) : (
                                            sale.number
                                        )}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {sale.soldOn}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {sale.dueOn ?? '–'}{' '}
                                        {sale.overdue && (
                                            <StatusBadge tone="danger">
                                                Overdue
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(sale.totalMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {sale.returnedMinor > 0
                                            ? `−${money(sale.returnedMinor)}`
                                            : '–'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {sale.paidMinor > 0
                                            ? `−${money(sale.paidMinor)}`
                                            : '–'}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {money(sale.owedMinor)}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Panel>

            <Panel>
                <PanelHeader title="Payments" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Payment</TableHead>
                            <TableHead>Received</TableHead>
                            <TableHead>How</TableHead>
                            <TableHead>Settled</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {payments.length === 0 ? (
                            <TableEmpty colSpan={5}>
                                No payments yet.
                            </TableEmpty>
                        ) : (
                            payments.map((payment) => (
                                <TableRow key={payment.id}>
                                    <TableCell className="font-medium">
                                        {payment.number}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {new Date(
                                            payment.receivedAt,
                                        ).toLocaleString()}{' '}
                                        <span className="text-muted-foreground">
                                            {payment.receivedBy}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {payment.method}
                                        {payment.reference &&
                                            ` · ${payment.reference}`}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {payment.settled.join(', ')}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(payment.amountMinor)}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
