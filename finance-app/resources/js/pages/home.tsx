import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Landmark,
    NotebookPen,
    TrendingUp,
} from 'lucide-react';
import { Amount } from '@/components/amount';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useCan } from '@/lib/access';
import { sourceLabel, useMoney } from '@/lib/format';
import { index as banking } from '@/routes/banking';
import { index as bills } from '@/routes/bills';
import { index as invoices } from '@/routes/invoices';
import {
    create as newJournal,
    index as journals,
    show as showJournal,
} from '@/routes/journals';
import { profitAndLoss } from '@/routes/reports';

type Props = {
    cash: { id: string; name: string; balanceMinor: number }[] | null;
    receivable: { outstandingMinor: number; overdue: number } | null;
    payable: {
        outstandingMinor: number;
        overdue: number;
        dueSoon: number;
    } | null;
    month: {
        revenueMinor: number;
        expensesMinor: number;
        profitMinor: number;
    } | null;
    recent:
        | {
              id: string;
              number: string;
              date: string;
              memo: string;
              source: string;
              totalMinor: number;
          }[]
        | null;
};

/**
 * The books at a glance.
 */
export default function Home({
    cash,
    receivable,
    payable,
    month,
    recent,
}: Props) {
    const { workspace } = usePage().props;
    const can = useCan();
    const money = useMoney();
    const cashTotal =
        cash?.reduce((sum, bank) => sum + bank.balanceMinor, 0) ?? 0;

    return (
        <>
            <Head title="Dashboard" />
            <PageHeader
                title="Dashboard"
                description={`The books of ${workspace?.name ?? 'your workspace'}.`}
                actions={
                    can('finance.journals.post') && (
                        <Button asChild>
                            <Link href={newJournal()}>
                                <NotebookPen className="size-4" aria-hidden />
                                New journal
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {cash && (
                    <StatCard
                        label="Cash and bank"
                        value={money(cashTotal)}
                        hint={`${cash.length} accounts`}
                        icon={Landmark}
                        accent="blue"
                        href={banking()}
                    />
                )}
                {receivable && (
                    <StatCard
                        label="Customers owe"
                        value={money(receivable.outstandingMinor)}
                        hint={
                            receivable.overdue > 0
                                ? `${receivable.overdue} invoices overdue`
                                : 'Nothing overdue'
                        }
                        icon={ArrowDownToLine}
                        accent={receivable.overdue > 0 ? 'amber' : 'emerald'}
                        href={invoices()}
                    />
                )}
                {payable && (
                    <StatCard
                        label="Owed to suppliers"
                        value={money(payable.outstandingMinor)}
                        hint={
                            payable.overdue > 0
                                ? `${payable.overdue} bills overdue`
                                : `${payable.dueSoon} due in 7 days`
                        }
                        icon={ArrowUpFromLine}
                        accent={payable.overdue > 0 ? 'red' : 'violet'}
                        href={bills()}
                    />
                )}
                {month && (
                    <StatCard
                        label="Profit this month"
                        value={money(month.profitMinor)}
                        hint={`${money(month.revenueMinor)} in, ${money(month.expensesMinor)} out`}
                        icon={TrendingUp}
                        accent={month.profitMinor >= 0 ? 'emerald' : 'red'}
                        href={profitAndLoss()}
                    />
                )}
            </div>

            <div className="mt-6 grid gap-6 xl:grid-cols-3">
                {recent && (
                    <Panel className="xl:col-span-2">
                        <PanelHeader
                            title="Latest entries"
                            actions={
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={journals()}>All journals</Link>
                                </Button>
                            }
                        />
                        {recent.length === 0 ? (
                            <EmptyState
                                icon={NotebookPen}
                                title="Nothing posted yet"
                            >
                                Sales, payments and supplier bills from your
                                products appear here as they are booked.
                            </EmptyState>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Journal</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Memo</TableHead>
                                        <TableHead>From</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recent.map((journal) => (
                                        <TableRow key={journal.id}>
                                            <TableCell className="font-mono text-xs">
                                                <Link
                                                    href={showJournal(
                                                        journal.id,
                                                    )}
                                                    className="text-primary hover:underline"
                                                >
                                                    {journal.number}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {journal.date}
                                            </TableCell>
                                            <TableCell className="max-w-80 truncate">
                                                {journal.memo}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    tone={
                                                        journal.source ===
                                                        'finance'
                                                            ? 'neutral'
                                                            : 'info'
                                                    }
                                                >
                                                    {sourceLabel(
                                                        journal.source,
                                                    )}
                                                </StatusBadge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Amount
                                                    minor={journal.totalMinor}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Panel>
                )}

                {cash && (
                    <Panel>
                        <PanelHeader title="Bank and cash" />
                        <ul className="divide-y text-sm">
                            {cash.map((bank) => (
                                <li
                                    key={bank.id}
                                    className="flex items-center justify-between gap-3 px-4 py-2.5"
                                >
                                    <span>{bank.name}</span>
                                    <Amount
                                        minor={bank.balanceMinor}
                                        className="font-medium"
                                    />
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}
            </div>
        </>
    );
}
