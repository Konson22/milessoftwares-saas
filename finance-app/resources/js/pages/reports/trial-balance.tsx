import { Head, Link } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import {
    DimensionFilter,
    dimensionFilterLabel,
} from '@/components/dimension-filter';
import type { DimensionFilterProps } from '@/components/dimension-filter';
import { PeriodFilter } from '@/components/period-filter';
import { ReportActions } from '@/components/report-actions';
import { StatusBadge } from '@miles/ui/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { show as showAccount } from '@/routes/accounts';
import { trialBalance } from '@/routes/reports';

type Props = DimensionFilterProps & {
    asOf: string;
    rows: {
        id: string;
        code: string;
        name: string;
        type: string;
        debitMinor: number;
        creditMinor: number;
    }[];
    debitMinor: number;
    creditMinor: number;
};

/**
 * Every account's balance on a day; debits and credits must agree.
 */
export default function TrialBalance({
    asOf,
    rows,
    debitMinor,
    creditMinor,
    costCentre,
    project,
    dimensionOptions,
}: Props) {
    const filter = { costCentre, project, dimensionOptions };
    const filtered = dimensionFilterLabel(filter);

    return (
        <>
            <Head title="Trial balance" />
            <PageHeader
                title="Trial balance"
                description={`As of ${asOf}${filtered ? ` · ${filtered}` : ''}`}
                badges={
                    filtered ? (
                        <StatusBadge tone="neutral">
                            Only lines with this filter
                        </StatusBadge>
                    ) : debitMinor === creditMinor ? (
                        <StatusBadge tone="success">Balanced</StatusBadge>
                    ) : (
                        <StatusBadge tone="danger">Out of balance</StatusBadge>
                    )
                }
            />
            <Panel className="max-w-4xl">
                <PanelToolbar>
                    <PeriodFilter form={trialBalance.form()} asOf={asOf}>
                        <DimensionFilter {...filter} />
                    </PeriodFilter>
                    <ReportActions
                        route={trialBalance}
                        query={{
                            as_of: asOf,
                            cost_centre: costCentre,
                            project,
                        }}
                        exports={[{ key: 'accounts', title: 'Accounts' }]}
                    />
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-24">Code</TableHead>
                            <TableHead>Account</TableHead>
                            <TableHead className="text-right">Debit</TableHead>
                            <TableHead className="text-right">Credit</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 && (
                            <TableEmpty colSpan={4}>
                                Nothing posted yet.
                            </TableEmpty>
                        )}
                        {rows.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell className="font-mono text-xs">
                                    {row.code}
                                </TableCell>
                                <TableCell>
                                    <Link
                                        href={showAccount(row.id)}
                                        className="hover:underline"
                                    >
                                        {row.name}
                                    </Link>
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={row.debitMinor} blankZero />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={row.creditMinor} blankZero />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={2}>Totals</TableCell>
                            <TableCell className="text-right">
                                <Amount minor={debitMinor} />
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount minor={creditMinor} />
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
