import { Link } from '@inertiajs/react';
import {
    ArrowUpFromLine,
    Banknote,
    Hourglass,
    Landmark,
    Receipt,
    TrendingUp,
    Waves,
} from 'lucide-react';
import { Amount } from '@/components/amount';
import { UsageBar } from '@/components/usage-bar';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { useMoney } from '@/lib/format';
import { index as banking } from '@/routes/banking';
import { index as bills } from '@/routes/bills';
import { index as paymentRequests } from '@/routes/payment-requests';
import {
    budgetVsActual,
    cashFlow as cashFlowReport,
    profitAndLoss,
    profitAndLossByDimension,
} from '@/routes/reports';

type Tally = { count: number; minor: number };

export type FinanceDashboardData = {
    queue: { review: Tally; approval: Tally; disbursement: Tally } | null;
    expenses: Tally | null;
    budget: {
        id: string;
        name: string;
        budgetMinor: number;
        actualMinor: number;
        percent: number | null;
        accounts: {
            id: string;
            name: string;
            budgetMinor: number;
            actualMinor: number;
            percent: number | null;
        }[];
    } | null;
    cashFlow: {
        operatingMinor: number;
        investingMinor: number;
        financingMinor: number;
        netChangeMinor: number;
        openingCashMinor: number;
        closingCashMinor: number;
    } | null;
    performance: {
        revenueMinor: number;
        expensesMinor: number;
        profitMinor: number;
        previousProfitMinor: number;
        marginPercent: number | null;
    } | null;
    departments: { id: string; label: string; minor: number }[] | null;
};

type Props = {
    data: FinanceDashboardData;
    cash: { id: string; name: string; balanceMinor: number }[] | null;
    payable: {
        outstandingMinor: number;
        overdue: number;
        dueSoon: number;
    } | null;
};

function requests(count: number): string {
    return count === 1 ? '1 request' : `${count} requests`;
}

/**
 * Financial management: money available, what is waiting to be paid, and how
 * spending compares with the plan. Drawn from the same ledger as accounting's.
 */
