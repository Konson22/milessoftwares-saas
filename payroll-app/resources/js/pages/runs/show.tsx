import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Download, FileDown } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, formatDate, selectClass, TextField } from '@/components/fields';
import { RunStatus } from '@/components/run-status';
import { formatMoney } from '@/lib/money';
import {
    approve,
    destroy,
    pay,
    payslip,
    recalculate,
    reverse,
    review,
    show,
} from '@/routes/runs';
import { pdf as payslipsPdf } from '@/routes/runs/payslips';

type Props = {
    currency: string | null;
    run: {
        id: string;
        number: string;
        period: string;
        status:
            | 'draft'
            | 'reviewed'
            | 'approved'
            | 'part_paid'
            | 'paid'
            | 'reversed';
        headcount: number;
        netMinor: number;
        paidMinor: number;
        periodStart: string;
        periodEnd: string;
        payDate: string;
        earningsMinor: number;
        incomeTaxMinor: number;
        socialSecurityEmployeeMinor: number;
        socialSecurityEmployerMinor: number;
        otherDeductionsMinor: number;
        preparedBy: string;
        preparedAt: string;
        reviewedBy: string | null;
        reviewedAt: string | null;
        approvedBy: string | null;
        approvedAt: string | null;
        paidBy: string | null;
        paidAt: string | null;
        paymentMethod: string | null;
        reversedBy: string | null;
        reversedAt: string | null;
        reversalReason: string | null;
        note: string | null;
    };
    payments: {
        id: string;
        number: string;
        method: string;
        paidOn: string;
        reference: string | null;
        amountMinor: number;
        headcount: number;
        recordedBy: string;
    }[];
    payslips: {
        id: string;
        number: string;
        name: string;
        branch: string | null;
        department: string | null;
        earningsMinor: number;
        incomeTaxMinor: number;
        socialSecurityMinor: number;
        otherDeductionsMinor: number;
        netMinor: number;
        paidIn: string | null;
    }[];
    can: {
        prepare: boolean;
        review: boolean;
        approve: boolean;
        approveSelf: boolean;
        pay: boolean;
        reverse: boolean;
    };
    paymentMethods: string[];
};

const METHOD_LABELS: Record<string, string> = {
    bank: 'Bank transfer',
    cash: 'Cash',
    mobile_money: 'Mobile money',
};

const when = (iso: string | null) =>
    iso === null ? '' : new Date(iso).toLocaleString();

