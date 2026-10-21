import { Form, Head, Link, usePage, usePoll } from '@inertiajs/react';
import { ClipboardList, ShoppingCart } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader, PanelToolbar } from '@miles/ui/components/panel';
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
import { index, show } from '@/routes/orders';
import { create as counter } from '@/routes/sales';

type Order = {
    id: string;
    number: string;
    status: string;
    customerName: string | null;
    totalMinor: number;
    orderedAt: string;
    orderedBy: string;
    /** The step it waits for, or null when finished. */
    awaits: 'payment' | 'dispensing' | 'collection' | null;
};

type Props = { orders: Order[]; workflow: string };

const STATUS_LABELS: Record<string, string> = {
    open: 'Open',
    paid: 'Paid',
    handed_over: 'Dispensed',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

/**
 * The branch's order queue, refreshed as other counters work: orders to
 * pay at the cashier, orders to dispense or collect, and today's finished
 * ones. Scanning an order slip's barcode opens the order.
 */
export default function Orders({ orders, workflow }: Props) {
    const { branch, currency } = usePage().props;
    const canHere = useCanHere();
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    usePoll(10_000, { only: ['orders'] });

    const toPay = orders.filter((order) => order.awaits === 'payment');
    const toHandOver = orders.filter(
        (order) =>
            order.awaits === 'dispensing' || order.awaits === 'collection',
    );
    const done = orders.filter((order) => order.awaits === null);

    const table = (rows: Order[], empty: string) =>
        rows.length === 0 ? (
            <p className="px-3 py-4 text-sm text-muted-foreground">{empty}</p>
        ) : (
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Order</TableHead>
                        <TableHead>Customer</TableHead>
                        <TableHead>Taken</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">Total</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((order) => (
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
                                {order.customerName ?? (
                                    <span className="text-muted-foreground">
                                        Walk-in Customer
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="text-muted-foreground tabular-nums">
                                {new Date(order.orderedAt).toLocaleTimeString()}{' '}
                                · {order.orderedBy}
                            </TableCell>
                            <TableCell>
                                <StatusBadge tone={statusTone(order.status)}>
                                    {STATUS_LABELS[order.status] ??
                                        order.status}
                                </StatusBadge>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {money(order.totalMinor)}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        );

    return (
        <>
            <Head title="Order queue" />
            <PageHeader
                title="Order queue"
                description={`${branch?.name ?? ''} · ${workflow}`}
                actions={
                    canHere('pharmacy.sales.create') ? (
                        <Button asChild>
                            <Link href={counter()}>
                                <ShoppingCart className="size-4" aria-hidden />
                                Take an order
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <Panel className="mb-3">
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        className="flex flex-wrap items-end gap-2"
                    >
                        {({ errors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <label
                                        htmlFor="find"
                                        className="text-sm font-medium"
                                    >
                                        Order number
                                    </label>
                                    <Input
                                        id="find"
                                        name="find"
                                        placeholder="Scan or type, e.g. O-000012"
                                        autoComplete="off"
                                        autoFocus
                                        className="w-64"
                                        aria-invalid={
                                            errors.find ? true : undefined
                                        }
                                    />
                                </div>
                                <Button type="submit" variant="outline">
                                    Open
                                </Button>
                                {errors.find && (
                                    <p className="w-full text-sm text-destructive">
                                        {errors.find}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </PanelToolbar>
            </Panel>

            {orders.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={ClipboardList}
                        title="No orders yet today"
                    />
                </Panel>
            ) : (
                <div className="grid gap-3 xl:grid-cols-2">
                    <Panel>
                        <PanelHeader
                            title={`To pay (${toPay.length})`}
                            description="At the cashier"
                        />
                        {table(toPay, 'Nothing waiting for payment.')}
                    </Panel>
                    <Panel>
                        <PanelHeader
                            title={`To hand over (${toHandOver.length})`}
                            description="Dispensing or collection"
                        />
                        {table(
                            toHandOver,
                            'Nothing waiting to be handed over.',
                        )}
                    </Panel>
                    {done.length > 0 && (
                        <Panel className="xl:col-span-2">
                            <PanelHeader title="Finished today" />
                            {table(done, '')}
                        </Panel>
                    )}
                </div>
            )}
        </>
    );
}
