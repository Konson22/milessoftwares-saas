import { Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import {
    DimensionFilter,
    dimensionFilterLabel,
} from '@/components/dimension-filter';
import type { DimensionFilterProps } from '@/components/dimension-filter';
import { PeriodFilter } from '@/components/period-filter';
import { ReportActions } from '@/components/report-actions';
import {
    StatementHeading,
    StatementSection,
    StatementTotal,
} from '@/components/statement';
import type { StatementRow } from '@/components/statement';
import { profitAndLoss } from '@/routes/reports';

type Totals = {
    revenueMinor: number;
    expensesMinor: number;
    profitMinor: number;
};

type Props = Totals &
    DimensionFilterProps & {
        from: string;
        to: string;
        compareWith: string | null;
        compareLabel: string | null;
        revenue: StatementRow[];
        expenses: StatementRow[];
        compare?: Totals;
    };

/**
 * What was earned and spent in a period, optionally next to another period.
 */
export default function ProfitAndLoss({
    from,
    to,
    compareWith,
    compareLabel,
    revenue,
    expenses,
    revenueMinor,
    expensesMinor,
    profitMinor,
    compare,
    costCentre,
    project,
    dimensionOptions,
}: Props) {
    const filter = { costCentre, project, dimensionOptions };
    const filtered = dimensionFilterLabel(filter);

    return (
        <>
            <Head title="Profit & loss" />
            <PageHeader
                title="Profit & loss"
                description={`${from} to ${to}${compareLabel ? `, compared with ${compareLabel}` : ''}${filtered ? ` · ${filtered}` : ''}`}
            />
            <Panel className={compare ? 'max-w-4xl' : 'max-w-3xl'}>
                <PanelToolbar>
                    <PeriodFilter
                        form={profitAndLoss.form()}
                        from={from}
                        to={to}
                        compare={{
                            value: compareWith,
                            options: [
                                {
                                    value: 'previous_period',
                                    label: 'Previous period',
                                },
                                {
                                    value: 'previous_year',
                                    label: 'Same period last year',
                                },
                            ],
                        }}
                    >
                        <DimensionFilter {...filter} />
                    </PeriodFilter>
                    <ReportActions
                        route={profitAndLoss}
                        query={{
                            from,
                            to,
                            compare: compareWith,
                            cost_centre: costCentre,
                            project,
                        }}
                        exports={[{ key: 'statement', title: 'Statement' }]}
                    />
                </PanelToolbar>
                <StatementHeading
                    current={`${from} – ${to}`}
                    compared={compareLabel}
                />
                <StatementSection
                    title="Revenue"
                    rows={revenue}
                    totalLabel="Total revenue"
                    totalMinor={revenueMinor}
                    compareTotalMinor={compare?.revenueMinor}
                />
                <StatementSection
                    title="Expenses"
                    rows={expenses}
                    totalLabel="Total expenses"
                    totalMinor={expensesMinor}
                    compareTotalMinor={compare?.expensesMinor}
                />
                <StatementTotal
                    label={profitMinor >= 0 ? 'Net profit' : 'Net loss'}
                    minor={profitMinor}
                    compareMinor={compare?.profitMinor}
                />
            </Panel>
        </>
    );
}
