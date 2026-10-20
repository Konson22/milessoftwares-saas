import { Form, Head, Link } from '@inertiajs/react';
import { Target } from 'lucide-react';
import { Amount } from '@/components/amount';
import { Select } from '@/components/field';
import { ReportActions } from '@/components/report-actions';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { cn } from '@miles/ui/lib/utils';
import { index as budgetsIndex } from '@/routes/budgets';
import { budgetVsActual, profitAndLoss } from '@/routes/reports';

type Compared = {
    budgetMinor: number;
    actualMinor: number;
    varianceMinor: number;
    favourable: boolean;
};

type Row = Compared & { id: string; code: string; name: string };

type Props = {
    budgets: { id: string; label: string }[];
    budget: {
        id: string;
        name: string;
        year: string;
        costCentre: string | null;
        costCentreId: string | null;
    } | null;
    periods?: { number: number; label: string }[];
    from?: number;
    to?: number;
    fromDate?: string;
    toDate?: string;
    revenue?: Row[];
    expenses?: Row[];
    revenue_total?: Compared;
    expenses_total?: Compared;
    profit?: Compared;
};

/** The variance, green when it helps the result and red when it hurts. */
function Variance({ row }: { row: Compared }) {
    const percent =
        row.budgetMinor === 0
            ? null
            : Math.round((row.varianceMinor / Math.abs(row.budgetMinor)) * 100);

    return (
        <span
            className={cn(
                'tabular-nums',
                row.varianceMinor !== 0 &&
                    (row.favourable ? 'text-emerald-700' : 'text-destructive'),
            )}
        >
            <Amount
                minor={row.varianceMinor}
                blankZero
                className="text-inherit"
            />
            {percent !== null && row.varianceMinor !== 0 && (
                <span className="ml-1 text-xs">({percent}%)</span>
            )}
        </span>
    );
}

/**
 * Budget against actual for a range of months; the actual column is the
 * profit and loss of those months.
 */
export default function BudgetVsActual({
    budgets,
    budget,
    periods = [],
    from,
    to,
    fromDate,
    toDate,
    revenue = [],
    expenses = [],
    revenue_total,
    expenses_total,
    profit,
}: Props) {
    if (budget === null) {
        return (
            <>
                <Head title="Budget vs actual" />
                <PageHeader title="Budget vs actual" />
                <Panel>
                    <EmptyState icon={Target} title="No budgets yet">
                        <Link
                            href={budgetsIndex()}
                            className="text-primary hover:underline"
                        >
                            Add a budget
                        </Link>{' '}
                        to compare it with what happened.
                    </EmptyState>
                </Panel>
            </>
        );
    }

    const rows = (title: string, list: Row[], total?: Compared) => (
        <>
            <TableRow className="hover:bg-transparent">
                <TableCell colSpan={4} className="pt-4 font-semibold">
                    {title}
                </TableCell>
            </TableRow>
            {list.map((row) => (
                <TableRow key={row.id}>
                    <TableCell className="pl-6">
                        <span className="text-muted-foreground tabular-nums">
                            {row.code}
                        </span>{' '}
                        {row.name}
                    </TableCell>
                    <TableCell className="text-right">
                        <Amount minor={row.budgetMinor} blankZero />
                    </TableCell>
                    <TableCell className="text-right">
                        <Amount minor={row.actualMinor} blankZero />
                    </TableCell>
                    <TableCell className="text-right">
                        <Variance row={row} />
                    </TableCell>
                </TableRow>
            ))}
            {total && (
                <TableRow className="hover:bg-transparent">
                    <TableCell className="font-medium">
                        Total {title.toLowerCase()}
                    </TableCell>
                    <TableCell className="text-right font-medium">
                        <Amount minor={total.budgetMinor} />
                    </TableCell>
                    <TableCell className="text-right font-medium">
                        <Amount minor={total.actualMinor} />
                    </TableCell>
                    <TableCell className="text-right font-medium">
                        <Variance row={total} />
                    </TableCell>
                </TableRow>
            )}
        </>
    );

    return (
        <>
            <Head title="Budget vs actual" />
            <PageHeader
                title="Budget vs actual"
                description={`${budget.year} · ${budget.name} · ${budget.costCentre ?? 'Whole organization'} · ${fromDate} to ${toDate}`}
            />
            <Panel>
                <PanelToolbar>
                    <Form
                        {...budgetVsActual.form()}
                        options={{ preserveState: true }}
                        className="flex flex-wrap items-end gap-2 print:hidden"
                    >
                        {({ errors }) => (
                            <>
                                <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                                    Budget
                                    <Select
                                        name="budget"
                                        defaultValue={budget.id}
                                        className="w-64"
                                    >
                                        {budgets.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                </label>
                                <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                                    From
                                    <Select
                                        name="from"
                                        defaultValue={from}
                                        className="w-36"
                                    >
                                        {periods.map((period) => (
                                            <option
                                                key={period.number}
                                                value={period.number}
                                            >
                                                {period.label}
                                            </option>
                                        ))}
                                    </Select>
                                </label>
                                <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                                    To
                                    <Select
                                        name="to"
                                        defaultValue={to}
                                        className="w-36"
                                    >
                                        {periods.map((period) => (
                                            <option
                                                key={period.number}
                                                value={period.number}
                                            >
                                                {period.label}
                                            </option>
                                        ))}
                                    </Select>
                                </label>
                                <Button type="submit" variant="outline">
                                    Show
                                </Button>
                                {errors.from && (
                                    <p className="text-sm text-destructive">
                                        {errors.from}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                    <ReportActions
                        route={budgetVsActual}
                        query={{
                            budget: budget.id,
                            from: String(from),
                            to: String(to),
                        }}
                        exports={[{ key: 'report', title: 'Report' }]}
                    />
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Account</TableHead>
                            <TableHead className="text-right">Budget</TableHead>
                            <TableHead className="text-right">
                                <Link
                                    href={
                                        profitAndLoss({
                                            query: {
                                                from: fromDate,
                                                to: toDate,
                                                cost_centre:
                                                    budget.costCentreId ??
                                                    undefined,
                                            },
                                        }).url
                                    }
                                    className="hover:underline"
                                >
                                    Actual
                                </Link>
                            </TableHead>
                            <TableHead className="text-right">
                                Variance
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {revenue.length === 0 && expenses.length === 0 ? (
                            <TableEmpty colSpan={4}>
                                Nothing budgeted or recorded in these months.
                            </TableEmpty>
                        ) : (
                            <>
                                {rows('Revenue', revenue, revenue_total)}
                                {rows('Expenses', expenses, expenses_total)}
                                {profit && (
                                    <TableRow className="border-t-2 hover:bg-transparent">
                                        <TableCell className="font-semibold">
                                            Profit
                                        </TableCell>
                                        <TableCell className="text-right font-semibold">
                                            <Amount
                                                minor={profit.budgetMinor}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right font-semibold">
                                            <Amount
                                                minor={profit.actualMinor}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right font-semibold">
                                            <Variance row={profit} />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </>
                        )}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