export function FinanceDashboard({ data, cash, payable }: Props) {
    const money = useMoney();
    const { queue, expenses, budget, cashFlow, performance, departments } =
        data;
    const cashTotal = cash?.reduce((sum, bank) => sum + bank.balanceMinor, 0);
    const largest = Math.max(...(departments ?? []).map((row) => row.minor), 0);

    return (
        <div className="grid gap-3">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {cash && (
                    <StatCard
                        label="Available cash and bank"
                        value={money(cashTotal ?? 0)}
                        hint={`${cash.length} accounts`}
                        icon={Landmark}
                        accent="blue"
                        href={banking()}
                    />
                )}
                {queue && (
                    <StatCard
                        label="Payments to approve"
                        value={money(queue.approval.minor)}
                        hint={
                            queue.review.count > 0
                                ? `${requests(queue.approval.count)}, ${queue.review.count} more in review`
                                : requests(queue.approval.count)
                        }
                        icon={Hourglass}
                        accent={queue.approval.count > 0 ? 'amber' : 'emerald'}
                        href={paymentRequests()}
                    />
                )}
                {expenses && (
                    <StatCard
                        label="Expense requests pending"
                        value={money(expenses.minor)}
                        hint={`${requests(expenses.count)}, with advances`}
                        icon={Receipt}
                        accent={expenses.count > 0 ? 'amber' : 'emerald'}
                        href={paymentRequests()}
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
                {queue && (
                    <StatCard
                        label="Approved, waiting to be paid"
                        value={money(queue.disbursement.minor)}
                        hint={requests(queue.disbursement.count)}
                        icon={Banknote}
                        accent="slate"
                        href={paymentRequests()}
                    />
                )}
                {performance && (
                    <StatCard
                        label="Profit this month"
                        value={money(performance.profitMinor)}
                        hint={`${money(performance.previousProfitMinor)} last month`}
                        icon={TrendingUp}
                        accent={
                            performance.profitMinor >= 0 ? 'emerald' : 'red'
                        }
                        href={profitAndLoss()}
                    />
                )}
                {cashFlow && (
                    <StatCard
                        label="Cash flow this month"
                        value={money(cashFlow.netChangeMinor)}
                        hint={`${money(cashFlow.closingCashMinor)} at today's close`}
                        icon={Waves}
                        accent={
                            cashFlow.netChangeMinor >= 0 ? 'emerald' : 'red'
                        }
                        href={cashFlowReport()}
                    />
                )}
            </div>

            <div className="grid gap-3 xl:grid-cols-2">
                {budget && (
                    <Panel>
                        <PanelHeader
                            title="Budget utilization"
                            description={`${budget.name}, spending this year to date`}
                            actions={
                                <Link
                                    href={budgetVsActual()}
                                    className="text-sm text-primary hover:underline"
                                >
                                    Budget vs actual
                                </Link>
                            }
                        />
                        <PanelBody className="grid gap-3">
                            <UsageBar
                                label="All spending"
                                actualMinor={budget.actualMinor}
                                budgetMinor={budget.budgetMinor}
                                percent={budget.percent}
                            />
                            {budget.accounts.map((account) => (
                                <UsageBar
                                    key={account.id}
                                    label={account.name}
                                    actualMinor={account.actualMinor}
                                    budgetMinor={account.budgetMinor}
                                    percent={account.percent}
                                />
                            ))}
                        </PanelBody>
                    </Panel>
                )}

                {departments && (
                    <Panel>
                        <PanelHeader
                            title="Departmental expenditure"
                            description="This month, by cost centre"
                            actions={
                                <Link
                                    href={profitAndLossByDimension()}
                                    className="text-sm text-primary hover:underline"
                                >
                                    By dimension
                                </Link>
                            }
                        />
                        <PanelBody className="grid gap-2">
                            {departments.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No spending this month.
                                </p>
                            )}
                            {departments.map((row) => (
                                <div
                                    key={row.id}
                                    className="grid gap-1 text-sm"
                                    title={`${row.label}: ${money(row.minor)}`}
                                >
                                    <div className="flex justify-between gap-3">
                                        <span className="truncate">
                                            {row.label}
                                        </span>
                                        <Amount
                                            minor={row.minor}
                                            className="font-medium"
                                        />
                                    </div>
                                    <div className="h-2 rounded-sm bg-muted">
                                        <div
                                            className="h-2 rounded-sm bg-primary"
                                            style={{
                                                width: `${largest === 0 ? 0 : Math.max((row.minor / largest) * 100, 1)}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </PanelBody>
                    </Panel>
                )}

                {cashFlow && (
                    <Panel>
                        <PanelHeader
                            title="Cash flow"
                            description="This month"
                        />
                        <dl className="divide-y text-sm">
                            {(
                                [
                                    ['Opening', cashFlow.openingCashMinor],
                                    ['Operating', cashFlow.operatingMinor],
                                    ['Investing', cashFlow.investingMinor],
                                    ['Financing', cashFlow.financingMinor],
                                    ['Closing', cashFlow.closingCashMinor],
                                ] as const
                            ).map(([label, minor]) => (
                                <div
                                    key={label}
                                    className="flex justify-between gap-3 px-4 py-2"
                                >
                                    <dt>{label}</dt>
                                    <dd>
                                        <Amount minor={minor} />
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Panel>
                )}

                {performance && (
                    <Panel>
                        <PanelHeader
                            title="Financial performance"
                            description="This month"
                            actions={
                                performance.marginPercent !== null && (
                                    <StatusBadge
                                        tone={
                                            performance.marginPercent >= 0
                                                ? 'success'
                                                : 'danger'
                                        }
                                    >
                                        {performance.marginPercent}% margin
                                    </StatusBadge>
                                )
                            }
                        />
                        <dl className="divide-y text-sm">
                            {(
                                [
                                    ['Revenue', performance.revenueMinor],
                                    ['Expenses', performance.expensesMinor],
                                    ['Profit', performance.profitMinor],
                                ] as const
                            ).map(([label, minor]) => (
                                <div
                                    key={label}
                                    className="flex justify-between gap-3 px-4 py-2"
                                >
                                    <dt>{label}</dt>
                                    <dd>
                                        <Amount minor={minor} />
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </Panel>
                )}
            </div>
        </div>
    );
}
