import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { statusTone } from '@/lib/status';
import { create, index, show } from '@/routes/retail/orders';

type Props = {
    orders: {
        id: string;
        reference: string;
        quoteNumber: string;
        customer: string;
        customerReference: string | null;
        status: string;
        statusLabel: string;
        expired: boolean;
        validUntil: string;
        totalMinor: number;
        createdAt: string;
    }[];
    filters: { status: string };
    statuses: { value: string; label: string }[];
    canManage: boolean;
};

/**
 * Quotations and sales orders of this branch.
 */
export default function Orders({
    orders,
    filters,
    statuses,
    canManage,
}: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const newQuote = canManage ? (
        <Button asChild>
            <Link href={create()}>New quotation</Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Orders" />
            <PageHeader
                title="Orders"
                description="Quotations, sales orders and what was delivered on them."
                actions={newQuote}
            />

            <Panel>
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        options={{ preserveState: true }}
                        className="flex flex-wrap items-center gap-3"
                    >
                        <select
                            name="status"
                            defaultValue={filters.status}
                            aria-label="Status"
                            className="h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option value="">All</option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>

                {orders.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title="No orders"
                        action={newQuote}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Order</TableHead>
                                <TableHead>Customer</TableHead>
                                <TableHead>Status</TableHead>
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
                                            {order.reference}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {new Date(
                                                order.createdAt,
                                            ).toLocaleDateString()}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {order.customer}
                                        {order.customerReference && (
                                            <span className="block text-xs text-muted-foreground">
                                                Their ref.{' '}
                                                {order.customerReference}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={
                                                order.expired
                                                    ? 'danger'
                                                    : statusTone(order.status)
                                            }
                                        >
                                            {order.expired
                                                ? `Expired ${order.validUntil}`
                                                : order.statusLabel}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(order.totalMinor)}
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
