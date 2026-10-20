import { Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { PeriodFilter } from '@/components/period-filter';
import { ReportActions } from '@/components/report-actions';
import {
    StatementHeading,
    StatementSection,
    StatementTotal,
} from '@/components/statement';
import type { StatementRow } from '@/components/statement';
import { balanceSheet } from '@/routes/reports';

type Totals = {
    earningsMinor: number;
    assetsMinor: number;
    liabilitiesMinor: number;
    equityMinor: number;
};

type Props = Totals & {
    asOf: string;
    compareWith: string | null;
    compareLabel: string | null;
    assets: StatementRow[];
    liabilities: StatementRow[];
    equity: StatementRow[];
    compare?: Totals;
};

/**
 * What the organization owns and owes on a day, optionally next to another day.
 */
export default function BalanceSheet({
    asOf,
    compareWith,
    compareLabel,
    assets,
    liabilities,
    equity,
    earningsMinor,
    assetsMinor,
    liabilitiesMinor,
    equityMinor,
    compare,
}: Props) {
    const balanced = assetsMinor === liabilitiesMinor + equityMinor;

    return (
        <>
            <Head title="Balance sheet" />
            <PageHeader
                title="Balance sheet"
                description={`As of ${asOf}${compareLabel ? `, compared with ${compareLabel}` : ''}`}
                badges={
                    balanced ? (
                        <StatusBadge tone="success">Balances</StatusBadge>
                    ) : (
                        <StatusBadge tone="danger">
                            Does not balance
                        </StatusBadge>
                    )
                }
            />
            <Panel className={compare ? 'max-w-4xl' : 'max-w-3xl'}>
                <PanelToolbar>
                    <PeriodFilter
                        form={balanceSheet.form()}
                        asOf={asOf}
                        compare={{
                            value: compareWith,
                            options: [
                                {
                                    value: 'previous_month',
                                    label: 'End of previous month',
                                },
                                {
                                    value: 'previous_year',
                                    label: 'Same day last year',
                                },
                            ],
                        }}
                    />
                    <ReportActions
                        route={balanceSheet}
                        query={{ as_of: asOf, compare: compareWith }}
                        exports={[{ key: 'statement', title: 'Statement' }]}
                    />
                </PanelToolbar>
                <StatementHeading current={asOf} compared={compareLabel} />
                <StatementSection
                    title="Assets"
                    rows={assets}
                    totalLabel="Total assets"
                    totalMinor={assetsMinor}
                    compareTotalMinor={compare?.assetsMinor}
                />
                <StatementSection
                    title="Liabilities"
                    rows={liabilities}
                    totalLabel="Total liabilities"
                    totalMinor={liabilitiesMinor}
                    compareTotalMinor={compare?.liabilitiesMinor}
                />
                <StatementSection
                    title="Equity"
                    rows={equity}
                    extra={{
                        label: 'Current earnings (profit not yet closed)',
                        amountMinor: earningsMinor,
                        compareMinor: compare?.earningsMinor,
                    }}
                    totalLabel="Total equity"
                    totalMinor={equityMinor}
                    compareTotalMinor={compare?.equityMinor}
                />
                <StatementTotal
                    label="Liabilities and equity"
                    minor={liabilitiesMinor + equityMinor}
                    compareMinor={
                        compare &&
                        compare.liabilitiesMinor + compare.equityMinor
                    }
                />
            </Panel>
        </>
    );
}
