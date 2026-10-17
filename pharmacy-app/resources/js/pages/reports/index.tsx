import { Form, Head, usePage } from '@inertiajs/react';
import { Banknote, Package, TrendingUp, XCircle } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { index } from '@/routes/reports';

type Props = {
    from: string;
    to: string;
    summary: {
        count: number;
        revenueMinor: number;
        costMinor: number;
        profitMinor: number;
        averageMinor: number;
        voidedCount: number;
        voidedMinor: number;
        returnedCount: number;
        returnedMinor: number;
    };
    byMethod: { method: string; count: number; totalMinor: number }[];
    byDay: { day: string; count: number; totalMinor: number }[];
    topMedicines: {
        id: string;
        medicine: string;
        quantity: number;
        revenueMinor: number;
        costMinor: number;
    }[];
    stock: {
        units: number;
        costMinor: number;
        retailMinor: number;
        expiredUnits: number;
        expiredCostMinor: number;
        heldUnits: number;
        heldCostMinor: number;
    };
};

function margin(revenue: number, cost: number): string {
    return revenue === 0
        ? '–'
        : `${Math.round(((revenue - cost) / revenue) * 100)}%`;
}

/**
 * The branch's figures for a period, and its stock's worth today.
 */
export default function Reports({
    from,
    to,
    summary,
    byMethod,
    byDay,
    topMedicines,
    stock,
}: Props) {
    const { branch, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const busiest = Math.max(0, ...byDay.map((day) => day.totalMinor));

    return (
        <>
            <Head title="Reports" />
            <PageHeader title="Reports" description={branch?.name} />

            <Panel className="mb-5">
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        className="flex flex-wrap items-end gap-2"
                    >
                        {({ errors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <label
                                        htmlFor="from"
                                        className="text-sm font-medium"
                                    >
                                        From
                                    </label>
                                    <Input
                                        id="from"
                                        name="from"
                                        type="date"
                                        defaultValue={from}
                                        className="w-44"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <label
                                        htmlFor="to"
                                        className="text-sm font-medium"
                                    >
                                        To
                                    </label>
                                    <Input
                                        id="to"
                                        name="to"
                                        type="date"
                                        defaultValue={to}
                                        className="w-44"
                                    />
                                </div>
                                <Button type="submit" variant="outline">
                                    Show
                                </Button>
                                {(errors.from || errors.to) && (
                                    <p className="w-full text-sm text-destructive">
                                        {errors.from ?? errors.to}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </PanelToolbar>
            </Panel>

            <div className="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Takings"
                    value={money(summary.revenueMinor)}
                    hint={`${summary.count} sales, average ${money(summary.averageMinor)}`}
                    icon={Banknote}
                    accent="blue"
                />
                <StatCard
                    label="Gross profit"
                    value={
                        <>
                            {money(summary.profitMinor)}{' '}
                            <span className="text-base font-normal text-muted-foreground">
                                {margin(
                                    summary.revenueMinor,
                                    summary.costMinor,
                                )}
                            </span>
                        </>
                    }
                    hint={`Cost of stock sold ${money(summary.costMinor)}`}
                    icon={TrendingUp}
                    accent="emerald"
                />
                <StatCard
                    label="Voided and returned"
                    value={money(summary.voidedMinor + summary.returnedMinor)}
                    hint={`${summary.voidedCount} sales, not in takings. Returns: ${money(summary.returnedMinor)} (${summary.returnedCount}), taken off`}
                    icon={XCircle}
                    accent="red"
                />
                <StatCard
                    label="Stock today"
                    value={
                        <>
                            {money(stock.costMinor)}{' '}
                            <span className="text-base font-normal text-muted-foreground">
                                at cost
                            </span>
                        </>
                    }
                    hint={
                        <>
                            {stock.units} units, {money(stock.retailMinor)} at
                            today&apos;s prices
                            {stock.expiredUnits > 0 && (
                                <span className="mt-1 block text-destructive">
                                    Expired: {stock.expiredUnits} units,{' '}
                                    {money(stock.expiredCostMinor)}
                                </span>
                            )}
                            {stock.heldUnits > 0 && (
                                <span className="mt-1 block text-amber-700 dark:text-amber-400">
                                    On hold: {stock.heldUnits} units,{' '}
                                    {money(stock.heldCostMinor)}
                                </span>
                            )}
                        </>
                    }
                    icon={Package}
                    accent="slate"
                />
            </div>

            <div className="grid gap-5 lg:grid-cols-2">
                <div className="space-y-5">
                    <Panel>
                        <PanelHeader title="Best sellers" />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Medicine</TableHead>
                                    <TableHead className="text-right">
                                        Sold
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Takings
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Margin
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {topMedicines.length === 0 ? (
                                    <TableEmpty colSpan={4}>
                                        Nothing sold in this period.
                                    </TableEmpty>
                                ) : (
                                    topMedicines.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                {row.medicine}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {row.quantity}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {money(row.revenueMinor)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {margin(
                                                    row.revenueMinor,
                                                    row.costMinor,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </Panel>

                    <Panel>
                        <PanelHeader title="How customers paid" />
                        {byMethod.length === 0 ? (
                            <PanelBody>
                                <p className="text-sm text-muted-foreground">
                                    No payments in this period.
                                </p>
                            </PanelBody>
                        ) : (
                            <ul className="divide-y text-sm">
                                {byMethod.map((row) => (
                                    <li
                                        key={row.method}
                                        className="flex justify-between gap-4 px-4 py-2.5"
                                    >
                                        <span>
                                            {row.method}
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {row.count} sales
                                            </span>
                                        </span>
                                        <span className="tabular-nums">
                                            {money(row.totalMinor)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <Panel>
                    <PanelHeader title="Takings by day" />
                    {byDay.length === 0 ? (
                        <PanelBody>
                            <p className="text-sm text-muted-foreground">
                                No sales in this period.
                            </p>
                        </PanelBody>
                    ) : (
                        <ul className="divide-y text-sm">
                            {byDay.map((row) => (
                                <li
                                    key={row.day}
                                    className="grid grid-cols-[6.5rem_1fr_auto] items-center gap-3 px-4 py-2.5"
                                >
                                    <span className="tabular-nums">
                                        {row.day}
                                    </span>
                                    <span
                                        className="h-2 rounded-full bg-primary/70"
                                        style={{
                                            width: `${busiest === 0 ? 0 : Math.max(2, (row.totalMinor / busiest) * 100)}%`,
                                        }}
                                        aria-hidden
                                    />
                                    <span className="tabular-nums">
                                        {money(row.totalMinor)}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {row.count}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
