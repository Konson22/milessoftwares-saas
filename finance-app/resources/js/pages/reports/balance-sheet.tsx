import { Head } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { PeriodFilter } from '@/components/period-filter';
import { StatementSection } from '@/components/statement';
import type { StatementRow } from '@/components/statement';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { balanceSheet } from '@/routes/reports';

type Props = {
    asOf: string;
    assets: StatementRow[];
    liabilities: StatementRow[];
    equity: StatementRow[];
    earningsMinor: number;
    assetsMinor: number;
    liabilitiesMinor: number;
    equityMinor: number;
};

/**
 * What the organization owns and owes on a day.
 */
export default function BalanceSheet({
    asOf,
    assets,
    liabilities,
    equity,
    earningsMinor,
    assetsMinor,
    liabilitiesMinor,
    equityMinor,
}: Props) {
    const balanced = assetsMinor === liabilitiesMinor + equityMinor;

    return (
        <>
            <Head title="Balance sheet" />
            <PageHeader
                title="Balance sheet"
                description={`As of ${asOf}`}
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
            <Panel className="max-w-3xl">
                <PanelToolbar>
                    <PeriodFilter form={balanceSheet.form()} asOf={asOf} />
                </PanelToolbar>
                <StatementSection
                    title="Assets"
                    rows={assets}
                    totalLabel="Total assets"
                    totalMinor={assetsMinor}
                />
                <StatementSection
                    title="Liabilities"
                    rows={liabilities}
                    totalLabel="Total liabilities"
                    totalMinor={liabilitiesMinor}
                />
                <StatementSection
                    title="Equity"
                    rows={equity}
                    extra={{
                        label: 'Current earnings (profit not yet closed)',
                        amountMinor: earningsMinor,
                    }}
                    totalLabel="Total equity"
                    totalMinor={equityMinor}
                />
                <p className="flex justify-between gap-4 px-4 py-3 text-base font-semibold">
                    <span>Liabilities and equity</span>
                    <Amount minor={liabilitiesMinor + equityMinor} />
                </p>
            </Panel>
        </>
    );
}
