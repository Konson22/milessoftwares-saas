import { Link } from '@inertiajs/react';
import { CalendarRange, ListTree, ShieldCheck } from 'lucide-react';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useMoney } from '@/lib/format';
import { index as accounts } from '@/routes/accounts';
import { index as approvalRules } from '@/routes/approval-rules';
import { index as audit } from '@/routes/audit';
import { index as periods } from '@/routes/periods';

export type AdminDashboardData = {
    rules: {
        paymentThresholdMinor: number | null;
        paymentReview: boolean;
        journalApproval: boolean;
        journalThresholdMinor: number | null;
    } | null;
    chart: {
        active: number;
        inactive: number;
        banks: number;
        dimensions: number;
    } | null;
    books: { years: number; lockedUntil: string | null };
    audit:
        | {
              id: string;
              at: string;
              by: string | null;
              action: string;
              subject: string | null;
          }[]
        | null;
};

/**
 * Setup and controls for administrators: the rules, the chart's shape and
 * the latest control events. No balances and no transactions.
 */
export function AdminDashboard({ data }: { data: AdminDashboardData }) {
    const money = useMoney();
    const { rules, chart, books } = data;

    return (
        <div className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {rules && (
                    <StatCard
                        label="Payment approval"
                        value={
                            rules.paymentThresholdMinor === null
                                ? 'Every payment'
                                : `From ${money(rules.paymentThresholdMinor)}`
                        }
                        hint={
                            rules.journalApproval
                                ? `Journals: ${rules.journalThresholdMinor === null ? 'every manual journal' : `from ${money(rules.journalThresholdMinor)}`}`
                                : 'Journals: no approval'
                        }
                        icon={ShieldCheck}
                        accent="blue"
                        href={approvalRules()}
                    />
                )}
                {chart && (
                    <StatCard
                        label="Chart of accounts"
                        value={`${chart.active} accounts`}
                        hint={`${chart.banks} bank and cash · ${chart.inactive} closed · ${chart.dimensions} cost centres and projects`}
                        icon={ListTree}
                        accent="violet"
                        href={accounts()}
                    />
                )}
                <StatCard
                    label="Financial years"
                    value={`${books.years}`}
                    hint={
                        books.lockedUntil
                            ? `Locked to ${books.lockedUntil}`
                            : 'No month locked yet'
                    }
                    icon={CalendarRange}
                    accent="emerald"
                    href={periods()}
                />
            </div>

            {data.audit && (
                <Panel>
                    <PanelHeader
                        title="Latest control events"
                        actions={
                            <Link
                                href={audit()}
                                className="text-sm text-primary hover:underline"
                            >
                                Audit trail
                            </Link>
                        }
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead>What</TableHead>
                                <TableHead>Record</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.audit.length === 0 && (
                                <TableEmpty colSpan={4}>
                                    Nothing recorded yet.
                                </TableEmpty>
                            )}
                            {data.audit.map((event) => (
                                <TableRow key={event.id}>
                                    <TableCell className="whitespace-nowrap tabular-nums">
                                        {new Date(event.at).toLocaleString()}
                                    </TableCell>
                                    <TableCell>
                                        {event.by ?? 'System'}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {event.action}
                                    </TableCell>
                                    <TableCell>{event.subject}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            )}
        </div>
    );
}
