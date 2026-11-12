import { Form, Head, Link } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { TextField } from '@/components/payroll/fields';
import { RunStatus } from '@/components/payroll/run-status';
import { formatMoney } from '@/lib/payroll/money';
import { show, store } from '@/routes/payroll/runs';

type Props = {
    currency: string | null;
    runs: {
        id: string;
        number: string;
        period: string;
        status: string;
        headcount: number;
        netMinor: number;
    }[];
    defaultMonth: string;
    defaultPayDate: string;
    canPrepare: boolean;
};

export default function Runs({
    currency,
    runs,
    defaultMonth,
    defaultPayDate,
    canPrepare,
}: Props) {
    return (
        <>
            <Head title="Payroll runs" />
            <PageHeader title="Payroll runs" />

            {canPrepare && (
                <Panel className="mb-3">
                    <PanelHeader title="Prepare a month" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <TextField
                                        name="month"
                                        label="Month"
                                        type="month"
                                        defaultValue={defaultMonth}
                                        required
                                        error={errors.month ?? errors.run}
                                    />
                                    <TextField
                                        name="pay_date"
                                        label="Pay day"
                                        type="date"
                                        defaultValue={defaultPayDate}
                                        required
                                        error={errors.pay_date}
                                    />
                                    <TextField
                                        name="note"
                                        label="Note"
                                        error={errors.note}
                                    />
                                    <div className="flex items-end">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Prepare payroll
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                {runs.length === 0 ? (
                    <EmptyState icon={Banknote} title="No payroll yet">
                        Set everyone's pay, then prepare the first month.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Run</TableHead>
                                <TableHead>Month</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    People
                                </TableHead>
                                <TableHead className="text-right">
                                    Net pay
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {runs.map((run) => (
                                <TableRow key={run.id}>
                                    <TableCell>
                                        <Link
                                            href={show(run.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {run.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{run.period}</TableCell>
                                    <TableCell>
                                        <RunStatus status={run.status} />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {run.headcount}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(run.netMinor, currency)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
