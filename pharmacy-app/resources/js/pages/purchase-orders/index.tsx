import { Head, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold">
                    Purchase orders, {branch?.name}
                </h1>
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
            </div>

            <ul className="mt-6 divide-y rounded-md border">
                {orders.length === 0 && (
                    <li className="px-4 py-3 text-sm text-muted-foreground">
                        No purchase orders yet.
                    </li>
                )}
                {orders.map((order) => (
                    <li
                        key={order.id}
                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                    >
                        <Link
                            href={show(order.id)}
                            className="font-medium hover:underline"
                        >
                            {order.number}
                        </Link>
                        <span>{order.supplier}</span>
                        <Badge variant="secondary">
                            {ORDER_STATUS[order.status] ?? order.status}
                        </Badge>
                        {order.expectedOn && (
                            <span className="text-muted-foreground">
                                expected {order.expectedOn}
                            </span>
                        )}
                        <span className="ml-auto tabular-nums">
                            {formatMoney(order.totalMinor, currency ?? 'USD')}
                        </span>
                    </li>
                ))}
            </ul>
        </>
    );
}
