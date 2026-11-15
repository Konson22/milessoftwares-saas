import { Head, Link, router } from "@inertiajs/react";
import {
    ChevronLeft,
    ChevronRight,
    Droplets,
    Fuel,
    Plus,
    Receipt,
    Search,
    TrendingUp,
    X,
} from "lucide-react";
import { useState, type FormEvent, type ReactNode } from "react";
import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from "recharts";
import { PageHeader } from "@miles/ui/components/page-header";
import { Panel, PanelBody, PanelHeader } from "@miles/ui/components/panel";
import { StatusBadge } from "@miles/ui/components/status-badge";
import { Button } from "@miles/ui/components/ui/button";
import { Input } from "@miles/ui/components/ui/input";
import { cn } from "@miles/ui/lib/utils";
import { useMoney } from "@/lib/use-money";
import { create, index, show } from "@/routes/sales";
import {
    Empty,
    gradeColors,
    SegmentedBar,
    shortDate,
    ViewToggle,
} from "../overview/charts";

type Payment = {
    kind: string;
    amountMinor: number;
    reference: string | null;
    customer: string | null;
};

type Sale = {
    id: string;
    number: string;
    status: string;
    product: string;
    nozzle: string;
    attendant: string;
    litres: string;
    totalMinor: number;
    priceMinor: number;
    currency: string;
    soldAt: string;
    payments: Payment[];
};

type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Filters = {
    product: string;
    attendant: string;
    method: string;
    status: string;
    q: string;
};

type Summary = {
    count: number;
    totalMinor: number;
    litres: string;
    profitMinor: number;
    averageMinor: number;
    voided: number;
    payments: { kind: string; totalMinor: number }[];
    grades: {
        name: string;
        totalMinor: number;
        litres: string;
        count: number;
    }[];
    hours: { label: string; totalMinor: number; count: number }[];
};

const METHODS: Record<string, { label: string; color: string }> = {
    cash: { label: "Cash", color: "var(--viz-1)" },
    mobile_money: { label: "Mobile money", color: "var(--viz-3)" },
    bank_transfer: { label: "Bank transfer", color: "var(--viz-axis)" },
    credit: { label: "Credit", color: "var(--viz-2)" },
};

