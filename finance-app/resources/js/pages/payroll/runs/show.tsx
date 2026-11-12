import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Download, FileDown } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
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
import {
    Field,
    formatDate,
    selectClass,
    TextField,
} from '@/components/payroll/fields';
import { RunStatus } from '@/components/payroll/run-status';
import { formatMoney } from '@/lib/payroll/money';
import {
    approve,
    destroy,
    pay,
    payslip,
    recalculate,
    show,
} from '@/routes/payroll/runs';
import { pdf as payslipsPdf } from '@/routes/payroll/runs/payslips';

type Props = {
    currency: string | null;
    run: {
        id: string;
        number: string;
        period: string;
        status: 'draft' | 'approved' | 'paid';
        headcount: number;
        netMinor: number;
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
        approvedBy: string | null;
        approvedAt: string | null;
        paidBy: string | null;
        paidAt: string | null;
        paymentMethod: string | null;
        note: string | null;
    };
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
    }[];
    can: {
        prepare: boolean;
        approve: boolean;
        approveSelf: boolean;
        pay: boolean;
    };
    paymentMethods: string[];
};

const METHOD_LABELS: Record<string, string> = {
    bank: 'Bank transfer',
    cash: 'Cash',
    mobile_money: 'Mobile money',
};

export default function ShowRun({
    currency,
    run,
    payslips,
    can,
    paymentMethods,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const money = (minor: number) => formatMoney(minor, currency);

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
                    hint={`${run.headcount} people, paid ${formatDate(run.payDate)}`}
                    accent="emerald"
                />
            </div>

            <Panel className="mt-3">
                <PanelHeader title="Who checked it" />
                <PanelBody className="grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Prepared
                        </p>
                        <p>
                            {run.preparedBy},{' '}
                            {new Date(run.preparedAt).toLocaleString()}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Approved
                        </p>
                        {run.approvedBy ? (
                            <p>
                                {run.approvedBy},{' '}
                                {new Date(
                                    run.approvedAt ?? '',
                                ).toLocaleString()}
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
                                {formatDate(run.paidAt)} by{' '}
                                {METHOD_LABELS[run.paymentMethod ?? ''] ??
                                    run.paymentMethod}
                                , recorded by {run.paidBy}
                            </p>
                        ) : can.pay ? (
                            <Form
                                {...pay.form(run.id)}
                                className="flex flex-wrap items-end gap-2"
                            >
                                {({ errors: payErrors, processing }) => (
                                    <>
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
                                                {paymentMethods.map(
                                                    (method) => (
                                                        <option
                                                            key={method}
                                                            value={method}
                                                        >
                                                            {METHOD_LABELS[
                                                                method
                                                            ] ?? method}
                                                        </option>
                                                    ),
                                                )}
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
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Record as paid
                                        </Button>
                                    </>
                                )}
                            </Form>
                        ) : (
                            <p className="text-muted-foreground">Not yet</p>
                        )}
                    </div>
                </PanelBody>
            </Panel>

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
                            <TableCell colSpan={2}>Total</TableCell>
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
