import { Head, Link, usePage } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { statusTone } from '@/lib/status';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { ORDER_STATUS } from '@/lib/purchasing';
import { create, show } from '@/routes/purchase-orders';
import { index as receipts } from '@/routes/receipts';

type Order = {
    id: string;
    number: string;
    supplier: string;
    status: string;
    expectedOn: string | null;
    totalMinor: number;
    createdAt: string;
};

export default function PurchaseOrders({
    orders,
    canManage,
}: {
    orders: Order[];
    canManage: boolean;
}) {
    const { branch, currency } = usePage().props;

    return (
        <>
            <Head title="Purchase orders" />
            <PageHeader
                title="Purchase orders"
                description={branch?.name}
                actions={
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={receipts()}>Goods received</Link>
                        </Button>
                        {canManage && (
                            <Button asChild>
                                <Link href={create()}>New order</Link>
                            </Button>
                        )}
                    </div>
                }
            />

            <Panel>
                {orders.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title="No purchase orders yet"
                        action={
                            canManage ? (
                                <Button asChild>
                                    <Link href={create()}>New order</Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Supplier</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Expected</TableHead>
                                <TableHead className="text-right">
                                    Total
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
                                    <TableCell>{order.supplier}</TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={statusTone(order.status)}
                                        >
                                            {ORDER_STATUS[order.status] ??
                                                order.status}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {order.expectedOn ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            order.totalMinor,
                                            currency ?? 'USD',
                                        )}
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
