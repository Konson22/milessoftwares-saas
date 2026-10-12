import { Form, Head, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
            <h1 className="text-2xl font-semibold">Reports, {branch?.name}</h1>

            <Form
                {...index.form()}
                className="mt-6 flex flex-wrap items-end gap-2"
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
                            <label htmlFor="to" className="text-sm font-medium">
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

            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Takings</CardTitle>
                        <CardDescription>
                            {summary.count} sales, average{' '}
                            {money(summary.averageMinor)}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="text-2xl font-semibold tabular-nums">
                        {money(summary.revenueMinor)}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Gross profit</CardTitle>
                        <CardDescription>
                            Cost of stock sold {money(summary.costMinor)}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="text-2xl font-semibold tabular-nums">
                        {money(summary.profitMinor)}{' '}
                        <span className="text-base font-normal text-muted-foreground">
                            {margin(summary.revenueMinor, summary.costMinor)}
                        </span>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Voided</CardTitle>
                        <CardDescription>
                            {summary.voidedCount} sales, not in takings
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="text-2xl font-semibold tabular-nums">
                        {money(summary.voidedMinor)}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Stock today</CardTitle>
                        <CardDescription>
                            {stock.units} units, {money(stock.retailMinor)} at
                            today&apos;s prices
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="text-2xl font-semibold tabular-nums">
                        {money(stock.costMinor)}{' '}
                        <span className="text-base font-normal text-muted-foreground">
                            at cost
                        </span>
                        {stock.expiredUnits > 0 && (
                            <p className="mt-1 text-sm font-normal text-destructive">
                                Expired on the shelves: {stock.expiredUnits}{' '}
                                units, {money(stock.expiredCostMinor)}
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="mt-8 grid gap-8 lg:grid-cols-2">
                <section>
                    <h2 className="font-semibold">Best sellers</h2>
                    <div className="mt-2 overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Medicine
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Sold
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Takings
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Margin
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {topMedicines.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-3 py-6 text-center text-muted-foreground"
                                        >
                                            Nothing sold in this period.
                                        </td>
                                    </tr>
                                )}
                                {topMedicines.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-3 py-2">
                                            {row.medicine}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {row.quantity}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {money(row.revenueMinor)}
                                        </td>
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {margin(
                                                row.revenueMinor,
                                                row.costMinor,
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <h2 className="mt-8 font-semibold">How customers paid</h2>
                    <ul className="mt-2 divide-y rounded-md border text-sm">
                        {byMethod.length === 0 && (
                            <li className="px-3 py-3 text-muted-foreground">
                                No payments in this period.
                            </li>
                        )}
                        {byMethod.map((row) => (
                            <li
                                key={row.method}
                                className="flex justify-between gap-4 px-3 py-2"
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
                </section>

                <section>
                    <h2 className="font-semibold">Takings by day</h2>
                    <ul className="mt-2 divide-y rounded-md border text-sm">
                        {byDay.length === 0 && (
                            <li className="px-3 py-3 text-muted-foreground">
                                No sales in this period.
                            </li>
                        )}
                        {byDay.map((row) => (
                            <li
                                key={row.day}
                                className="grid grid-cols-[6.5rem_1fr_auto] items-center gap-3 px-3 py-2"
                            >
                                <span className="tabular-nums">{row.day}</span>
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
                </section>
            </div>
        </>
    );
}
