import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { formatMoney } from '@/lib/money';
import { overview } from '@/routes';
import { update as chooseBranch } from '@/routes/branch';

type Props = {
    filters: {
        from: string;
        to: string;
        branch: string | null;
        status: string | null;
    };
    currency: string;
    branches: { id: string; name: string; city: string | null }[];
    totals: {
        grossMinor: number;
        discountMinor: number;
        salesMinor: number;
        refundedMinor: number;
        netMinor: number;
        previousNetMinor: number;
        cashMinor: number;
        nonCashMinor: number;
        bills: number;
        averageOrderMinor: number;
        orders: {
            total: number;
            completed: number;
            pending: number;
            cancelled: number;
            refunded: number;
        };
    };
    otherCurrencies: Record<string, number>;
    byMethod: {
        method: string;
        name: string;
        count: number;
        totalMinor: number;
    }[];
    trend: { day: string; netMinor: number }[];
    kitchen: {
        preparing: number;
        ready: number;
        slow: number;
        oldestMinutes: number | null;
    };
    performance: {
        id: string;
        name: string;
        city: string | null;
        netMinor: number;
        orders: number;
        averageOrderMinor: number;
        pendingTickets: number;
        openOrders: number;
        lowStock: number;
        status: 'trading' | 'quiet';
    }[];
    alerts: {
        branchId: string;
        branch: string;
        kind: string;
        message: string;
    }[];
    orders: {
        data: {
            id: string;
            number: string;
            branchId: string;
            branch: string;
            where: string;
            status: string;
            openedAt: string;
            openedBy: string;
            paidMinor: number;
        }[];
        total: number;
        currentPage: number;
        lastPage: number;
        prev: string | null;
        next: string | null;
    };
};

const STATUS_NAMES: Record<string, string> = {
    open: 'Open',
    paid: 'Paid',
    cancelled: 'Cancelled',
};

const SELECT =
    'h-9 rounded-md border bg-background px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

function Stat({
    label,
    value,
    note,
}: {
    label: string;
    value: string;
    note?: ReactNode;
}) {
    return (
        <div className="rounded-lg border bg-card p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
            {note && (
                <div className="mt-1 text-xs text-muted-foreground">{note}</div>
            )}
        </div>
    );
}

function Field({
    id,
    label,
    children,
}: {
    id: string;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            {children}
        </div>
    );
}

/**
 * Every branch together: what was sold and how, the kitchens now, what
 * needs attention, and each branch side by side with a way into it.
 */