export default function Sales({
    sales,
    from,
    to,
    today,
    filters,
    summary,
    options,
    canSell,
}: {
    sales: Page<Sale>;
    from: string;
    to: string;
    today: string;
    filters: Filters;
    summary: Summary;
    options: {
        products: { id: string; name: string }[];
        attendants: { id: string; name: string }[];
    };
    canSell: boolean;
}) {
    const money = useMoney();
    const colors = gradeColors(options.products.map((product) => product.name));
    const oneDay = from === to;
    const filtered = Object.values(filters).some((value) => value !== "");

    /** Reload with some filters changed; the page goes back to 1. */
    const go = (change: Partial<Filters & { from: string; to: string }>) => {
        const query = { from, to, ...filters, ...change };
        router.get(
            index.url(),
            Object.fromEntries(
                Object.entries(query).filter(([, value]) => value !== ""),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const shiftDay = (days: number) => {
        const [y, m, d] = from.split("-").map(Number);
        const next = new Date(Date.UTC(y, m - 1, d + days))
            .toISOString()
            .slice(0, 10);
        go({ from: next, to: next });
    };

    const daysBack = (days: number) => {
        const [y, m, d] = today.split("-").map(Number);
        const start = new Date(Date.UTC(y, m - 1, d - days + 1))
            .toISOString()
            .slice(0, 10);
        go({ from: start, to: today });
    };

    const period = oneDay
        ? from === today
            ? "Today"
            : longDate(from)
        : `${shortDate(from)} to ${shortDate(to)}`;

    return (
        <>
            <Head title="Sales" />
            <PageHeader
                title="Sales"
                description="Every fuel sale at this station. Voided sales are listed but never counted."
                actions={
                    canSell ? (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus className="size-4" aria-hidden />
                                New sale
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            {/* When: one day with arrows, or a quick range */}
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2 border bg-card p-2">
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Previous day"
                        onClick={() => shiftDay(-1)}
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <div className="min-w-36 px-1 text-center">
                        <p className="text-sm font-semibold">{period}</p>
                        {oneDay && from === today && (
                            <p className="text-xs text-muted-foreground">
                                {longDate(today)}
                            </p>
                        )}
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Next day"
                        disabled={oneDay && from >= today}
                        onClick={() => shiftDay(1)}
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    <RangeButton
                        on={oneDay && from === today}
                        onClick={() => go({ from: today, to: today })}
                    >
                        Today
                    </RangeButton>
                    <RangeButton
                        on={!oneDay && to === today && days(from, to) === 7}
                        onClick={() => daysBack(7)}
                    >
                        7 days
                    </RangeButton>
                    <RangeButton
                        on={!oneDay && to === today && days(from, to) === 30}
                        onClick={() => daysBack(30)}
                    >
                        30 days
                    </RangeButton>
                    <Input
                        type="date"
                        value={from}
                        max={today}
                        aria-label="From"
                        className="h-8 w-auto"
                        onChange={(event) =>
                            event.target.value &&
                            go({
                                from: event.target.value,
                                to:
                                    event.target.value > to
                                        ? event.target.value
                                        : to,
                            })
                        }
                    />
                    <Input
                        type="date"
                        value={to}
                        max={today}
                        aria-label="To"
                        className="h-8 w-auto"
                        onChange={(event) =>
                            event.target.value && go({ to: event.target.value })
                        }
                    />
                </div>
            </div>

            {/* What the period added up to (posted sales only) */}
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Figure
                    icon={Fuel}
                    label="Sales"
                    value={money(summary.totalMinor)}
                    note={`${summary.count.toLocaleString()} posted${summary.voided > 0 ? ` · ${summary.voided} voided` : ""}`}
                />
                <Figure
                    icon={Droplets}
                    label="Litres"
                    value={`${summary.litres} L`}
                    note={summary.grades
                        .map(
                            (grade) =>
                                `${grade.name.split(" ")[0]} ${Math.round(Number(grade.litres)).toLocaleString()}`,
                        )
                        .join(" · ")}
                />
                <Figure
                    icon={Receipt}
                    label="Average sale"
                    value={money(summary.averageMinor)}
                    note={
                        summary.count > 0
                            ? `${(Number(summary.litres) / summary.count).toFixed(1)} L a sale`
                            : "No posted sales"
                    }
                />
                <Figure
                    icon={TrendingUp}
                    label="Margin on fuel"
                    value={money(summary.profitMinor)}
                    note={
                        summary.totalMinor > 0
                            ? `${Math.round((summary.profitMinor * 100) / summary.totalMinor)}% over the fuel's average cost`
                            : "Sales minus fuel cost"
                    }
                />
            </div>

            <div className="mt-4 grid gap-4 xl:grid-cols-3">
                <Panel className="xl:col-span-2">
                    <PanelHeader
                        title={oneDay ? "Sales by hour" : "Sales by day"}
                        description={period}
                    />
                    <PanelBody>
                        <Timeline
                            rows={summary.hours}
                            oneDay={oneDay}
                            money={money}
                        />
                    </PanelBody>
                </Panel>
                <div className="grid gap-4">
                    <Panel>
                        <PanelHeader title="By fuel grade" />
                        <PanelBody>
                            {summary.grades.length === 0 ? (
                                <Empty>No fuel sold.</Empty>
                            ) : (
                                <ul className="grid gap-3 text-sm">
                                    {summary.grades.map((grade) => {
                                        const share =
                                            summary.totalMinor > 0
                                                ? (grade.totalMinor * 100) /
                                                  summary.totalMinor
                                                : 0;

                                        return (
                                            <li key={grade.name}>
                                                <div className="mb-1 flex items-baseline justify-between gap-3">
                                                    <span className="flex items-center gap-2 font-medium">
                                                        <Swatch
                                                            color={
                                                                colors[
                                                                    grade.name
                                                                ]
                                                            }
                                                        />
                                                        {grade.name}
                                                    </span>
                                                    <span className="font-medium tabular-nums">
                                                        {money(
                                                            grade.totalMinor,
                                                        )}
                                                    </span>
                                                </div>
                                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="h-full rounded-full"
                                                        style={{
                                                            width: `${share}%`,
                                                            background:
                                                                colors[
                                                                    grade.name
                                                                ],
                                                        }}
                                                    />
                                                </div>
                                                <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                                    {grade.litres} L ·{" "}
                                                    {grade.count} sales ·{" "}
                                                    {Math.round(share)}%
                                                </p>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </PanelBody>
                    </Panel>
                    <Panel>
                        <PanelHeader
                            title="How it was paid"
                            description="Credit is owed, not collected."
                        />
                        <PanelBody>
                            <SegmentedBar
                                money={money}
                                parts={summary.payments
                                    .filter((payment) => payment.totalMinor > 0)
                                    .map((payment) => ({
                                        name:
                                            METHODS[payment.kind]?.label ??
                                            payment.kind,
                                        totalMinor: payment.totalMinor,
                                        color:
                                            METHODS[payment.kind]?.color ??
                                            "var(--viz-axis)",
                                    }))}
                            />
                        </PanelBody>
                    </Panel>
                </div>
            </div>

            {/* The ledger */}
            <Panel className="mt-4">
                <div className="flex flex-wrap items-center gap-2 border-b p-2">
                    <SearchBox
                        initial={filters.q}
                        onSearch={(q) => go({ q })}
                    />
                    <Select
                        label="Fuel grade"
                        value={filters.product}
                        onChange={(product) => go({ product })}
                        options={options.products.map((product) => ({
                            value: product.id,
                            label: product.name,
                        }))}
                        all="All grades"
                    />
                    <Select
                        label="Attendant"
                        value={filters.attendant}
                        onChange={(attendant) => go({ attendant })}
                        options={options.attendants.map((attendant) => ({
                            value: attendant.id,
                            label: attendant.name,
                        }))}
                        all="All attendants"
                    />
                    <Select
                        label="Payment"
                        value={filters.method}
                        onChange={(method) => go({ method })}
                        options={Object.entries(METHODS).map(
                            ([value, method]) => ({
                                value,
                                label: method.label,
                            }),
                        )}
                        all="Any payment"
                    />
                    <Select
                        label="Status"
                        value={filters.status}
                        onChange={(status) => go({ status })}
                        options={[
                            { value: "posted", label: "Posted" },
                            { value: "voided", label: "Voided" },
                        ]}
                        all="Any status"
                    />
                    {filtered && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                go({
                                    product: "",
                                    attendant: "",
                                    method: "",
                                    status: "",
                                    q: "",
                                })
                            }
                        >
                            <X className="size-3.5" aria-hidden />
                            Clear
                        </Button>
                    )}
                    <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                        {sales.total.toLocaleString()} sale
                        {sales.total === 1 ? "" : "s"}
                    </span>
                </div>

                {sales.data.length === 0 ? (
                    <div className="p-6">
                        <Empty>
                            {filtered
                                ? "No sales match these filters."
                                : "No sales in this period."}
                        </Empty>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/40 text-xs text-muted-foreground">
                                <tr>
                                    <Th>Time</Th>
                                    <Th>Receipt</Th>
                                    <Th>Fuel</Th>
                                    <Th>Pump · attendant</Th>
                                    <Th>Paid by</Th>
                                    <Th right>Litres</Th>
                                    <Th right>Price / L</Th>
                                    <Th right>Amount</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {sales.data.map((sale) => {
                                    const voided = sale.status !== "posted";

                                    return (
                                        <tr
                                            key={sale.id}
                                            className={cn(
                                                "cursor-pointer border-b last:border-0 hover:bg-muted/40",
                                                voided &&
                                                    "text-muted-foreground",
                                            )}
                                            onClick={() =>
                                                router.visit(show(sale.id))
                                            }
                                        >
                                            <Td>
                                                <span className="tabular-nums">
                                                    {time(sale.soldAt)}
                                                </span>
                                                {!oneDay && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {new Date(
                                                            sale.soldAt,
                                                        ).toLocaleDateString(
                                                            [],
                                                            {
                                                                day: "numeric",
                                                                month: "short",
                                                            },
                                                        )}
                                                    </span>
                                                )}
                                            </Td>
                                            <Td>
                                                <Link
                                                    href={show(sale.id)}
                                                    className="font-medium hover:underline"
                                                    onClick={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                >
                                                    {sale.number}
                                                </Link>
                                                {voided && (
                                                    <span className="ml-2">
                                                        <StatusBadge tone="danger">
                                                            Voided
                                                        </StatusBadge>
                                                    </span>
                                                )}
                                            </Td>
                                            <Td>
                                                <span className="flex items-center gap-2">
                                                    <Swatch
                                                        color={
                                                            colors[sale.product]
                                                        }
                                                    />
                                                    {sale.product}
                                                </span>
                                            </Td>
                                            <Td>
                                                <span className="font-mono text-xs">
                                                    {sale.nozzle}
                                                </span>{" "}
                                                · {sale.attendant}
                                            </Td>
                                            <Td>
                                                <span className="flex flex-wrap gap-1">
                                                    {sale.payments.map(
                                                        (payment) => (
                                                            <PaymentChip
                                                                key={
                                                                    payment.kind
                                                                }
                                                                payment={
                                                                    payment
                                                                }
                                                                split={
                                                                    sale
                                                                        .payments
                                                                        .length >
                                                                    1
                                                                }
                                                                money={money}
                                                            />
                                                        ),
                                                    )}
                                                </span>
                                            </Td>
                                            <Td right>{sale.litres}</Td>
                                            <Td right>
                                                {money(sale.priceMinor)}
                                            </Td>
                                            <Td right>
                                                <span
                                                    className={cn(
                                                        "font-semibold",
                                                        voided &&
                                                            "line-through",
                                                    )}
                                                >
                                                    {money(sale.totalMinor)}
                                                </span>
                                            </Td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {sales.last_page > 1 && (
                    <div className="flex items-center justify-between gap-2 border-t px-3 py-2 text-xs text-muted-foreground">
                        <span className="tabular-nums">
                            {sales.from}–{sales.to} of{" "}
                            {sales.total.toLocaleString()}
                        </span>
                        <div className="flex items-center gap-1">
                            <PageLink
                                href={sales.prev_page_url}
                                label="Previous page"
                            >
                                <ChevronLeft className="size-4" />
                            </PageLink>
                            <span className="px-2 tabular-nums">
                                Page {sales.current_page} of {sales.last_page}
                            </span>
                            <PageLink
                                href={sales.next_page_url}
                                label="Next page"
                            >
                                <ChevronRight className="size-4" />
                            </PageLink>
                        </div>
                    </div>
                )}
            </Panel>
        </>
    );
}

/** Sales by hour (one day) or by day (a range): one series, so no legend. */
function Timeline({
    rows,
    oneDay,
    money,
}: {
    rows: Summary["hours"];
    oneDay: boolean;
    money: (minor: number) => string;
}) {
    const [table, setTable] = useState(false);
    if (rows.length === 0) {
        return <Empty>No posted sales in this period.</Empty>;
    }

    // Fill the gaps so quiet hours or days show as empty columns.
    let data = rows;
    if (oneDay) {
        const by = new Map(rows.map((row) => [Number(row.label), row]));
        const first = Math.min(...by.keys());
        const last = Math.max(...by.keys());
        data = [];
        for (let hour = first; hour <= last; hour++) {
            data.push(
                by.get(hour) ?? {
                    label: String(hour).padStart(2, "0"),
                    totalMinor: 0,
                    count: 0,
                },
            );
        }
    }
    const label = (row: Summary["hours"][number]) =>
        oneDay ? `${row.label.padStart(2, "0")}:00` : shortDate(row.label);
    const peak = data.reduce((top, row) =>
        row.totalMinor > top.totalMinor ? row : top,
    );

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground">
                    {oneDay ? "Busiest hour" : "Best day"}{" "}
                    <span className="font-medium text-foreground">
                        {label(peak)}
                    </span>{" "}
                    · {money(peak.totalMinor)} from {peak.count} sales
                </p>
                <ViewToggle table={table} onChange={setTable} />
            </div>
            {table ? (
                <div className="max-h-64 overflow-auto">
                    <table className="w-full text-right text-sm tabular-nums">
                        <thead className="sticky top-0 bg-card text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1.5 text-left font-medium">
                                    {oneDay ? "Hour" : "Day"}
                                </th>
                                <th className="py-1.5 font-medium">Sales</th>
                                <th className="py-1.5 font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((row) => (
                                <tr key={row.label} className="border-t">
                                    <td className="py-1.5 text-left">
                                        {label(row)}
                                    </td>
                                    <td className="py-1.5">{row.count}</td>
                                    <td className="py-1.5">
                                        {money(row.totalMinor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div
                    className="h-64"
                    role="img"
                    aria-label={`Sales ${oneDay ? "by hour" : "by day"}`}
                >
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart
                            data={data}
                            margin={{ top: 8, right: 4, bottom: 0, left: 0 }}
                            barCategoryGap="20%"
                        >
                            <CartesianGrid
                                vertical={false}
                                stroke="var(--viz-grid)"
                            />
                            <XAxis
                                dataKey="label"
                                tickFormatter={(value: string) =>
                                    oneDay
                                        ? value.padStart(2, "0")
                                        : shortDate(value)
                                }
                                minTickGap={oneDay ? 4 : 24}
                                stroke="var(--viz-grid)"
                                tick={{ fill: "var(--viz-axis)", fontSize: 11 }}
                                tickLine={false}
                            />
                            <YAxis
                                tickFormatter={(minor: number) =>
                                    new Intl.NumberFormat(undefined, {
                                        notation: "compact",
                                        maximumFractionDigits: 1,
                                    }).format(minor / 100)
                                }
                                width={44}
                                axisLine={false}
                                tickLine={false}
                                tick={{ fill: "var(--viz-axis)", fontSize: 11 }}
                            />
                            <Tooltip
                                cursor={{ fill: "var(--muted)", opacity: 0.6 }}
                                content={({ active, payload }) => {
                                    const row = payload?.[0]?.payload as
                                        Summary["hours"][number] | undefined;
                                    if (!active || !row) {
                                        return null;
                                    }

                                    return (
                                        <div className="min-w-40 border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md">
                                            <p className="mb-1 font-semibold">
                                                {label(row)}
                                            </p>
                                            <p className="flex justify-between gap-4 tabular-nums">
                                                <span className="text-muted-foreground">
                                                    Sales
                                                </span>
                                                {row.count}
                                            </p>
                                            <p className="flex justify-between gap-4 font-semibold tabular-nums">
                                                <span className="font-normal text-muted-foreground">
                                                    Amount
                                                </span>
                                                {money(row.totalMinor)}
                                            </p>
                                        </div>
                                    );
                                }}
                            />
                            <Bar
                                dataKey="totalMinor"
                                fill="var(--viz-1)"
                                maxBarSize={24}
                                radius={[4, 4, 0, 0]}
                                isAnimationActive={false}
                            />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}
        </div>
    );
}

function Figure({
    icon: Icon,
    label,
    value,
    note,
}: {
    icon: typeof Fuel;
    label: string;
    value: string;
    note?: string;
}) {
    return (
        <div className="border bg-card p-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-xs font-medium text-muted-foreground">
                    {label}
                </p>
                <Icon className="size-4 text-muted-foreground" aria-hidden />
            </div>
            <p className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            {note && (
                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                    {note}
                </p>
            )}
        </div>
    );
}

/** A payment method as a chip; the dot carries the method, the text says it. */
function PaymentChip({
    payment,
    split,
    money,
}: {
    payment: Payment;
    split: boolean;
    money: (minor: number) => string;
}) {
    const method = METHODS[payment.kind];
    const detail = payment.customer ?? payment.reference;

    return (
        <span
            className="inline-flex max-w-56 items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs"
            title={detail ?? undefined}
        >
            <Swatch color={method?.color ?? "var(--viz-axis)"} round />
            <span>{method?.label ?? payment.kind}</span>
            {split && (
                <span className="text-muted-foreground tabular-nums">
                    {money(payment.amountMinor)}
                </span>
            )}
            {detail && (
                <span className="truncate text-muted-foreground">
                    · {detail}
                </span>
            )}
        </span>
    );
}

function SearchBox({
    initial,
    onSearch,
}: {
    initial: string;
    onSearch: (q: string) => void;
}) {
    const [value, setValue] = useState(initial);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSearch(value.trim());
    };

    return (
        <form onSubmit={submit} className="relative" role="search">
            <Search
                className="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground"
                aria-hidden
            />
            <Input
                value={value}
                onChange={(event) => setValue(event.target.value)}
                placeholder="Receipt number"
                aria-label="Search by receipt number"
                className="h-8 w-44 pl-7"
            />
        </form>
    );
}

function Select({
    label,
    value,
    onChange,
    options,
    all,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    all: string;
}) {
    return (
        <select
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            className={cn(
                "h-8 border bg-background px-2 text-sm",
                value !== "" && "border-primary text-primary",
            )}
        >
            <option value="">{all}</option>
            {options.map((option) => (
                <option key={option.value} value={option.value}>
                    {option.label}
                </option>
            ))}
        </select>
    );
}

function RangeButton({
    on,
    onClick,
    children,
}: {
    on: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Button
            variant={on ? "default" : "ghost"}
            size="sm"
            aria-pressed={on}
            onClick={onClick}
        >
            {children}
        </Button>
    );
}

function PageLink({
    href,
    label,
    children,
}: {
    href: string | null;
    label: string;
    children: ReactNode;
}) {
    return href ? (
        <Button variant="outline" size="icon" asChild>
            <Link href={href} preserveScroll aria-label={label}>
                {children}
            </Link>
        </Button>
    ) : (
        <Button variant="outline" size="icon" disabled aria-label={label}>
            {children}
        </Button>
    );
}

function Th({ children, right }: { children: ReactNode; right?: boolean }) {
    return (
        <th
            className={cn(
                "px-3 py-2 font-medium whitespace-nowrap",
                right ? "text-right" : "text-left",
            )}
        >
            {children}
        </th>
    );
}

function Td({ children, right }: { children: ReactNode; right?: boolean }) {
    return (
        <td
            className={cn(
                "px-3 py-2 whitespace-nowrap",
                right && "text-right tabular-nums",
            )}
        >
            {children}
        </td>
    );
}

function Swatch({ color, round }: { color?: string; round?: boolean }) {
    return (
        <span
            className={cn(
                "size-2.5 shrink-0",
                round ? "rounded-full" : "rounded-sm",
            )}
            style={{ background: color ?? "var(--viz-axis)" }}
            aria-hidden
        />
    );
}

/** Inclusive days from one date to another. */
function days(from: string, to: string): number {
    return Math.round((Date.parse(to) - Date.parse(from)) / 86_400_000) + 1;
}

/** "Saturday 14 November" from "2026-11-14". */
function longDate(date: string): string {
    const [y, m, d] = date.split("-").map(Number);

    return new Date(y, m - 1, d).toLocaleDateString([], {
        weekday: "long",
        day: "numeric",
        month: "long",
    });
}

function time(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });
}
