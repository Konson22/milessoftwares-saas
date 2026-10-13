import { Head } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelToolbar } from '@/components/panel';
import { PeriodFilter } from '@/components/period-filter';
import { StatementSection } from '@/components/statement';
import type { StatementRow } from '@/components/statement';
import { profitAndLoss } from '@/routes/reports';

type Props = {
    from: string;
    to: string;
    revenue: StatementRow[];
    expenses: StatementRow[];
    revenueMinor: number;
    expensesMinor: number;
    profitMinor: number;
};

/**
 * What was earned and spent in a period.
 */
export default function ProfitAndLoss({
    from,
    to,
    revenue,
    expenses,
    revenueMinor,
    expensesMinor,
    profitMinor,
}: Props) {
    return (
        <>
            <Head title="Profit & loss" />
            <PageHeader
                title="Profit & loss"
                description={`${from} to ${to}`}
            />
            <Panel className="max-w-3xl">
                <PanelToolbar>
                    <PeriodFilter
                        form={profitAndLoss.form()}
                        from={from}
                        to={to}
                    />
                </PanelToolbar>
                <StatementSection
                    title="Revenue"
                    rows={revenue}
                    totalLabel="Total revenue"
                    totalMinor={revenueMinor}
                />
                <StatementSection
                    title="Expenses"
                    rows={expenses}
                    totalLabel="Total expenses"
                    totalMinor={expensesMinor}
                />
                <p className="flex justify-between gap-4 px-4 py-3 text-base font-semibold">
                    <span>{profitMinor >= 0 ? 'Net profit' : 'Net loss'}</span>
                    <Amount minor={profitMinor} />
                </p>
            </Panel>
        </>
    );
}
