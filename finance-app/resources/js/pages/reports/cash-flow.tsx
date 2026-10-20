import { Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Amount } from '@/components/amount';
import { PeriodFilter } from '@/components/period-filter';
import { ReportActions } from '@/components/report-actions';
import { StatementSection, StatementTotal } from '@/components/statement';
import type { StatementRow } from '@/components/statement';
import { cashFlow } from '@/routes/reports';

type Props = {
    from: string;
    to: string;
    profitMinor: number;
    operating: StatementRow[];
    investing: StatementRow[];
    financing: StatementRow[];
    operatingMinor: number;
    investingMinor: number;
    financingMinor: number;
    netChangeMinor: number;
    openingCashMinor: number;
    closingCashMinor: number;
};

/**
 * Where the period's cash came from and went (indirect method): the profit,
 * adjusted by every other balance sheet movement, by activity.
 */
export default function CashFlow({
    from,
    to,
    profitMinor,
    operating,
    investing,
    financing,
    operatingMinor,
    investingMinor,
    financingMinor,
    netChangeMinor,
    openingCashMinor,
    closingCashMinor,
}: Props) {
    const agrees = openingCashMinor + netChangeMinor === closingCashMinor;

    return (
        <>
            <Head title="Cash flow" />
            <PageHeader
                title="Cash flow"
                description={`${from} to ${to}. Movements of each account count where the chart of accounts classifies it.`}
                badges={
                    agrees ? (
                        <StatusBadge tone="success">
                            Agrees with bank and cash
                        </StatusBadge>
                    ) : (
                        <StatusBadge tone="danger">
                            Does not agree with bank and cash
                        </StatusBadge>
                    )
                }
            />
            <Panel className="max-w-3xl">
                <PanelToolbar>
                    <PeriodFilter form={cashFlow.form()} from={from} to={to} />
                    <ReportActions
                        route={cashFlow}
                        query={{ from, to }}
                        exports={[{ key: 'statement', title: 'Statement' }]}
                    />
                </PanelToolbar>
                <p className="flex justify-between gap-4 border-b px-4 py-2 text-sm">
                    <span>Net profit</span>
                    <Amount minor={profitMinor} className="w-32 text-right" />
                </p>
                <StatementSection
                    title="Operating activities: changes in working capital"
                    rows={operating}
                    totalLabel="Cash from operating activities"
                    totalMinor={operatingMinor}
                />
                <StatementSection
                    title="Investing activities"
                    rows={investing}
                    totalLabel="Cash from investing activities"
                    totalMinor={investingMinor}
                />
                <StatementSection
                    title="Financing activities"
                    rows={financing}
                    totalLabel="Cash from financing activities"
                    totalMinor={financingMinor}
                />
                <StatementTotal
                    label="Net change in cash"
                    minor={netChangeMinor}
                />
                <div className="border-t px-4 py-2 text-sm text-muted-foreground">
                    <p className="flex justify-between gap-4">
                        <span>Bank and cash at the start</span>
                        <Amount
                            minor={openingCashMinor}
                            className="w-32 text-right"
                        />
                    </p>
                    <p className="flex justify-between gap-4">
                        <span>Bank and cash at the end</span>
                        <Amount
                            minor={closingCashMinor}
                            className="w-32 text-right"
                        />
                    </p>
                </div>
            </Panel>
        </>
    );
}