export default function ShowRun({
    currency,
    run,
    payments,
    payslips,
    can,
    paymentMethods,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const money = (minor: number) => formatMoney(minor, currency);
    const unpaid = payslips.filter((slip) => slip.paidIn === null);
    const [selected, setSelected] = useState<string[]>([]);
    const toggle = (id: string) =>
        setSelected((ids) =>
            ids.includes(id) ? ids.filter((x) => x !== id) : [...ids, id],
        );
    const selectedNet = payslips
        .filter((slip) => selected.includes(slip.id))
        .reduce((sum, slip) => sum + slip.netMinor, 0);
    const outstanding = run.netMinor - run.paidMinor;

    return (
        <>
            <Head title={`Payroll ${run.number}`} />
            <PageHeader
                title={`Payroll ${run.number}: ${run.period}`}
                badges={<RunStatus status={run.status} />}
                actions={
                    can.prepare && (
                        <>
                            <Form {...recalculate.form(run.id)}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Recalculate
                                    </Button>
                                )}
                            </Form>
                            <Form {...destroy.form(run.id)}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        disabled={processing}
                                    >
                                        Discard draft
                                    </Button>
                                )}
                            </Form>
                        </>
                    )
                }
            />

            {errors.run && (
                <p className="mb-3 border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {errors.run}
                </p>
            )}

            {run.status === 'reversed' && (
                <p className="mb-3 border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                    Reversed by {run.reversedBy}, {when(run.reversedAt)}:{' '}
                    {run.reversalReason}. Finance takes the journal back;
                    nothing here is paid. Prepare {run.period} again for the
                    right figures.
                </p>
            )}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Earnings" value={money(run.earningsMinor)} />
                <StatCard
                    label="Income tax"
                    value={money(run.incomeTaxMinor)}
                />
                <StatCard
                    label="Social security"
                    value={money(
                        run.socialSecurityEmployeeMinor +
                            run.socialSecurityEmployerMinor,
                    )}
                    hint={`${money(run.socialSecurityEmployerMinor)} paid by the employer`}
                />
                <StatCard
                    label="Net pay"
                    value={money(run.netMinor)}
                    hint={
                        run.status === 'part_paid'
                            ? `${money(outstanding)} still to pay`
                            : `${run.headcount} people, paid ${formatDate(run.payDate)}`
                    }
                    accent="emerald"
                />
            </div>

            <Panel className="mt-3">
                <PanelHeader title="Who checked it" />
                <PanelBody className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Prepared
                        </p>
                        <p>
                            {run.preparedBy}, {when(run.preparedAt)}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Reviewed
                        </p>
                        {run.reviewedBy ? (
                            <p>
                                {run.reviewedBy}, {when(run.reviewedAt)}
                            </p>
                        ) : can.review && !can.approveSelf ? (
                            <Form {...review.form(run.id)}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Mark as reviewed
                                    </Button>
                                )}
                            </Form>
                        ) : (
                            <p className="text-muted-foreground">
                                {run.approvedBy ? 'Not reviewed' : 'Optional'}
                            </p>
                        )}
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Approved
                        </p>
                        {run.approvedBy ? (
                            <p>
                                {run.approvedBy}, {when(run.approvedAt)}
                            </p>
                        ) : can.approve ? (
                            can.approveSelf ? (
                                <p className="text-muted-foreground">
                                    You prepared this payroll: someone else must
                                    approve it.
                                </p>
                            ) : (
                                <Form {...approve.form(run.id)}>
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Approve and lock
                                        </Button>
                                    )}
                                </Form>
                            )
                        ) : (
                            <p className="text-muted-foreground">Waiting</p>
                        )}
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">Paid</p>
                        {run.paidAt ? (
                            <p>
                                {formatDate(run.paidAt)}, finished by{' '}
                                {run.paidBy}
                            </p>
                        ) : run.status === 'part_paid' ? (
                            <p>
                                {money(run.paidMinor)} of {money(run.netMinor)}
                            </p>
                        ) : (
                            <p className="text-muted-foreground">Not yet</p>
                        )}
                    </div>
                </PanelBody>
            </Panel>

            {can.pay && (
                <Panel className="mt-3">
                    <PanelHeader
                        title="Record a payment"
                        actions={
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={show.url(run.id, {
                                        query: { export: 'instructions' },
                                    })}
                                    download
                                >
                                    <Download className="size-4" aria-hidden />
                                    Payment instructions
                                </a>
                            </Button>
                        }
                    />
                    <PanelBody>
                        <Form
                            {...pay.form(run.id)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setSelected([])}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ errors: payErrors, processing }) => (
                                <>
                                    {selected.map((id) => (
                                        <input
                                            key={id}
                                            type="hidden"
                                            name="payslips[]"
                                            value={id}
                                        />
                                    ))}
                                    <Field
                                        id="method"
                                        label="How"
                                        error={payErrors.method}
                                    >
                                        <select
                                            id="method"
                                            name="method"
                                            className={selectClass}
                                        >
                                            {paymentMethods.map((method) => (
                                                <option
                                                    key={method}
                                                    value={method}
                                                >
                                                    {METHOD_LABELS[method] ??
                                                        method}
                                                </option>
                                            ))}
                                        </select>
                                    </Field>
                                    <TextField
                                        name="paid_on"
                                        label="On"
                                        type="date"
                                        defaultValue={run.payDate}
                                        required
                                        error={payErrors.paid_on}
                                    />
                                    <TextField
                                        name="reference"
                                        label="Bank batch or reference"
                                        error={payErrors.reference}
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {selected.length > 0
                                            ? `Record ${selected.length} paid (${money(selectedNet)})`
                                            : `Record everyone not paid yet (${unpaid.length}, ${money(outstanding)})`}
                                    </Button>
                                    {payErrors.payslips && (
                                        <p className="basis-full text-sm text-destructive">
                                            {payErrors.payslips}
                                        </p>
                                    )}
                                    <p className="basis-full text-xs text-muted-foreground">
                                        Paying only some people now? Tick them
                                        in the list below first.
                                    </p>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {payments.length > 0 && (
                <Panel className="mt-3">
                    <PanelHeader title="Payments" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Payment</TableHead>
                                <TableHead>On</TableHead>
                                <TableHead>How</TableHead>
                                <TableHead>Reference</TableHead>
                                <TableHead>Recorded by</TableHead>
                                <TableHead className="text-right">
                                    People
                                </TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {payments.map((payment) => (
                                <TableRow key={payment.id}>
                                    <TableCell className="font-medium tabular-nums">
                                        {payment.number}
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(payment.paidOn)}
                                    </TableCell>
                                    <TableCell>
                                        {METHOD_LABELS[payment.method] ??
                                            payment.method}
                                    </TableCell>
                                    <TableCell>
                                        {payment.reference ?? '—'}
                                    </TableCell>
                                    <TableCell>{payment.recordedBy}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {payment.headcount}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(payment.amountMinor)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <a
                                            href={show.url(run.id, {
                                                query: {
                                                    export: 'instructions',
                                                    payment: payment.id,
                                                },
                                            })}
                                            download
                                            className="text-sm hover:underline"
                                        >
                                            Instructions
                                        </a>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            )}

            {can.reverse && (
                <Panel className="mt-3">
                    <PanelHeader title="Reverse this payroll" />
                    <PanelBody>
                        <Form
                            {...reverse.form(run.id)}
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ errors: reverseErrors, processing }) => (
                                <>
                                    <div className="min-w-64 flex-1">
                                        <TextField
                                            name="reason"
                                            label="Why"
                                            required
                                            error={reverseErrors.reason}
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Reverse
                                    </Button>
                                    <p className="basis-full text-xs text-muted-foreground">
                                        Only while nobody has been paid from it.
                                        Finance takes the journal back, the
                                        payslips stay as a record, and{' '}
                                        {run.period} can be prepared again.
                                    </p>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel className="mt-3">
                <PanelHeader
                    title="Payslips"
                    actions={
                        payslips.length > 0 && (
                            <>
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={show.url(run.id, {
                                            query: { export: 'payslips' },
                                        })}
                                        download
                                    >
                                        <Download
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Excel
                                    </a>
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <a href={payslipsPdf.url(run.id)} download>
                                        <FileDown
                                            className="size-4"
                                            aria-hidden
                                        />
                                        All payslips (PDF)
                                    </a>
                                </Button>
                            </>
                        )
                    }
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            {can.pay && (
                                <TableHead className="w-8">
                                    <span className="sr-only">Pay now</span>
                                </TableHead>
                            )}
                            <TableHead>Name</TableHead>
                            <TableHead>Branch</TableHead>
                            <TableHead className="text-right">
                                Earnings
                            </TableHead>
                            <TableHead className="text-right">
                                Income tax
                            </TableHead>
                            <TableHead className="text-right">
                                Social security
                            </TableHead>
                            <TableHead className="text-right">
                                Deductions
                            </TableHead>
                            <TableHead className="text-right">Net</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {payslips.map((slip) => (
                            <TableRow key={slip.id}>
                                {can.pay && (
                                    <TableCell>
                                        {slip.paidIn === null && (
                                            <input
                                                type="checkbox"
                                                className="size-4 align-middle"
                                                checked={selected.includes(
                                                    slip.id,
                                                )}
                                                onChange={() => toggle(slip.id)}
                                                aria-label={`Pay ${slip.name} in this payment`}
                                            />
                                        )}
                                    </TableCell>
                                )}
                                <TableCell>
                                    <Link
                                        href={payslip({
                                            run: run.id,
                                            payslip: slip.id,
                                        })}
                                        className="font-medium hover:underline"
                                    >
                                        {slip.name}
                                    </Link>{' '}
                                    <span className="text-muted-foreground tabular-nums">
                                        {slip.number}
                                    </span>
                                    {slip.paidIn && (
                                        <StatusBadge
                                            tone="success"
                                            className="ml-2"
                                        >
                                            Paid · {slip.paidIn}
                                        </StatusBadge>
                                    )}
                                </TableCell>
                                <TableCell>{slip.branch ?? '—'}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(slip.earningsMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(slip.incomeTaxMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(slip.socialSecurityMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(slip.otherDeductionsMinor)}
                                </TableCell>
                                <TableCell className="text-right font-medium tabular-nums">
                                    {formatMoney(slip.netMinor)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={can.pay ? 3 : 2}>
                                Total
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(run.earningsMinor)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(run.incomeTaxMinor)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(run.socialSecurityEmployeeMinor)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(run.otherDeductionsMinor)}
                            </TableCell>
                            <TableCell className="text-right font-medium tabular-nums">
                                {formatMoney(run.netMinor)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
