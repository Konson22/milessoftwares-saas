import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
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
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { index, show } from '@/routes/orders';

type Props = {
    day: string;
    orders: {
        id: string;
        number: string;
        service: 'dine_in' | 'takeaway';
        table: string | null;
        customerName: string | null;
        status: 'open' | 'paid' | 'cancelled';
        openedAt: string;
        openedBy: string;
        bill: {
            id: string;
            number: string;
            totalMinor: number;
            status: string;
        } | null;
    }[];
};

/**
 * The branch's orders of one day: open, paid and cancelled.
 */
export default function Orders({ day, orders }: Props) {
    const { currency, branch } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title="Orders" />
            <PageHeader title="Orders" description={branch?.name} />

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} className="flex items-center gap-2">
                        <Input
                            type="date"
                            name="day"
                            defaultValue={day}
                            aria-label="Day"
                            className="h-9 w-44"
                        />
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>

                {orders.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title="No orders this day"
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Order</TableHead>
                                <TableHead>Where</TableHead>
                                <TableHead>Opened</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Bill
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.map((order) => (
                                <TableRow key={order.id}>
                                    <TableCell>
                                        <Link
                                            href={show(order.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {order.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {order.table ??
                                            `Takeaway${order.customerName ? ` · ${order.customerName}` : ''}`}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {new Date(
                                            order.openedAt,
                                        ).toLocaleTimeString([], {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })}
                                    </TableCell>
                                    <TableCell>{order.openedBy}</TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(order.status)}
                                        >
                                            {order.status}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {order.bill
                                            ? `${money(order.bill.totalMinor)} · ${order.bill.number}${order.bill.status === 'voided' ? ' (voided)' : ''}`
                                            : '–'}
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
