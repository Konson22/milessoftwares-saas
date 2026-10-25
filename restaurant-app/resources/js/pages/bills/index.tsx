import { Form, Head, Link, usePage } from '@inertiajs/react';
import { LayoutGrid, ReceiptText } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { statusTone } from '@/lib/status';
import { floor } from '@/routes';
import { index, show } from '@/routes/bills';

type Bill = {
    id: string;
    number: string;
    paidAt: string;
    paidBy: string;
    table: string | null;
    customerName: string | null;
    totalMinor: number;
    paymentMethod: string;
    status: string;
};

type Props = {
    day: string;
    bills: Bill[];
    /** Bills still paid (voided ones are refunded). */
    takings: { count: number; totalMinor: number };
};

/**
 * The bills paid at the branch on one day.
 */
export default function Bills({ day, bills, takings }: Props) {
    const { branch, currency } = usePage().props;
    const canHere = useCanHere();
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const toFloor = canHere('restaurant.orders.take') ? (
        <Button asChild>
            <Link href={floor()}>
                <LayoutGrid className="size-4" aria-hidden />
                Go to the floor
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Bills paid" />
            <PageHeader
                title="Bills paid"
                description={branch?.name}
                actions={toFloor}
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
                            from {takings.count} bills
                        </span>
                    </p>
                </PanelToolbar>

                {bills.length === 0 ? (
                    <EmptyState
                        icon={ReceiptText}
                        title="No bills paid this day"
                        action={toFloor}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Bill</TableHead>
                                <TableHead>Time</TableHead>
                                <TableHead>Where</TableHead>
                                <TableHead>Cashier</TableHead>
                                <TableHead>Payment</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bills.map((bill) => (
                                <TableRow key={bill.id}>
                                    <TableCell>
                                        <Link
                                            href={show(bill.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {bill.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {new Date(
                                            bill.paidAt,
                                        ).toLocaleTimeString([], {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </TableCell>
                                    <TableCell>
                                        {bill.table ??
                                            `Takeaway${bill.customerName ? ` · ${bill.customerName}` : ''}`}
                                    </TableCell>
                                    <TableCell>{bill.paidBy}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {bill.paymentMethod}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(bill.status)}
                                        >
                                            {bill.status}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell
                                        className={`text-right tabular-nums ${bill.status === 'voided' ? 'line-through' : ''}`}
                                    >
                                        {money(bill.totalMinor)}
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
