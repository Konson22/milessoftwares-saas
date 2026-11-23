import { Head, Link } from '@inertiajs/react';
import { PackageCheck } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Status } from '@/components/fields';
import { OrdersTable } from '@/components/orders-table';
import type { OrderRow } from '@/components/orders-table';
import { formatDate } from '@/lib/procurement';
import { show } from '@/routes/orders';

type Props = {
    orders: OrderRow[];
    receipts: {
        id: string;
        number: string;
        orderId: string;
        order: string;
        supplier: string;
        receivedOn: string | null;
        inspection: string;
        by: string | null;
    }[];
    canReceive: boolean;
};

export default function Receiving({ orders, receipts }: Props) {
    return (
        <>
            <Head title="Receiving" />
            <PageHeader title="Receiving" />
            <div className="grid gap-6">
                <Panel>
                    <PanelHeader
                        title="Waiting for delivery"
                        description="Open an order to record what arrived."
                    />
                    {orders.length === 0 ? (
                        <EmptyState
                            icon={PackageCheck}
                            title="Nothing is on its way"
                        />
                    ) : (
                        <OrdersTable orders={orders} />
                    )}
                </Panel>
                <Panel>
                    <PanelHeader title="Latest receipts" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Receipt</TableHead>
                                <TableHead>Order</TableHead>
                                <TableHead>Received</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead>Inspection</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {receipts.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground"
                                    >
                                        No receipts yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {receipts.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell>{r.number}</TableCell>
                                    <TableCell>
                                        <Link
                                            href={show(r.orderId)}
                                            className="hover:underline"
                                        >
                                            {r.order}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {r.supplier}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(r.receivedOn)}
                                    </TableCell>
                                    <TableCell>{r.by ?? '—'}</TableCell>
                                    <TableCell>
                                        <Status value={r.inspection} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            </div>
        </>
    );
}
