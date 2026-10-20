import { Head, Link } from '@inertiajs/react';
import { Fragment } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Amount } from '@/components/amount';
import { Select } from '@/components/field';
import { PeriodFilter } from '@/components/period-filter';
import { ReportActions } from '@/components/report-actions';
import { profitAndLoss, profitAndLossByDimension } from '@/routes/reports';

type Row = {
    id: string;
    code: string;
    name: string;
    amounts: Record<string, number>;
    amountMinor: number;
};

type Props = {
    from: string;
    to: string;
    by: 'cost_centre' | 'project';
    columns: { id: string; label: string }[];
    revenue: Row[];
    expenses: Row[];
    revenueTotals: Record<string, number>;
    expenseTotals: Record<string, number>;
    profit: Record<string, number>;
    revenueMinor: number;
    expensesMinor: number;
    profitMinor: number;
};

const KINDS = { cost_centre: 'Cost centre', project: 'Project' } as const;

/**
 * Profit and loss with a column per cost centre or project; the total
 * column is the plain profit and loss.
 */
export default function ProfitAndLossByDimension({
    from,
    to,
    by,
    columns,
    revenue,
    expenses,
    revenueTotals,
    expenseTotals,
    profit,
    revenueMinor,
    expensesMinor,
    profitMinor,
}: Props) {
    const span = columns.length + 2;
    const filterKey = by === 'project' ? 'project' : 'cost_centre';

    const totalRow = (
        label: string,
        totals: Record<string, number>,
        total: number,
        strong = false,
    ) => (
        <TableRow className={strong ? 'border-t-2' : undefined}>
            <TableCell className={strong ? 'font-semibold' : 'font-medium'}>
                {label}
            </TableCell>
            {columns.map((column) => (
                <TableCell
                    key={column.id}
                    className={`text-right ${strong ? 'font-semibold' : 'font-medium'}`}
                >
                    <Amount minor={totals[column.id] ?? 0} />
                </TableCell>
            ))}
            <TableCell
                className={`text-right ${strong ? 'font-semibold' : 'font-medium'}`}
            >
                <Amount minor={total} />
            </TableCell>
        </TableRow>
    );

    const section = (title: string, rows: Row[]) => (
        <>
            <TableRow>
                <TableCell colSpan={span} className="pt-4 font-semibold">
                    {title}
                </TableCell>
            </TableRow>
            {rows.map((row) => (
                <TableRow key={row.id}>
                    <TableCell className="pl-6">
                        <span className="text-muted-foreground tabular-nums">
                            {row.code}
                        </span>{' '}
                        {row.name}
                    </TableCell>
                    {columns.map((column) => (
                        <TableCell key={column.id} className="text-right">
                            <Amount
                                minor={row.amounts[column.id] ?? 0}
                                blankZero
                            />
                        </TableCell>
                    ))}
                    <TableCell className="text-right">
                        <Amount minor={row.amountMinor} />
                    </TableCell>
                </TableRow>
            ))}
        </>
    );

    return (
        <>
            <Head title={`P&L by ${KINDS[by].toLowerCase()}`} />
            <PageHeader
                title={`Profit & loss by ${KINDS[by].toLowerCase()}`}
                description={`${from} to ${to}`}
            />
            <Panel>
                <PanelToolbar>
                    <PeriodFilter
                        form={profitAndLossByDimension.form()}
                        from={from}
                        to={to}
                    >
                        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                            By
                            <Select
                                name="by"
                                defaultValue={by}
                                className="w-40"
                            >
                                <option value="cost_centre">Cost centre</option>
                                <option value="project">Project</option>
                            </Select>
                        </label>
                    </PeriodFilter>
                    <ReportActions
                        route={profitAndLossByDimension}
                        query={{ from, to, by }}
                        exports={[{ key: 'statement', title: 'Statement' }]}
                    />
                </PanelToolbar>
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="min-w-56">
                                    Account
                                </TableHead>
                                {columns.map((column) => (
                                    <TableHead
                                        key={column.id}
                                        className="text-right whitespace-nowrap"
                                    >
                                        <Link
                                            href={
                                                profitAndLoss({
                                                    query: {
                                                        from,
                                                        to,
                                                        [filterKey]: column.id,
                                                    },
                                                }).url
                                            }
                                            className="hover:underline"
                                        >
                                            {column.label}
                                        </Link>
                                    </TableHead>
                                ))}
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {revenue.length === 0 && expenses.length === 0 ? (
                                <TableEmpty colSpan={span}>
                                    No income or spending in this period.
                                </TableEmpty>
                            ) : (
                                <Fragment>
                                    {section('Revenue', revenue)}
                                    {totalRow(
                                        'Total revenue',
                                        revenueTotals,
                                        revenueMinor,
                                    )}
                                    {section('Expenses', expenses)}
                                    {totalRow(
                                        'Total expenses',
                                        expenseTotals,
                                        expensesMinor,
                                    )}
                                    {totalRow(
                                        'Net profit',
                                        profit,
                                        profitMinor,
                                        true,
                                    )}
                                </Fragment>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </Panel>
        </>
    );
}
