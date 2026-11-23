import { Link } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Status } from '@/components/fields';
import { formatDate, money } from '@/lib/procurement';
import { show } from '@/routes/orders';

export type OrderRow = {
    id: string;
    number: string | null;
    status: string;
    method: string;
    supplier: string;
    supplierId: string;
    currency: string;
    total: string;
    totalBase: string | null;
    deliveryDate: string | null;
    late: boolean;
    date: string | null;
};

export function OrdersTable({ orders }: { orders: OrderRow[] }) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Number</TableHead>
                    <TableHead>Supplier</TableHead>
                    <TableHead>Delivery</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                    <TableHead>Status</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {orders.map((o) => (
                    <TableRow key={o.id}>
                        <TableCell className="whitespace-nowrap">
                            <Link
                                href={show(o.id)}
                                className="font-medium hover:underline"
                            >
                                {o.number ?? 'Draft'}
                            </Link>
                        </TableCell>
                        <TableCell>{o.supplier}</TableCell>
                        <TableCell>
                            {formatDate(o.deliveryDate)}
                            {o.late && (
                                <span className="ml-2 text-xs text-destructive">
                                    late
                                </span>
                            )}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {money(o.total, o.currency)}
                        </TableCell>
                        <TableCell>
                            <Status value={o.status} />
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
