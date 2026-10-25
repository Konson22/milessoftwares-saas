import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Armchair, BellRing, ShoppingBag, Users } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { useCanHere } from '@/lib/access';
import { formatMoney } from '@/lib/money';
import { show, store } from '@/routes/orders';
import { index as tablesIndex } from '@/routes/tables';

type Card = {
    id: string;
    number: string;
    guests: number | null;
    customerName: string | null;
    openedAt: string;
    openedBy: string;
    totalMinor: number;
    /** The kitchen has a ticket of it ready to take out. */
    hasReady: boolean;
};

type Props = {
    tables: {
        id: string;
        name: string;
        area: string | null;
        seats: number;
        order: Card | null;
    }[];
    takeaways: Card[];
};

function since(iso: string): string {
    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
}

/**
 * The branch's tables, free or being served, and the takeaways still
 * open: a free table opens an order, a busy one goes to its order.
 */
export default function Floor({ tables, takeaways }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const canHere = useCanHere();
    const areas = [...new Set(tables.map((table) => table.area ?? ''))];

    return (
        <>
            <Head title="Floor" />
            <PageHeader
                title="Floor"
                description="Tap a free table to seat guests, a busy one to add to its order."
                actions={
                    <Form
                        {...store.form()}
                        className="flex flex-wrap items-center gap-2"
                    >
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="service"
                                    value="takeaway"
                                />
                                <Input
                                    name="customer_name"
                                    placeholder="Name for the takeaway"
                                    aria-label="Name for the takeaway"
                                    className="h-9 w-48"
                                />
                                <Button type="submit" disabled={processing}>
                                    <ShoppingBag
                                        className="size-4"
                                        aria-hidden
                                    />
                                    New takeaway
                                </Button>
                            </>
                        )}
                    </Form>
                }
            />

            {tables.length === 0 && (
                <EmptyState
                    icon={Armchair}
                    title="No tables at this branch"
                    action={
                        canHere('restaurant.tables.manage') ? (
                            <Button asChild>
                                <Link href={tablesIndex()}>Set up tables</Link>
                            </Button>
                        ) : undefined
                    }
                />
            )}

            {areas.map((area) => (
                <section key={area || 'tables'} className="mb-6">
                    {area && (
                        <h2 className="mb-2 text-sm font-medium text-muted-foreground">
                            {area}
                        </h2>
                    )}
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {tables
                            .filter((table) => (table.area ?? '') === area)
                            .map((table) =>
                                table.order ? (
                                    <Link
                                        key={table.id}
                                        href={show(table.order.id)}
                                        className="rounded-lg border border-primary/40 bg-primary/5 p-3 transition hover:bg-primary/10"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-lg font-semibold">
                                                {table.name}
                                            </span>
                                            {table.order.hasReady && (
                                                <StatusBadge tone="success">
                                                    <BellRing
                                                        className="size-3"
                                                        aria-hidden
                                                    />{' '}
                                                    Ready
                                                </StatusBadge>
                                            )}
                                        </div>
                                        <p className="mt-1 text-sm tabular-nums">
                                            {money(table.order.totalMinor)}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {table.order.number} ·{' '}
                                            {since(table.order.openedAt)} ·{' '}
                                            {table.order.openedBy}
                                        </p>
                                    </Link>
                                ) : (
                                    <Form
                                        key={table.id}
                                        {...store.form()}
                                        className="rounded-lg border border-dashed p-3"
                                    >
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="service"
                                                    value="dine_in"
                                                />
                                                <input
                                                    type="hidden"
                                                    name="dining_table_id"
                                                    value={table.id}
                                                />
                                                <div className="flex items-center justify-between">
                                                    <span className="text-lg font-semibold">
                                                        {table.name}
                                                    </span>
                                                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                        <Users
                                                            className="size-3"
                                                            aria-hidden
                                                        />
                                                        {table.seats}
                                                    </span>
                                                </div>
                                                <div className="mt-2 flex items-center gap-2">
                                                    <Input
                                                        name="guests"
                                                        type="number"
                                                        min={1}
                                                        max={100}
                                                        placeholder="Guests"
                                                        aria-label={`Guests at ${table.name}`}
                                                        className="h-8 w-20"
                                                    />
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                    >
                                                        Seat
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                ),
                            )}
                    </div>
                </section>
            ))}

            {takeaways.length > 0 && (
                <Panel>
                    <PanelHeader title="Takeaways" />
                    <PanelBody className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {takeaways.map((order) => (
                            <Link
                                key={order.id}
                                href={show(order.id)}
                                className="rounded-lg border p-3 transition hover:bg-muted"
                            >
                                <div className="flex items-center justify-between">
                                    <span className="font-semibold">
                                        {order.customerName ?? order.number}
                                    </span>
                                    {order.hasReady && (
                                        <StatusBadge tone="success">
                                            Ready
                                        </StatusBadge>
                                    )}
                                </div>
                                <p className="mt-1 text-sm tabular-nums">
                                    {money(order.totalMinor)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {order.number} · {since(order.openedAt)}
                                </p>
                            </Link>
                        ))}
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