export default function Overview(props: Props) {
    const money = (minor: number) => formatMoney(minor, props.currency);
    const { totals, kitchen } = props;
    const change =
        totals.previousNetMinor === 0
            ? null
            : Math.round(
                  ((totals.netMinor - totals.previousNetMinor) /
                      Math.abs(totals.previousNetMinor)) *
                      100,
              );
    const peak = Math.max(1, ...props.trend.map((day) => day.netMinor));
    const others = Object.entries(props.otherCurrencies);
    const workIn = (branchId: string) =>
        router.put(chooseBranch.url(), { branch_id: branchId });
    const kitchenNote = [
        `${kitchen.ready} ready to serve`,
        kitchen.slow > 0 ? `${kitchen.slow} waiting too long` : null,
        kitchen.oldestMinutes !== null
            ? `oldest ${kitchen.oldestMinutes} min`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Head title="All branches" />
            <PageHeader
                title="All branches"
                description={
                    props.filters.from === props.filters.to
                        ? new Date(
                              `${props.filters.from}T00:00:00`,
                          ).toLocaleDateString(undefined, { dateStyle: 'full' })
                        : `${props.filters.from} to ${props.filters.to}`
                }
            />

            <Panel className="mb-4">
                <PanelToolbar>
                    <Form
                        {...overview.form()}
                        className="flex flex-wrap items-end gap-2"
                    >
                        <Field id="from" label="From">
                            <Input
                                id="from"
                                name="from"
                                type="date"
                                defaultValue={props.filters.from}
                                className="w-44"
                            />
                        </Field>
                        <Field id="to" label="To">
                            <Input
                                id="to"
                                name="to"
                                type="date"
                                defaultValue={props.filters.to}
                                className="w-44"
                            />
                        </Field>
                        <Field id="branch" label="Branch">
                            <select
                                id="branch"
                                name="branch"
                                defaultValue={props.filters.branch ?? ''}
                                className={`${SELECT} w-48`}
                            >
                                <option value="">All branches</option>
                                {props.branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>
                                        {branch.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field id="status" label="Orders">
                            <select
                                id="status"
                                name="status"
                                defaultValue={props.filters.status ?? ''}
                                className={`${SELECT} w-36`}
                            >
                                <option value="">Any status</option>
                                {Object.entries(STATUS_NAMES).map(
                                    ([value, name]) => (
                                        <option key={value} value={value}>
                                            {name}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>
            </Panel>

            {others.length > 0 && (
                <p className="mb-4 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Not added in below:{' '}
                    {others
                        .map(([code, count]) => `${count} bill(s) in ${code}`)
                        .join(', ')}
                    .
                </p>
            )}

            <div className="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Stat
                    label="Net sales"
                    value={money(totals.netMinor)}
                    note={
                        change === null ? (
                            'Nothing in the period before to compare.'
                        ) : (
                            <span
                                className={`inline-flex items-center gap-1 ${change >= 0 ? 'text-green-700' : 'text-red-700'}`}
                            >
                                {change >= 0 ? (
                                    <ArrowUpRight
                                        className="size-3"
                                        aria-hidden
                                    />
                                ) : (
                                    <ArrowDownRight
                                        className="size-3"
                                        aria-hidden
                                    />
                                )}
                                {Math.abs(change)}% on the period before
                            </span>
                        )
                    }
                />
                <Stat
                    label="Orders"
                    value={String(totals.orders.total)}
                    note={`${totals.orders.completed} paid · ${totals.orders.pending} open · ${totals.orders.cancelled} cancelled · ${totals.orders.refunded} refunded`}
                />
                <Stat
                    label="Average order"
                    value={money(totals.averageOrderMinor)}
                    note={`${totals.bills} bills`}
                />
                <Stat
                    label="Taken in cash"
                    value={money(totals.cashMinor)}
                    note={`${money(totals.nonCashMinor)} by other means`}
                />
                <Stat
                    label="Before discounts"
                    value={money(totals.grossMinor)}
                />
                <Stat
                    label="Discounts given"
                    value={money(totals.discountMinor)}
                />
                <Stat label="Refunds" value={money(totals.refundedMinor)} />
                <Stat
                    label="Kitchens now"
                    value={`${kitchen.preparing} preparing`}
                    note={kitchenNote}
                />
            </div>

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader title="Net sales by day" />
                    <PanelBody>
                        <ul className="space-y-1 text-sm">
                            {props.trend.map((day) => (
                                <li
                                    key={day.day}
                                    className="grid grid-cols-[6rem_1fr_7rem] items-center gap-2"
                                >
                                    <span className="text-muted-foreground tabular-nums">
                                        {day.day}
                                    </span>
                                    <span className="h-3 rounded-sm bg-muted">
                                        <span
                                            className="block h-3 rounded-sm bg-primary"
                                            style={{
                                                width: `${Math.max(0, (day.netMinor / peak) * 100)}%`,
                                            }}
                                        />
                                    </span>
                                    <span className="text-right tabular-nums">
                                        {money(day.netMinor)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader title="Paid by" />
                    <PanelBody>
                        {props.byMethod.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No bills paid.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {props.byMethod.map((row) => (
                                    <li
                                        key={row.method}
                                        className="flex justify-between gap-3 py-1.5"
                                    >
                                        <span>{row.name}</span>
                                        <span className="text-right tabular-nums">
                                            {money(row.totalMinor)}
                                            <span className="block text-xs text-muted-foreground">
                                                {row.count} bills
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            <Panel className="mb-4">
                <PanelHeader title="Branches" />
                <PanelBody className="overflow-x-auto">
                    <table className="w-full min-w-[44rem] text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="py-2 font-medium">Branch</th>
                                <th className="py-2 text-right font-medium">
                                    Net sales
                                </th>
                                <th className="py-2 text-right font-medium">
                                    Orders
                                </th>
                                <th className="py-2 text-right font-medium">
                                    Average
                                </th>
                                <th className="py-2 text-right font-medium">
                                    In kitchen
                                </th>
                                <th className="py-2 text-right font-medium">
                                    Low stock
                                </th>
                                <th className="py-2 pl-4 font-medium">
                                    Status
                                </th>
                                <th className="py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {props.performance.map((row) => (
                                <tr key={row.id}>
                                    <td className="py-2">
                                        <span className="font-medium">
                                            {row.name}
                                        </span>
                                        {row.city && (
                                            <span className="block text-xs text-muted-foreground">
                                                {row.city}
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">
                                        {money(row.netMinor)}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">
                                        {row.orders}
                                        {row.openOrders > 0 && (
                                            <span className="block text-xs text-muted-foreground">
                                                {row.openOrders} open now
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">
                                        {money(row.averageOrderMinor)}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">
                                        {row.pendingTickets}
                                    </td>
                                    <td
                                        className={`py-2 text-right tabular-nums ${row.lowStock > 0 ? 'text-amber-800' : ''}`}
                                    >
                                        {row.lowStock}
                                    </td>
                                    <td className="py-2 pl-4">
                                        {row.status === 'trading'
                                            ? 'Till open'
                                            : 'No till open'}
                                    </td>
                                    <td className="py-2 text-right">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => workIn(row.id)}
                                        >
                                            Open branch
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </PanelBody>
            </Panel>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel>
                    <PanelHeader title="Needs attention" />
                    <PanelBody>
                        {props.alerts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing needs attention.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {props.alerts.map((alert, index) => (
                                    <li key={index} className="py-1.5">
                                        <span className="font-medium">
                                            {alert.branch}
                                        </span>
                                        : {alert.message}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader title={`Orders (${props.orders.total})`} />
                    <PanelBody>
                        {props.orders.data.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No orders in the period.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {props.orders.data.map((order) => (
                                    <li
                                        key={order.id}
                                        className="flex items-start justify-between gap-3 py-1.5"
                                    >
                                        <span className="min-w-0">
                                            <span className="font-medium">
                                                {order.number}
                                            </span>{' '}
                                            · {order.branch} · {order.where}
                                            <span className="block text-xs text-muted-foreground">
                                                {new Date(
                                                    order.openedAt,
                                                ).toLocaleString()}{' '}
                                                · {order.openedBy}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-right tabular-nums">
                                            {money(order.paidMinor)}
                                            <span className="block text-xs text-muted-foreground">
                                                {STATUS_NAMES[order.status] ??
                                                    order.status}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {props.orders.lastPage > 1 && (
                            <div className="mt-3 flex items-center justify-between text-sm">
                                {props.orders.prev ? (
                                    <Link
                                        href={props.orders.prev}
                                        preserveScroll
                                        className="hover:underline"
                                    >
                                        Newer
                                    </Link>
                                ) : (
                                    <span />
                                )}
                                <span className="text-muted-foreground">
                                    Page {props.orders.currentPage} of{' '}
                                    {props.orders.lastPage}
                                </span>
                                {props.orders.next ? (
                                    <Link
                                        href={props.orders.next}
                                        preserveScroll
                                        className="hover:underline"
                                    >
                                        Older
                                    </Link>
                                ) : (
                                    <span />
                                )}
                            </div>
                        )}
                    </PanelBody>
                </Panel>
            </div>
        </>
    );
}
