import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ReceiptText, ShoppingCart } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { statusTone } from '@/lib/status';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
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
            <PageHeader
                title="Sales"
                description={branch?.name}
                actions={
                    canHere('pharmacy.sales.create') ? (
                        <Button asChild>
                            <Link href={create()}>
                                <ShoppingCart className="size-4" aria-hidden />
                                Open the counter
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <Panel>
                <PanelToolbar className="justify-between">
                    <Form
                        {...index.form()}
                        className="flex flex-wrap items-end gap-2"
                    >
                        <div className="grid gap-1.5">
                            <label
                                htmlFor="day"
                                className="text-sm font-medium"
                            >
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
                </PanelToolbar>

                {sales.length === 0 ? (
                    <EmptyState
                        icon={ReceiptText}
                        title="No sales this day"
                        action={
                            canHere('pharmacy.sales.create') ? (
                                <Button asChild>
                                    <Link href={create()}>
                                        Open the counter
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Time</TableHead>
                                <TableHead>Sold by</TableHead>
                                <TableHead>Payment</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sales.map((sale) => (
                                <TableRow key={sale.id}>
                                    <TableCell>
                                        <Link
                                            href={show(sale.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {sale.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {new Date(
                                            sale.soldAt,
                                        ).toLocaleTimeString()}
                                    </TableCell>
                                    <TableCell>{sale.soldBy}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {sale.paymentMethod}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(sale.status)}
                                        >
                                            {sale.status}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell
                                        className={`text-right tabular-nums ${sale.status === 'voided' ? 'line-through' : ''}`}
                                    >
                                        {money(sale.totalMinor)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
