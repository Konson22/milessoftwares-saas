import { Form, Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownRight,
    ArrowUpRight,
    Banknote,
    ChefHat,
    FileSpreadsheet,
    Package,
    Percent,
    Receipt,
    RotateCcw,
    ShoppingBag,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { MoneyBars, Ring, usePalette } from '@/components/charts';
import { OpenBranch } from '@/components/open-branch';
import { currencyDecimals, formatMoney } from '@/lib/money';
import { overview } from '@/routes';

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
    hourly: { hour: number; netMinor: number; bills: number }[];
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

const STATUS_TONES: Record<string, string> = {
    open: 'bg-blue-50 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    paid: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    cancelled:
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
};

const ALERT_KINDS: Record<
    string,
    { name: string; icon: LucideIcon; tone: string }
> = {
    till: {
        name: 'Till',
        icon: Wallet,
        tone: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    },
    kitchen: {
        name: 'Kitchen',
        icon: ChefHat,
        tone: 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200',
    },
    handover: {
        name: 'Cash',
        icon: Banknote,
        tone: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
    },
    stock: {
        name: 'Stock',
        icon: Package,
        tone: 'bg-blue-50 text-blue-800 dark:bg-blue-950 dark:text-blue-200',
    },
    count: {
        name: 'Count',
        icon: AlertTriangle,
        tone: 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200',
    },
};

const ICON_TONES = {
    blue: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    emerald:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    red: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
    slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
};

const SELECT =
    'h-9 border bg-background px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/** A date as yyyy-mm-dd in the browser's own calendar. */
function isoDay(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function quickRanges(): { name: string; from: string; to: string }[] {
    const today = new Date();
    const daysAgo = (n: number) =>
        new Date(today.getFullYear(), today.getMonth(), today.getDate() - n);

    return [
        { name: 'Today', from: isoDay(today), to: isoDay(today) },
        { name: 'Yesterday', from: isoDay(daysAgo(1)), to: isoDay(daysAgo(1)) },
        { name: 'Last 7 days', from: isoDay(daysAgo(6)), to: isoDay(today) },
        { name: 'Last 30 days', from: isoDay(daysAgo(29)), to: isoDay(today) },
        {
            name: 'This month',
            from: isoDay(new Date(today.getFullYear(), today.getMonth(), 1)),
            to: isoDay(today),
        },
    ];
}

function describePeriod(from: string, to: string): string {
    const day = (value: string) => new Date(`${value}T00:00:00`);
    if (from === to) {
        return day(from).toLocaleDateString(undefined, { dateStyle: 'full' });
    }

    return `${day(from).toLocaleDateString(undefined, { dateStyle: 'medium' })} – ${day(to).toLocaleDateString(undefined, { dateStyle: 'medium' })}`;
}

function Kpi({
    label,
    value,
    icon: Icon,
    tone,
    children,
}: {
    label: string;
    value: string;
    icon: LucideIcon;
    tone: keyof typeof ICON_TONES;
    children?: ReactNode;
}) {
    return (
        <div className="border bg-card p-4">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm text-muted-foreground">{label}</p>
                <span
                    className={`grid size-8 shrink-0 place-items-center ${ICON_TONES[tone]}`}
                >
                    <Icon className="size-4" aria-hidden />
                </span>
            </div>
            <p className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            {children && (
                <div className="mt-2 text-xs text-muted-foreground">
                    {children}
                </div>
            )}
        </div>
    );
}

function Figure({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: string;
    icon: LucideIcon;
}) {
    return (
        <div className="flex items-center gap-3 border bg-card px-4 py-3">
            <Icon
                className="size-4 shrink-0 text-muted-foreground"
                aria-hidden
            />
            <div className="min-w-0">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="font-semibold tabular-nums">{value}</p>
            </div>
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
        <div className="grid gap-1">
            <label
                htmlFor={id}
                className="text-xs font-medium text-muted-foreground"
            >
                {label}
            </label>
            {children}
        </div>
    );
}

function LegendRow({
    color,
    name,
    value,
    note,
}: {
    color: string;
    name: string;
    value: string;
    note?: string;
}) {
    return (
        <li className="flex items-center justify-between gap-3 py-1.5 text-sm">
            <span className="flex min-w-0 items-center gap-2">
                <span
                    className="size-2.5 shrink-0"
                    style={{ backgroundColor: color }}
                    aria-hidden
                />
                <span className="truncate">{name}</span>
            </span>
            <span className="shrink-0 text-right tabular-nums">
                {value}
                {note && (
                    <span className="ml-1 text-xs text-muted-foreground">
                        {note}
                    </span>
                )}
            </span>
        </li>
    );
}

/**
 * Every branch together: what was sold and how, when, where, the kitchens
 * now, what needs attention, and each branch side by side with a way in.
 */
export default function Overview(props: Props) {
    const { totals, kitchen, filters, currency } = props;
    const palette = usePalette();
    const money = (minor: number) => formatMoney(minor, currency);
    const decimals = currencyDecimals(currency);
    const major = (minor: number) => minor / 10 ** decimals;
    const short = (minor: number) =>
        new Intl.NumberFormat(undefined, {
            notation: 'compact',
            maximumFractionDigits: 1,
        }).format(major(minor));

    const singleDay = filters.from === filters.to;
    const [view, setView] = useState<'day' | 'hour'>(
        singleDay ? 'hour' : 'day',
    );
    const change =
        totals.previousNetMinor === 0
            ? null
            : Math.round(
                  ((totals.netMinor - totals.previousNetMinor) /
                      Math.abs(totals.previousNetMinor)) *
                      100,
              );
    const others = Object.entries(props.otherCurrencies);

    // The hours that traded, padded to a sensible day (07:00 to 22:00).
    const traded = props.hourly.filter(
        (hour) => hour.bills > 0 || hour.netMinor !== 0,
    );
    const firstHour = Math.min(7, ...traded.map((hour) => hour.hour));
    const lastHour = Math.max(22, ...traded.map((hour) => hour.hour));
    const hours = props.hourly.filter(
        (hour) => hour.hour >= firstHour && hour.hour <= lastHour,
    );
    const busiest = traded.reduce<(typeof traded)[number] | null>(
        (best, hour) =>
            best === null || hour.netMinor > best.netMinor ? hour : best,
        null,
    );

    const exportQuery = {
        from: filters.from,
        to: filters.to,
        ...(filters.branch ? { branch: filters.branch } : {}),
        ...(filters.status ? { status: filters.status } : {}),
        export: 'xlsx',
    };
    const ranges = quickRanges();
    const branchByNet = [...props.performance].sort(
        (a, b) => b.netMinor - a.netMinor,
    );
    const orderParts = [
        { name: 'Paid', value: totals.orders.completed, color: '#16a34a' },
        {
            name: 'Open',
            value: totals.orders.pending,
            color: palette.series[0],
        },
        {
            name: 'Cancelled',
            value: totals.orders.cancelled,
            color: palette.series[4],
        },
    ];

    return (
        <>
            <Head title="All branches" />
            <PageHeader
                title="All branches"
                badges={
                    <span className="text-sm text-muted-foreground">
                        {describePeriod(filters.from, filters.to)}
                    </span>
                }
                actions={
                    <Button asChild variant="outline" size="sm">
                        <a href={overview.url({ query: exportQuery })} download>
                            <FileSpreadsheet className="size-4" aria-hidden />
                            Export to Excel
                        </a>
                    </Button>
                }
            />

            <Panel className="mb-4">
                <div className="flex flex-wrap gap-1 border-b px-3 py-2">
                    {ranges.map((range) => {
                        const active =
                            range.from === filters.from &&
                            range.to === filters.to;

                        return (
                            <Link
                                key={range.name}
                                href={overview.url({
                                    query: {
                                        from: range.from,
                                        to: range.to,
                                        ...(filters.branch
                                            ? { branch: filters.branch }
                                            : {}),
                                    },
                                })}
                                preserveScroll
                                className={`border px-3 py-1 text-sm transition-colors ${
                                    active
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-background hover:border-primary hover:bg-muted'
                                }`}
                            >
                                {range.name}
                            </Link>
                        );
                    })}
                </div>
                <Form
                    {...overview.form()}
                    className="flex flex-wrap items-end gap-2 px-3 py-2"
                >
                    <Field id="from" label="From">
                        <Input
                            id="from"
                            name="from"
                            type="date"
                            defaultValue={filters.from}
                            className="w-40"
                        />
                    </Field>
                    <Field id="to" label="To">
                        <Input
                            id="to"
                            name="to"
                            type="date"
                            defaultValue={filters.to}
                            className="w-40"
                        />
                    </Field>
                    <Field id="branch" label="Branch">
                        <select
                            id="branch"
                            name="branch"
                            defaultValue={filters.branch ?? ''}
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
                            defaultValue={filters.status ?? ''}
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
                    <Button type="submit">Show</Button>
                </Form>
            </Panel>

            {others.length > 0 && (
                <p className="mb-4 border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    Not added in below:{' '}
                    {others
                        .map(([code, count]) => `${count} bill(s) in ${code}`)
                        .join(', ')}
                    .
                </p>
            )}

            <div className="mb-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi
                    label="Net sales"
                    value={money(totals.netMinor)}
                    icon={TrendingUp}
                    tone="blue"
                >
                    {change === null ? (
                        'Nothing in the period before to compare.'
                    ) : (
                        <span className="inline-flex items-center gap-1">
                            <span
                                className={`inline-flex items-center gap-0.5 px-1.5 py-0.5 font-medium ${
                                    change >= 0
                                        ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
                                        : 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200'
                                }`}
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
                                {Math.abs(change)}%
                            </span>
                            on the period before (
                            {money(totals.previousNetMinor)})
                        </span>
                    )}
                </Kpi>
                <Kpi
                    label="Orders"
                    value={totals.orders.total.toLocaleString()}
                    icon={ShoppingBag}
                    tone="emerald"
                >
                    {totals.orders.completed} paid · {totals.orders.pending}{' '}
                    open · {totals.orders.cancelled} cancelled
                </Kpi>
                <Kpi
                    label="Average order"
                    value={money(totals.averageOrderMinor)}
                    icon={Receipt}
                    tone="slate"
                >
                    {totals.bills.toLocaleString()} bills paid
                </Kpi>
                <Kpi
                    label="Kitchens now"
                    value={`${kitchen.preparing} preparing`}
                    icon={ChefHat}
                    tone={kitchen.slow > 0 ? 'red' : 'amber'}
                >
                    {kitchen.ready} ready to serve
                    {kitchen.slow > 0 && (
                        <span className="font-medium text-red-700 dark:text-red-300">
                            {' '}
                            · {kitchen.slow} waiting too long
                        </span>
                    )}
                    {kitchen.oldestMinutes !== null &&
                        ` · oldest ${kitchen.oldestMinutes} min`}
                </Kpi>
            </div>

            <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Figure
                    label="Before discounts"
                    value={money(totals.grossMinor)}
                    icon={Wallet}
                />
                <Figure
                    label="Discounts given"
                    value={money(totals.discountMinor)}
                    icon={Percent}
                />
                <Figure
                    label="Refunds"
                    value={`${money(totals.refundedMinor)} · ${totals.orders.refunded} orders`}
                    icon={RotateCcw}
                />
                <Figure
                    label="Taken in cash"
                    value={`${money(totals.cashMinor)} · ${money(totals.nonCashMinor)} other`}
                    icon={Banknote}
                />
            </div>

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader
                        title="Net sales"
                        description={
                            view === 'hour'
                                ? busiest
                                    ? `By hour of the day${singleDay ? '' : ', all days added together'}. Busiest: ${String(busiest.hour).padStart(2, '0')}:00.`
                                    : 'By hour of the day.'
                                : 'Day by day, after refunds.'
                        }
                        actions={
                            <div
                                className="flex border"
                                role="group"
                                aria-label="Show net sales"
                            >
                                {(
                                    [
                                        ['day', 'By day'],
                                        ['hour', 'By hour'],
                                    ] as const
                                ).map(([value, name]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        onClick={() => setView(value)}
                                        aria-pressed={view === value}
                                        className={`px-3 py-1 text-xs font-medium ${
                                            view === value
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-card hover:bg-muted'
                                        }`}
                                    >
                                        {name}
                                    </button>
                                ))}
                            </div>
                        }
                    />
                    <PanelBody>
                        {view === 'day' ? (
                            <MoneyBars
                                label="Net sales by day"
                                labels={props.trend.map((day) =>
                                    new Date(
                                        `${day.day}T00:00:00`,
                                    ).toLocaleDateString(undefined, {
                                        day: 'numeric',
                                        month: 'short',
                                    }),
                                )}
                                values={props.trend.map((day) =>
                                    major(day.netMinor),
                                )}
                                format={(value) =>
                                    formatMoney(
                                        Math.round(value * 10 ** decimals),
                                        currency,
                                    )
                                }
                                axisFormat={(value) =>
                                    short(Math.round(value * 10 ** decimals))
                                }
                            />
                        ) : (
                            <MoneyBars
                                label="Net sales by hour"
                                labels={hours.map(
                                    (hour) =>
                                        `${String(hour.hour).padStart(2, '0')}:00`,
                                )}
                                values={hours.map((hour) =>
                                    major(hour.netMinor),
                                )}
                                color={palette.series[3]}
                                format={(value) =>
                                    formatMoney(
                                        Math.round(value * 10 ** decimals),
                                        currency,
                                    )
                                }
                                axisFormat={(value) =>
                                    short(Math.round(value * 10 ** decimals))
                                }
                                tooltipNote={(index) =>
                                    `${hours[index].bills} bills`
                                }
                            />
                        )}
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader title="Paid by" />
                    <PanelBody>
                        {props.byMethod.length === 0 ? (
                            <p className="py-16 text-center text-sm text-muted-foreground">
                                No bills paid in the period.
                            </p>
                        ) : (
                            <>
                                <Ring
                                    label="Sales by payment method"
                                    labels={props.byMethod.map(
                                        (row) => row.name,
                                    )}
                                    values={props.byMethod.map((row) =>
                                        major(row.totalMinor),
                                    )}
                                    colors={props.byMethod.map(
                                        (_, index) =>
                                            palette.series[
                                                index % palette.series.length
                                            ],
                                    )}
                                    format={(value) =>
                                        formatMoney(
                                            Math.round(value * 10 ** decimals),
                                            currency,
                                        )
                                    }
                                    centre={short(totals.salesMinor)}
                                    centreNote={`${currency} taken`}
                                    size={180}
                                />
                                <ul className="mt-3 divide-y">
                                    {props.byMethod.map((row, index) => (
                                        <LegendRow
                                            key={row.method}
                                            color={
                                                palette.series[
                                                    index %
                                                        palette.series.length
                                                ]
                                            }
                                            name={row.name}
                                            value={money(row.totalMinor)}
                                            note={`${row.count}`}
                                        />
                                    ))}
                                </ul>
                            </>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            <div className="mb-4 grid gap-4 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader
                        title="Branches compared"
                        description="Net sales in the period."
                    />
                    <PanelBody>
                        {branchByNet.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No branches.
                            </p>
                        ) : (
                            <MoneyBars
                                horizontal
                                label="Net sales by branch"
                                labels={branchByNet.map((row) => row.name)}
                                values={branchByNet.map((row) =>
                                    major(row.netMinor),
                                )}
                                format={(value) =>
                                    formatMoney(
                                        Math.round(value * 10 ** decimals),
                                        currency,
                                    )
                                }
                                axisFormat={(value) =>
                                    short(Math.round(value * 10 ** decimals))
                                }
                                tooltipNote={(index) =>
                                    `${branchByNet[index].orders} orders · average ${money(branchByNet[index].averageOrderMinor)}`
                                }
                                height={Math.max(160, branchByNet.length * 44)}
                            />
                        )}
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader title="Orders" />
                    <PanelBody>
                        {totals.orders.total === 0 ? (
                            <p className="py-16 text-center text-sm text-muted-foreground">
                                No orders in the period.
                            </p>
                        ) : (
                            <>
                                <Ring
                                    label="Orders by status"
                                    labels={orderParts.map((part) => part.name)}
                                    values={orderParts.map(
                                        (part) => part.value,
                                    )}
                                    colors={orderParts.map(
                                        (part) => part.color,
                                    )}
                                    format={(value) => `${value} orders`}
                                    centre={totals.orders.total.toLocaleString()}
                                    centreNote="orders"
                                    size={180}
                                />
                                <ul className="mt-3 divide-y">
                                    {orderParts.map((part) => (
                                        <LegendRow
                                            key={part.name}
                                            color={part.color}
                                            name={part.name}
                                            value={part.value.toLocaleString()}
                                        />
                                    ))}
                                </ul>
                            </>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            <Panel className="mb-4">
                <PanelHeader
                    title="Branches"
                    description="Each opens in its own tab."
                />
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[48rem] text-sm">
                        <thead className="bg-muted text-left text-xs text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Branch
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Net sales
                                </th>
                                <th className="px-3 py-2 font-medium">Share</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Orders
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Average
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    In kitchen
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Low stock
                                </th>
                                <th className="px-3 py-2 font-medium">Till</th>
                                <th className="px-3 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {props.performance.map((row) => {
                                const share =
                                    totals.netMinor > 0
                                        ? Math.max(
                                              0,
                                              Math.round(
                                                  (row.netMinor /
                                                      totals.netMinor) *
                                                      100,
                                              ),
                                          )
                                        : 0;

                                return (
                                    <tr key={row.id} className="hover:bg-muted">
                                        <td className="px-3 py-2.5">
                                            <OpenBranch
                                                branchId={row.id}
                                                name={row.name}
                                            />
                                            {row.city && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {row.city}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-right font-medium tabular-nums">
                                            {money(row.netMinor)}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <span className="flex items-center gap-2">
                                                <span className="h-1.5 w-20 bg-muted">
                                                    <span
                                                        className="block h-1.5 bg-primary"
                                                        style={{
                                                            width: `${Math.min(100, share)}%`,
                                                        }}
                                                    />
                                                </span>
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {share}%
                                                </span>
                                            </span>
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {row.orders}
                                            {row.openOrders > 0 && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {row.openOrders} open now
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {money(row.averageOrderMinor)}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {row.pendingTickets}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {row.lowStock > 0 ? (
                                                <span className="bg-amber-50 px-1.5 py-0.5 font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                                                    {row.lowStock}
                                                </span>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    0
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <span className="inline-flex items-center gap-1.5 text-xs">
                                                <span
                                                    className={`size-2 ${
                                                        row.status === 'trading'
                                                            ? 'bg-emerald-500'
                                                            : 'bg-slate-300 dark:bg-slate-600'
                                                    }`}
                                                    aria-hidden
                                                />
                                                {row.status === 'trading'
                                                    ? 'Open'
                                                    : 'None open'}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2.5 text-right">
                                            <OpenBranch
                                                branchId={row.id}
                                                name={row.name}
                                                button
                                            >
                                                Open
                                            </OpenBranch>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </Panel>

            <div className="grid gap-4 lg:grid-cols-2">
                <Panel>
                    <PanelHeader
                        title="Needs attention"
                        actions={
                            props.alerts.length > 0 && (
                                <span className="bg-red-50 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-200">
                                    {props.alerts.length}
                                </span>
                            )
                        }
                    />
                    {props.alerts.length === 0 ? (
                        <PanelBody>
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                Nothing needs attention.
                            </p>
                        </PanelBody>
                    ) : (
                        <ul className="max-h-[28rem] divide-y overflow-y-auto text-sm">
                            {props.alerts.map((alert, index) => {
                                const kind = ALERT_KINDS[alert.kind] ?? {
                                    name: alert.kind,
                                    icon: AlertTriangle,
                                    tone: ICON_TONES.slate,
                                };
                                const Icon = kind.icon;

                                return (
                                    <li
                                        key={index}
                                        className="flex items-start gap-3 px-3 py-2.5"
                                    >
                                        <span
                                            className={`grid size-7 shrink-0 place-items-center ${kind.tone}`}
                                            title={kind.name}
                                        >
                                            <Icon
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block font-medium">
                                                {alert.branch}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {alert.message}
                                            </span>
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Orders"
                        description={`${props.orders.total.toLocaleString()} in the period`}
                    />
                    {props.orders.data.length === 0 ? (
                        <PanelBody>
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No orders in the period.
                            </p>
                        </PanelBody>
                    ) : (
                        <ul className="divide-y text-sm">
                            {props.orders.data.map((order) => (
                                <li
                                    key={order.id}
                                    className="flex items-start justify-between gap-3 px-3 py-2"
                                >
                                    <span className="min-w-0">
                                        <span className="font-medium">
                                            {order.number}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            · {order.branch} · {order.where}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {new Date(
                                                order.openedAt,
                                            ).toLocaleString(undefined, {
                                                dateStyle: 'medium',
                                                timeStyle: 'short',
                                            })}{' '}
                                            · {order.openedBy}
                                        </span>
                                    </span>
                                    <span className="flex shrink-0 flex-col items-end gap-1">
                                        <span className="tabular-nums">
                                            {money(order.paidMinor)}
                                        </span>
                                        <span
                                            className={`px-1.5 py-0.5 text-xs ${STATUS_TONES[order.status] ?? STATUS_TONES.cancelled}`}
                                        >
                                            {STATUS_NAMES[order.status] ??
                                                order.status}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {props.orders.lastPage > 1 && (
                        <div className="flex items-center justify-between border-t px-3 py-2 text-sm">
                            {props.orders.prev ? (
                                <Link
                                    href={props.orders.prev}
                                    preserveScroll
                                    className="text-primary hover:underline"
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
                                    className="text-primary hover:underline"
                                >
                                    Older
                                </Link>
                            ) : (
                                <span />
                            )}
                        </div>
                    )}
                </Panel>
            </div>
        </>
    );
}
