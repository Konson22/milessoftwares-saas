import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCanHere } from '@/lib/access';
import { formatMoney } from '@/lib/money';
import { create, index, show } from '@/routes/sales';

type Sale = {
    id: string;
    number: string;
    soldAt: string;
    soldBy: string;
    totalMinor: number;
    paymentMethod: string;
    status: string;
};

type Props = {
    day: string;
    sales: Sale[];
    takings: { count: number; totalMinor: number };
};

export default function Sales({ day, sales, takings }: Props) {
    const { branch, currency } = usePage().props;
    const canHere = useCanHere();
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title="Sales" />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold">
                    Sales, {branch?.name}
                </h1>
                {canHere('pharmacy.sales.create') && (
                    <Button asChild>
                        <Link href={create()}>Open the counter</Link>
                    </Button>
                )}
            </div>

            <div className="mt-6 flex flex-wrap items-end gap-6">
                <Form {...index.form()} className="flex items-end gap-2">
                    <div className="grid gap-1.5">
                        <label htmlFor="day" className="text-sm font-medium">
                            Day
                        </label>
                        <Input
                            id="day"
                            name="day"
                            type="date"
                            defaultValue={day}
                            className="w-44"
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        Show
                    </Button>
                </Form>
                <p className="text-sm">
                    <span className="text-muted-foreground">Takings: </span>
                    <span className="font-semibold tabular-nums">
                        {money(takings.totalMinor)}
                    </span>
                    <span className="text-muted-foreground">
                        {' '}
                        from {takings.count} sales
                    </span>
                </p>
            </div>

            <ul className="mt-6 divide-y rounded-md border">
                {sales.length === 0 && (
                    <li className="px-4 py-3 text-sm text-muted-foreground">
                        No sales this day.
                    </li>
                )}
                {sales.map((sale) => (
                    <li
                        key={sale.id}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                    >
                        <Link
                            href={show(sale.id)}
                            className="font-medium hover:underline"
                        >
                            {sale.number}
                        </Link>
                        <span className="text-muted-foreground tabular-nums">
                            {new Date(sale.soldAt).toLocaleTimeString()}
                        </span>
                        <span>{sale.soldBy}</span>
                        <span className="text-muted-foreground">
                            {sale.paymentMethod}
                        </span>
                        {sale.status === 'voided' && (
                            <Badge variant="outline">Voided</Badge>
                        )}
                        <span
                            className={`ml-auto tabular-nums ${sale.status === 'voided' ? 'line-through' : ''}`}
                        >
                            {money(sale.totalMinor)}
                        </span>
                    </li>
                ))}
            </ul>
        </>
    );
}
