import { Head, Link } from '@inertiajs/react';
import { Banknote, Scale, UserX, Users } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { RunStatus } from '@/components/payroll/run-status';
import { formatMoney } from '@/lib/payroll/money';
import { settings } from '@/routes/payroll';
import { index as employees } from '@/routes/payroll/employees';
import { index as runsIndex, show } from '@/routes/payroll/runs';

type Props = {
    currency: string | null;
    headcount: number;
    withoutPay: number | null;
    rulesInForce: string | null;
    runs: {
        id: string;
        number: string;
        period: string;
        status: string;
        headcount: number;
        netMinor: number;
    }[];
};

export default function Home({
    currency,
    headcount,
    withoutPay,
    rulesInForce,
    runs,
}: Props) {
    const seesPay = withoutPay !== null;

    return (
        <>
            <Head title="Home" />
            <PageHeader title="Payroll" />

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="On the payroll"
                    value={headcount}
                    icon={Users}
                    href={employees()}
                />
                {seesPay && (
                    <>
                        <StatCard
                            label="Without pay set"
                            value={withoutPay}
                            icon={UserX}
                            accent={withoutPay > 0 ? 'amber' : 'slate'}
                            hint="Left out of payroll runs"
                            href={employees()}
                        />
                        <StatCard
                            label="Tax and social security"
                            value={rulesInForce ?? 'Not set up'}
                            icon={Scale}
                            accent={rulesInForce ? 'emerald' : 'red'}
                            href={settings()}
                        />
                        <StatCard
                            label="Last payroll"
                            value={
                                runs[0]
                                    ? formatMoney(runs[0].netMinor, currency)
                                    : '—'
                            }
                            hint={runs[0]?.period}
                            icon={Banknote}
                            href={runsIndex()}
                        />
                    </>
                )}
            </div>

            {seesPay && runs.length > 0 && (
                <Panel className="mt-4">
                    <PanelHeader title="Latest payroll runs" />
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
                </Panel>
            )}
        </>
    );
}
