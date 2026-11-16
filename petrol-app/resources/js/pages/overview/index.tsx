import { Form, Head, Link, router, usePoll } from "@inertiajs/react";
import {
    AlertTriangle,
    Building2,
    CircleCheck,
    ClipboardList,
    Droplets,
    Fuel,
    Gauge,
    RefreshCw,
    ShieldCheck,
    TrendingDown,
    TrendingUp,
    Users,
    Wallet,
} from "lucide-react";
import { useState, type ReactNode } from "react";
import { PageHeader } from "@miles/ui/components/page-header";
import { Panel, PanelBody, PanelHeader } from "@miles/ui/components/panel";
import { StatCard } from "@miles/ui/components/stat-card";
import { StatusBadge, type Tone } from "@miles/ui/components/status-badge";
import { Button } from "@miles/ui/components/ui/button";
import { Input } from "@miles/ui/components/ui/input";
import { cn } from "@miles/ui/lib/utils";
import { formatMoney } from "@/lib/money";
import { update } from "@/routes/branch";
import { index, main } from "@/routes/overview";
import {
    BusyHours,
    Empty,
    gradeColors,
    SalesTrend,
    SegmentedBar,
    Sparkline,
    type Day,
    type Hour,
} from "./charts";

type State = "critical" | "attention" | "ok" | "closed";

type Station = {
    id: string;
    name: string;
    code: string | null;
    main: boolean;
    state: State;
    shift: { number: string; openedAt: string } | null;
    salesMinor: number;
    litres: string;
    count: number;
    lastSaleAt: string | null;
    stockLitres: string;
    fill: number;
    tanks: number;
    lowTanks: number;
    attendants: number;
    staffedPumps: number;
    pumps: number;
    shortageMinor: number;
    issues: {
        tone: "critical" | "warning" | "info" | "neutral";
        text: string;
    }[];
};

type Overview = {
    kpis: {
        salesMinor: number;
        previousSalesMinor: number;
        salesChange: number | null;
        litres: string;
        count: number;
        profitMinor: number;
        stockLitres: string;
        stockValueMinor: number;
        cashMinor: number;
        creditMinor: number;
        shortageMinor: number;
        overageMinor: number;
        varianceMilli: number;
        unverifiedVarianceMilli: number;
        openShifts: number;
        pendingApprovals: number;
        branchCount: number;
        activeStations: number;
    };
    series: Day[];
    hours?: Hour[];
    stations: Station[];
    byProduct: {
        name: string;
        litres: string;
        totalMinor: number;
        share: number;
    }[];
    payments: { kind: string; totalMinor: number }[];
    tanks: {
        branchId: string;
        code: string;
        product: string;
        capacity: string;
        quantity: string;
        fill: number;
        valueMinor: number;
        low: boolean;
    }[];
    accountability?: {
        activeAttendants: number;
        activeAssignments: number;
        pendingHandovers: number;
        openExceptions: number;
        unverifiedReadings: number;
    };
    fuel?: {
        pendingDeliveries: number;
        waterAlerts: number;
        calibrationsDue: number;
        tanksUnmeasured: number;
    };
    onDuty: {
        attendant: string;
        branch: string;
        nozzle: string;
        shift: string;
    }[];
    alerts: { severity: string; branch: string; text: string }[];
    operations: {
        expensesMinor: number;
        pendingExpenses: number;
        serviceOpen: number;
        serviceCompleted: number;
        serviceRevenueMinor: number;
        storeValueMinor: number;
        storeLow: number;
        fuelInTransit: string;
        storeInTransit: number;
        attendantHeldMinor: number;
        cashierHeldMinor: number;
        safeHeldMinor: number;
        depositsInTransitMinor: number;
        depositsConfirmedMinor: number;
        remittedMinor: number;
        pendingTransfers: number;
        shortageMinor: number;
        overageMinor: number;
        custodyCurrencies: {
            currency: string;
            attendant: number;
            remitted: number;
            cashier: number;
            safe: number;
            inTransit: number;
            confirmed: number;
            depositGap: number;
            pending: number;
            shortage: number;
            overage: number;
        }[];
        custodyEquivalent?: {
            currency: string;
            complete: boolean;
            label: string;
            attendant: number;
            cashier: number;
            safe: number;
            inTransit: number;
            confirmed: number;
            depositGap: number;
            pending: number;
            remitted: number;
            shortage: number;
            overage: number;
        };
    };
};

const PAYMENT_LABELS: Record<string, string> = {
    cash: "Cash",
    mobile_money: "Mobile money",
    bank_transfer: "Bank transfer",
    credit: "Customer credit",
};

/** Payment methods keep one colour each; credit is the slot that reads as "owed". */
const PAYMENT_COLORS: Record<string, string> = {
    cash: "var(--viz-1)",
    mobile_money: "var(--viz-3)",
    bank_transfer: "var(--viz-axis)",
    credit: "var(--viz-2)",
};

const STATES: Record<State, { label: string; tone: Tone; dot: string }> = {
    critical: { label: "Act now", tone: "danger", dot: "bg-red-500" },
    attention: { label: "Attention", tone: "warning", dot: "bg-amber-500" },
    ok: { label: "Running", tone: "success", dot: "bg-emerald-500" },
    closed: { label: "Closed", tone: "neutral", dot: "bg-slate-400" },
};

const ISSUE_TONES: Record<Station["issues"][number]["tone"], string> = {
    critical: "text-red-700",
    warning: "text-amber-700",
    info: "text-muted-foreground",
    neutral: "text-muted-foreground",
};

/** Live while the range ends today: the board reloads every minute. */
const POLL_MS = 60_000;

export default function Overview({
    from,
    to,
    branchId,
    canDesignate,
    presets,
    branches,
    overview,
    currency,
    currencyNote,
    generatedAt,
    reportingCurrencies = ["SSP", "USD"],
}: {
    from: string;
    to: string;
    branchId: string | null;
    canDesignate: boolean;
    presets: { label: string; from: string; to: string }[];
    branches: { id: string; name: string; main: boolean }[];
    overview: Overview;
    currency: string;
    currencyNote: string;
    generatedAt: string;
    reportingCurrencies?: string[];
}) {
    const money = (minor: number) => formatMoney(minor, currency);
    const { kpis, stations } = overview;
    const [shown, setShown] = useState<State | "all">("all");
    const today = presets[0]?.to;
    const live = to === today;
    usePoll(POLL_MS, {}, { autoStart: live, keepAlive: false });

    const counts = (["critical", "attention", "ok", "closed"] as State[]).map(
        (state) => ({
            state,
            count: stations.filter((station) => station.state === state).length,
        }),
    );
    const board =
        shown === "all"
            ? stations
            : stations.filter((station) => station.state === shown);
    const activePreset = presets.find(
        (preset) => preset.from === from && preset.to === to,
    );
    const range = activePreset
        ? activePreset.label
        : from === to
          ? from
          : `${from} to ${to}`;
    const pumps = stations.reduce((sum, station) => sum + station.pumps, 0);
    const staffed = stations.reduce(
        (sum, station) => sum + station.staffedPumps,
        0,
    );
    const trend = overview.series;
    const colors = gradeColors([
        ...trend.flatMap((day) => (day.grades ?? []).map((g) => g.name)),
        ...overview.byProduct.map((product) => product.name),
        ...overview.tanks.map((tank) => tank.product),
    ]);

    return (
        <>
            <Head title="Operations dashboard" />
            <PageHeader
                title="Operations dashboard"
                description={`Operations across ${kpis.branchCount} station${kpis.branchCount === 1 ? "" : "s"} · ${range} · ${currency}`}
            />

            {/* Range, station and currency */}
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2 border bg-card p-2">
                <div className="flex flex-wrap items-center gap-1">
                    {presets.map((preset) => (
                        <Button
                            key={preset.label}
                            variant={
                                activePreset?.label === preset.label
                                    ? "default"
                                    : "ghost"
                            }

                            asChild
                        >
                            <Link
                                href={index({
                                    query: {
                                        from: preset.from,
                                        to: preset.to,
                                        ...(branchId
                                            ? { branch: branchId }
                                            : {}),
                                        currency,
                                    },
                                })}
                            >
                                {preset.label}
                            </Link>
                        </Button>
                    ))}
                </div>
                <Form
                    {...index.form()}
                    className="flex flex-wrap items-center gap-2"
                >
                    <Input
                        name="from"
                        type="date"
                        defaultValue={from}
                        aria-label="From"
                        className="h-8 w-auto"
                    />
                    <Input
                        name="to"
                        type="date"
                        defaultValue={to}
                        aria-label="To"
                        className="h-8 w-auto"
                    />
                    {branches.length > 1 && (
                        <select
                            name="branch"
                            defaultValue={branchId ?? "all"}
                            aria-label="Station"
                            className="h-8 border bg-background px-2 text-sm"
                        >
                            <option value="all">All stations</option>
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.name}
                                    {branch.main ? " (main)" : ""}
                                </option>
                            ))}
                        </select>
                    )}
                    <select
                        name="currency"
                        defaultValue={currency}
                        aria-label="Reporting currency"
                        className="h-8 border bg-background px-2 text-sm"
                    >
                        {reportingCurrencies.map((code) => (
                            <option key={code} value={code}>
                                {code}
                            </option>
                        ))}
                    </select>
                    <Button type="submit" variant="outline">
                        Show
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"

                        onClick={() => router.reload()}
                        aria-label="Refresh"
                    >
                        <RefreshCw className="size-4" aria-hidden />
                    </Button>
                </Form>
            </div>

            {/* The headline figures, with the trend behind the first two */}
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Tile
                    label="Fuel sales"
                    value={money(kpis.salesMinor)}
                    icon={Fuel}
                    hint={<SalesChange kpis={kpis} />}
                >
                    <Sparkline
                        values={trend.map((day) => day.totalMinor)}
                        label="Daily sales over the last two weeks"
                    />
                </Tile>
                <Tile
                    label="Litres sold"
                    value={`${kpis.litres} L`}
                    icon={Droplets}
                    hint={`${kpis.count.toLocaleString()} posted sale${kpis.count === 1 ? "" : "s"}`}
                >
                    <Sparkline
                        values={trend.map((day) => Number(day.litres))}
                        label="Daily litres over the last two weeks"
                    />
                </Tile>
                <Tile
                    label="Gross profit"
                    value={money(kpis.profitMinor)}
                    icon={Wallet}
                    hint={
                        kpis.salesMinor > 0
                            ? `${Math.round((kpis.profitMinor * 100) / kpis.salesMinor)}% of sales, after fuel cost and expenses`
                            : "Sales minus fuel cost and expenses"
                    }
                />
                <Tile
                    label="Fuel on hand"
                    value={`${kpis.stockLitres} L`}
                    icon={Gauge}
                    hint={`Worth ${money(kpis.stockValueMinor)} at cost`}
                >
                    <TankStrip tanks={overview.tanks} colors={colors} />
                </Tile>
            </div>

            {/* The floor right now */}
            <div className="mt-3 grid grid-cols-2 border bg-card sm:grid-cols-3 xl:grid-cols-6 [&>*]:border-b [&>*]:border-r">
                <Pulse
                    icon={ClipboardList}
                    label="Shifts open"
                    value={`${kpis.openShifts} of ${kpis.activeStations}`}
                    note={`${kpis.pendingApprovals} to approve`}
                    warn={kpis.openShifts < kpis.activeStations}
                />
                <Pulse
                    icon={Users}
                    label="On duty"
                    value={`${overview.accountability?.activeAttendants ?? 0}`}
                    note={`${staffed} of ${pumps} pumps staffed`}
                    warn={staffed < pumps}
                />
                <Pulse
                    icon={Wallet}
                    label="Cash collected"
                    value={money(kpis.cashMinor)}
                />
                <Pulse
                    icon={Building2}
                    label="Credit outstanding"
                    value={money(kpis.creditMinor)}
                />
                <Pulse
                    icon={ShieldCheck}
                    label="Approved shortages"
                    value={money(kpis.shortageMinor)}
                    note={
                        kpis.overageMinor > 0
                            ? `Over ${money(kpis.overageMinor)}`
                            : "Approved closings only"
                    }
                    bad={kpis.shortageMinor > 0}
                />
                <Pulse
                    icon={AlertTriangle}
                    label="Needs attention"
                    value={`${overview.alerts.length}`}
                    note={
                        overview.alerts.length === 0
                            ? "All clear"
                            : `${overview.alerts.filter((alert) => alert.severity === "critical").length} critical`
                    }
                    bad={overview.alerts.some(
                        (alert) => alert.severity === "critical",
                    )}
                    warn={overview.alerts.length > 0}
                />
            </div>

            {/* Sales: the trend, and what they were made of */}
            <div className="mt-4 grid gap-4 xl:grid-cols-3">
                <Panel className="xl:col-span-2">
                    <PanelHeader
                        title="Sales by day"
                        description={
                            trend.length > 0
                                ? `By fuel grade · ${trend[0].date} to ${trend[trend.length - 1].date}`
                                : undefined
                        }
                    />
                    <PanelBody>
                        <SalesTrend
                            series={trend}
                            colors={colors}
                            money={money}
                            from={from}
                        />
                    </PanelBody>
                </Panel>
                <div className="grid gap-4">
                    <Panel>
                        <PanelHeader title="Fuel sold" description={range} />
                        <PanelBody>
                            {overview.byProduct.length === 0 ? (
                                <Empty>No fuel sold in this range.</Empty>
                            ) : (
                                <ul className="grid gap-3 text-sm">
                                    {overview.byProduct.map((product) => (
                                        <li key={product.name}>
                                            <div className="mb-1 flex items-baseline justify-between gap-3">
                                                <span className="flex items-center gap-2 font-medium">
                                                    <span
                                                        className="size-2.5 rounded-sm"
                                                        style={{
                                                            background:
                                                                colors[
                                                                    product.name
                                                                ],
                                                        }}
                                                        aria-hidden
                                                    />
                                                    {product.name}
                                                </span>
                                                <span className="font-medium tabular-nums">
                                                    {money(product.totalMinor)}
                                                </span>
                                            </div>
                                            <Meter
                                                value={product.share}
                                                color={colors[product.name]}
                                            />
                                            <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                                {product.litres} L ·{" "}
                                                {product.share}% of sales
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </PanelBody>
                    </Panel>
                    <Panel>
                        <PanelHeader
                            title="How sales were settled"
                            description="Credit is money still owed, not cash in hand."
                        />
                        <PanelBody>
                            <SegmentedBar
                                money={money}
                                parts={overview.payments.map((payment) => ({
                                    name:
                                        PAYMENT_LABELS[payment.kind] ??
                                        payment.kind,
                                    totalMinor: payment.totalMinor,
                                    color:
                                        PAYMENT_COLORS[payment.kind] ??
                                        "var(--viz-axis)",
                                }))}
                            />
                        </PanelBody>
                    </Panel>
                </div>
            </div>

            {/* When the forecourt is busy, and the tanks it draws from */}
            <div className="mt-4 grid gap-4 xl:grid-cols-3">
                <Panel>
                    <PanelHeader
                        title="Busiest hours"
                        description={`Sales by hour of day · ${range}`}
                    />
                    <PanelBody>
                        <BusyHours hours={overview.hours ?? []} money={money} />
                    </PanelBody>
                </Panel>
                <Panel className="xl:col-span-2">
                    <PanelHeader
                        title="Tanks"
                        description={
                            kpis.varianceMilli !== 0 ||
                            kpis.unverifiedVarianceMilli !== 0
                                ? `Dip difference: approved ${(kpis.varianceMilli / 1000).toFixed(3)} L · awaiting approval ${(kpis.unverifiedVarianceMilli / 1000).toFixed(3)} L`
                                : "Levels now"
                        }
                    />
                    <PanelBody>
                        {overview.tanks.length === 0 ? (
                            <Empty>No active tanks in this view.</Empty>
                        ) : (
                            <div className="grid gap-4">
                                {groupTanks(overview.tanks, branches).map(
                                    (group) => (
                                        <div key={group.branchId}>
                                            {branches.length > 1 && (
                                                <p className="mb-2 text-xs font-semibold text-muted-foreground">
                                                    {group.name}
                                                </p>
                                            )}
                                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                                {group.tanks.map((tank) => (
                                                    <TankGauge
                                                        key={`${tank.branchId}-${tank.code}`}
                                                        tank={tank}
                                                        color={
                                                            colors[
                                                                tank.product
                                                            ] ??
                                                            "var(--viz-axis)"
                                                        }
                                                        money={money}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    ),
                                )}
                            </div>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            {/* What needs someone, and who is on the pumps */}
            <div className="mt-4 grid gap-4 xl:grid-cols-3">
                <Panel className="xl:col-span-2">
                    <PanelHeader
                        title="Needs attention"
                        description="Across the company"
                    />
                    <PanelBody>
                        {overview.alerts.length === 0 ? (
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <CircleCheck
                                    className="size-4 text-emerald-600"
                                    aria-hidden
                                />
                                Nothing in this view needs a decision.
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {overview.alerts.map((alert) => (
                                    <li
                                        key={`${alert.severity}-${alert.branch}-${alert.text}`}
                                        className="flex gap-2 text-sm"
                                    >
                                        <AlertTriangle
                                            className={cn(
                                                "mt-0.5 size-4 shrink-0",
                                                alert.severity === "critical"
                                                    ? "text-red-600"
                                                    : alert.severity ===
                                                        "warning"
                                                      ? "text-amber-600"
                                                      : "text-muted-foreground",
                                            )}
                                            aria-hidden
                                        />
                                        <span>
                                            <span className="font-medium">
                                                {alert.branch}.{" "}
                                            </span>
                                            {alert.text}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {overview.fuel && overview.accountability && (
                            <dl className="mt-4 grid grid-cols-2 gap-x-6 gap-y-1 border-t pt-3 text-xs sm:grid-cols-4">
                                <Fact
                                    label="Exceptions open"
                                    value={
                                        overview.accountability.openExceptions
                                    }
                                />
                                <Fact
                                    label="Handovers waiting"
                                    value={
                                        overview.accountability.pendingHandovers
                                    }
                                />
                                <Fact
                                    label="Readings not captured"
                                    value={
                                        overview.accountability
                                            .unverifiedReadings
                                    }
                                />
                                <Fact
                                    label="Deliveries to receive"
                                    value={overview.fuel.pendingDeliveries}
                                />
                                <Fact
                                    label="Water alerts"
                                    value={overview.fuel.waterAlerts}
                                />
                                <Fact
                                    label="Tanks not dipped today"
                                    value={overview.fuel.tanksUnmeasured}
                                />
                                <Fact
                                    label="Calibrations due"
                                    value={overview.fuel.calibrationsDue}
                                />
                            </dl>
                        )}
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader
                        title="On duty"
                        description="Attendants on a pump of an open shift"
                    />
                    <PanelBody>
                        {overview.onDuty.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No attendants are assigned on an open shift.
                            </p>
                        ) : (
                            <ul className="grid gap-2 text-sm">
                                {[...overview.onDuty]
                                    .sort(
                                        (a, b) =>
                                            a.branch.localeCompare(b.branch) ||
                                            a.nozzle.localeCompare(b.nozzle),
                                    )
                                    .map((row) => (
                                        <li
                                            key={`${row.shift}-${row.nozzle}-${row.attendant}`}
                                            className="flex items-center justify-between gap-3"
                                        >
                                            <span className="flex items-center gap-2">
                                                <span className="grid size-7 place-items-center rounded-full bg-muted text-xs font-semibold">
                                                    {initials(row.attendant)}
                                                </span>
                                                {row.attendant}
                                            </span>
                                            <span className="text-right text-xs text-muted-foreground">
                                                {row.nozzle}
                                                {branches.length > 1
                                                    ? ` · ${row.branch}`
                                                    : ""}
                                            </span>
                                        </li>
                                    ))}
                            </ul>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            {/* Every station at a glance */}
            <Panel className="mt-4">
                <PanelHeader
                    title="Stations"
                    description={
                        live
                            ? `Live: refreshes every minute. Updated ${clock(generatedAt)}.`
                            : `As of ${clock(generatedAt)}. Shift, staff and tank figures are now; sales are for ${range}.`
                    }
                    actions={
                        <div
                            className="flex flex-wrap gap-1"
                            role="group"
                            aria-label="Show stations"
                        >
                            <FilterButton
                                on={shown === "all"}
                                onClick={() => setShown("all")}
                            >
                                All {stations.length}
                            </FilterButton>
                            {counts
                                .filter(({ count }) => count > 0)
                                .map(({ state, count }) => (
                                    <FilterButton
                                        key={state}
                                        on={shown === state}
                                        onClick={() => setShown(state)}
                                    >
                                        <span
                                            className={cn(
                                                "size-2 rounded-full",
                                                STATES[state].dot,
                                            )}
                                            aria-hidden
                                        />
                                        {STATES[state].label} {count}
                                    </FilterButton>
                                ))}
                        </div>
                    }
                />
                <PanelBody>
                    {board.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No stations are open to you.
                        </p>
                    ) : (
                        <div className="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                            {board.map((station) => (
                                <StationCard
                                    key={station.id}
                                    station={station}
                                    money={money}
                                    share={
                                        kpis.salesMinor > 0
                                            ? Math.round(
                                                  (station.salesMinor * 100) /
                                                      kpis.salesMinor,
                                              )
                                            : 0
                                    }
                                />
                            ))}
                        </div>
                    )}
                </PanelBody>
            </Panel>
            <h2 className="mt-6 mb-2 text-sm font-semibold">Back office</h2>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Expenditures"
                    value={money(overview.operations.expensesMinor)}
                    hint={`${overview.operations.pendingExpenses} not yet approved`}
                    icon={Wallet}
                    accent="amber"
                />
                <StatCard
                    label="Service jobs open"
                    value={overview.operations.serviceOpen}
                    hint={`${overview.operations.serviceCompleted} completed · ${money(overview.operations.serviceRevenueMinor)} collected`}
                    icon={Fuel}
                    accent="violet"
                />
                <StatCard
                    label="Store stock"
                    value={money(overview.operations.storeValueMinor)}
                    hint={`${overview.operations.storeLow} at or below reorder`}
                    icon={Droplets}
                    accent="emerald"
                />
                <StatCard
                    label="In transit"
                    value={`${overview.operations.fuelInTransit} L`}
                    hint={`${overview.operations.storeInTransit} store transfer${overview.operations.storeInTransit === 1 ? "" : "s"} on the road`}
                    icon={Building2}
                    accent="slate"
                />
            </div>

            <h2 className="mt-6 mb-2 text-sm font-semibold">
                Where the cash is
            </h2>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="With attendants"
                    value={money(overview.operations.attendantHeldMinor)}
                    hint={`${money(overview.operations.remittedMinor)} confirmed back to the cashier`}
                    icon={Users}
                    accent="amber"
                />
                <StatCard
                    label="With cashiers"
                    value={money(overview.operations.cashierHeldMinor)}
                    hint={`${overview.operations.pendingTransfers} handover${overview.operations.pendingTransfers === 1 ? "" : "s"} waiting`}
                    icon={Wallet}
                    accent="violet"
                />
                <StatCard
                    label="In safes"
                    value={money(overview.operations.safeHeldMinor)}
                    hint={`${money(overview.operations.depositsInTransitMinor)} on the way to the bank`}
                    icon={ShieldCheck}
                    accent="emerald"
                />
                <StatCard
                    label="Banked"
                    value={money(overview.operations.depositsConfirmedMinor)}
                    hint={`Short ${money(overview.operations.shortageMinor)} · over ${money(overview.operations.overageMinor)}`}
                    icon={Building2}
                    accent="blue"
                />
            </div>
            <Panel className="mt-3">
                <PanelHeader
                    title="Cash by currency"
                    description={overview.operations.custodyEquivalent?.label}
                />
                <PanelBody className="overflow-x-auto">
                    <table className="w-full text-right text-sm tabular-nums">
                        <thead className="text-xs text-muted-foreground">
                            <tr>
                                <th className="py-2 text-left font-medium">
                                    Currency
                                </th>
                                <th className="py-2 font-medium">Attendants</th>
                                <th className="py-2 font-medium">Cashiers</th>
                                <th className="py-2 font-medium">Safes</th>
                                <th className="py-2 font-medium">In transit</th>
                                <th className="py-2 font-medium">Banked</th>
                                <th className="py-2 font-medium">Short</th>
                                <th className="py-2 font-medium">Over</th>
                            </tr>
                        </thead>
                        <tbody>
                            {overview.operations.custodyCurrencies.map(
                                (row) => (
                                    <CustodyRow
                                        key={row.currency}
                                        label={row.currency}
                                        currency={row.currency}
                                        row={row}
                                    />
                                ),
                            )}
                            {overview.operations.custodyEquivalent && (
                                <CustodyRow
                                    label={`Total in ${overview.operations.custodyEquivalent.currency}${overview.operations.custodyEquivalent.complete ? "" : " (partial)"}`}
                                    currency={
                                        overview.operations.custodyEquivalent
                                            .currency
                                    }
                                    row={overview.operations.custodyEquivalent}
                                    strong
                                />
                            )}
                        </tbody>
                    </table>
                </PanelBody>
            </Panel>

            {canDesignate && branches.length > 1 && (
                <Panel className="mt-4">
                    <PanelHeader
                        title="Main branch"
                        description="Its exchange rates convert the company figures."
                    />
                    <PanelBody>
                        <Form
                            {...main.form()}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <select
                                name="branch_id"
                                aria-label="Main branch"
                                defaultValue={
                                    branches.find((branch) => branch.main)?.id
                                }
                                className="h-9 border bg-background px-2 text-sm"
                            >
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>
                                        {branch.name}
                                    </option>
                                ))}
                            </select>
                            <Button type="submit" variant="outline">
                                Save
                            </Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <p className="mt-4 text-xs text-muted-foreground">{currencyNote}</p>
        </>
    );
}

/** One station's card on the board: its shift, sales, tanks, staff and what needs someone. */
function StationCard({
    station,
    money,
    share,
}: {
    station: Station;
    money: (minor: number) => string;
    share: number;
}) {
    const state = STATES[station.state];
    const shown = station.issues.slice(0, 4);
    const more = station.issues.length - shown.length;

    return (
        <article
            className={cn(
                "flex flex-col border bg-card",
                station.state === "critical" && "border-red-300",
                station.state === "attention" && "border-amber-300",
            )}
            aria-label={station.name}
        >
            <header className="flex items-start justify-between gap-2 border-b px-3 py-2">
                <div className="min-w-0">
                    <p className="flex items-center gap-2 font-semibold">
                        <span
                            className={cn(
                                "size-2.5 shrink-0 rounded-full",
                                state.dot,
                            )}
                            aria-hidden
                        />
                        <span className="truncate">{station.name}</span>
                        {station.main && (
                            <StatusBadge tone="info">Main</StatusBadge>
                        )}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {station.shift
                            ? `Shift ${station.shift.number} open since ${clock(station.shift.openedAt)}`
                            : "No shift open"}
                        {station.code ? ` · ${station.code}` : ""}
                    </p>
                </div>
                <StatusBadge tone={state.tone}>{state.label}</StatusBadge>
            </header>

            <div className="grid grid-cols-3 divide-x border-b text-center">
                <Figure label="Sales" value={money(station.salesMinor)} />
                <Figure label="Litres" value={station.litres} />
                <Figure label="Share" value={`${share}%`} />
            </div>

            <div className="grid gap-3 px-3 py-3 text-sm">
                <div>
                    <div className="mb-1 flex justify-between gap-2 text-xs">
                        <span className="text-muted-foreground">
                            Tanks {station.tanks}
                            {station.lowTanks > 0
                                ? ` · ${station.lowTanks} low`
                                : ""}
                        </span>
                        <span className="tabular-nums">
                            {station.stockLitres} L · {station.fill}%
                        </span>
                    </div>
                    <Bar
                        value={station.fill}
                        className={
                            station.lowTanks > 0
                                ? "bg-amber-500"
                                : "bg-emerald-600"
                        }
                    />
                </div>
                <div className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                    <span>
                        {station.attendants} attendant
                        {station.attendants === 1 ? "" : "s"} ·{" "}
                        {station.staffedPumps} of {station.pumps} pumps staffed
                    </span>
                    <span>
                        {station.count} sale{station.count === 1 ? "" : "s"}
                        {station.lastSaleAt
                            ? ` · last ${clock(station.lastSaleAt)}`
                            : ""}
                    </span>
                </div>
                {shown.length === 0 ? (
                    <p className="flex items-center gap-1.5 text-xs text-emerald-700">
                        <CircleCheck className="size-3.5" aria-hidden />
                        Nothing waiting.
                    </p>
                ) : (
                    <ul className="grid gap-1 text-xs">
                        {shown.map((issue) => (
                            <li
                                key={issue.text}
                                className={cn(
                                    "flex gap-1.5",
                                    ISSUE_TONES[issue.tone],
                                )}
                            >
                                <AlertTriangle
                                    className="mt-px size-3.5 shrink-0"
                                    aria-hidden
                                />
                                {issue.text}
                            </li>
                        ))}
                        {more > 0 && (
                            <li className="text-muted-foreground">
                                and {more} more
                            </li>
                        )}
                    </ul>
                )}
            </div>

            <footer className="mt-auto flex items-center justify-between gap-2 border-t px-3 py-2">
                <span className="text-xs text-muted-foreground">
                    {station.shortageMinor > 0 ? (
                        <span className="inline-flex items-center gap-1 text-red-700">
                            <TrendingDown className="size-3.5" aria-hidden />
                            Short {money(station.shortageMinor)}
                        </span>
                    ) : (
                        "No approved shortage"
                    )}
                </span>
                <Form {...update.form()}>
                    <input type="hidden" name="branch_id" value={station.id} />
                    <Button type="submit" variant="outline" size="sm">
                        Work here
                    </Button>
                </Form>
            </footer>
        </article>
    );
}

function SalesChange({ kpis }: { kpis: Overview["kpis"] }) {
    if (kpis.salesChange === null) {
        return (
            <>
                {kpis.previousSalesMinor === 0
                    ? "No sales in the previous period"
                    : "Compared with the previous period"}
            </>
        );
    }
    const up = kpis.salesChange >= 0;
    const Icon = up ? TrendingUp : TrendingDown;

    return (
        <span
            className={cn(
                "inline-flex items-center gap-1",
                up ? "text-emerald-700" : "text-red-700",
            )}
        >
            <Icon className="size-3.5" aria-hidden />
            {up ? "Up" : "Down"} {Math.abs(kpis.salesChange)}% on the previous
            period
        </span>
    );
}

function FilterButton({
    on,
    onClick,
    children,
}: {
    on: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={on}
            className={cn(
                "inline-flex h-7 items-center gap-1.5 border px-2 text-xs font-medium",
                on
                    ? "border-primary bg-primary text-primary-foreground"
                    : "bg-background hover:bg-muted",
            )}
        >
            {children}
        </button>
    );
}

function Figure({ label, value }: { label: string; value: string }) {
    return (
        <div className="px-2 py-2">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="truncate text-sm font-semibold tabular-nums">
                {value}
            </p>
        </div>
    );
}

function Fact({ label, value }: { label: string; value: number }) {
    return (
        <>
            <dt className="text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    "text-right font-medium tabular-nums",
                    value > 0 && "text-amber-700",
                )}
            >
                {value}
            </dd>
        </>
    );
}

function Bar({ value, className }: { value: number; className?: string }) {
    return (
        <div className="h-2 overflow-hidden bg-muted">
            <div
                className={cn("h-full bg-primary", className)}
                style={{ width: `${Math.min(100, Math.max(0, value))}%` }}
            />
        </div>
    );
}

function CustodyRow({
    label,
    currency,
    row,
    strong,
}: {
    label: string;
    currency: string;
    row: {
        attendant: number;
        cashier: number;
        safe: number;
        inTransit: number;
        confirmed: number;
        shortage: number;
        overage: number;
    };
    strong?: boolean;
}) {
    const cell = (minor: number) => formatMoney(minor, currency);

    return (
        <tr className={cn("border-t", strong && "font-semibold")}>
            <td className="py-2 text-left">{label}</td>
            <td className="py-2">{cell(row.attendant)}</td>
            <td className="py-2">{cell(row.cashier)}</td>
            <td className="py-2">{cell(row.safe)}</td>
            <td className="py-2">{cell(row.inTransit)}</td>
            <td className="py-2">{cell(row.confirmed)}</td>
            <td className={cn("py-2", row.shortage > 0 && "text-red-700")}>
                {cell(row.shortage)}
            </td>
            <td className="py-2">{cell(row.overage)}</td>
        </tr>
    );
}

/** The tanks under their station's name, stations in name order. */
function groupTanks(
    tanks: Overview["tanks"],
    branches: { id: string; name: string }[],
) {
    const groups = new Map<
        string,
        { branchId: string; name: string; tanks: Overview["tanks"] }
    >();
    for (const tank of tanks) {
        const group = groups.get(tank.branchId) ?? {
            branchId: tank.branchId,
            name:
                branches.find((branch) => branch.id === tank.branchId)?.name ??
                "Station",
            tanks: [],
        };
        group.tanks.push(tank);
        groups.set(tank.branchId, group);
    }

    return [...groups.values()].sort((a, b) => a.name.localeCompare(b.name));
}

/** "14:05" today, "3 Nov 14:05" another day. */
function clock(iso: string): string {
    const at = new Date(iso);
    const time = at.toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });

    return at.toDateString() === new Date().toDateString()
        ? time
        : `${at.toLocaleDateString([], { day: "numeric", month: "short" })} ${time}`;
}

/** A headline figure; children add its trend or breakdown underneath. */
function Tile({
    label,
    value,
    icon: Icon,
    hint,
    children,
}: {
    label: string;
    value: string;
    icon: typeof Fuel;
    hint?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-col border bg-card p-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-xs font-medium text-muted-foreground">
                    {label}
                </p>
                <Icon className="size-4 text-muted-foreground" aria-hidden />
            </div>
            <p className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            {hint && (
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {hint}
                </div>
            )}
            <div className="mt-auto">{children}</div>
        </div>
    );
}

/** One cell of the live strip; amber or red with an icon, never colour alone. */
function Pulse({
    icon: Icon,
    label,
    value,
    note,
    warn,
    bad,
}: {
    icon: typeof Fuel;
    label: string;
    value: string;
    note?: string;
    warn?: boolean;
    bad?: boolean;
}) {
    return (
        <div className="min-w-0 px-3 py-2.5">
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon
                    className={cn(
                        "size-3.5 shrink-0",
                        bad
                            ? "text-red-600"
                            : warn
                              ? "text-amber-600"
                              : "text-muted-foreground",
                    )}
                    aria-hidden
                />
                <span className="truncate">{label}</span>
            </p>
            <p className="mt-0.5 truncate text-base font-semibold tabular-nums">
                {value}
            </p>
            {note && (
                <p
                    className={cn(
                        "truncate text-xs",
                        bad
                            ? "text-red-700"
                            : warn
                              ? "text-amber-700"
                              : "text-muted-foreground",
                    )}
                >
                    {note}
                </p>
            )}
        </div>
    );
}

/** Fill level per tank under "Fuel on hand": one thin meter each. */
function TankStrip({
    tanks,
    colors,
}: {
    tanks: Overview["tanks"];
    colors: Record<string, string>;
}) {
    if (tanks.length === 0) {
        return null;
    }

    return (
        <ul className="mt-3 grid gap-1.5">
            {tanks.slice(0, 4).map((tank) => (
                <li
                    key={`${tank.branchId}-${tank.code}`}
                    className="grid grid-cols-[3.5rem_1fr_2.5rem] items-center gap-2 text-xs"
                >
                    <span className="truncate text-muted-foreground">
                        {tank.code}
                    </span>
                    <Meter
                        value={tank.fill}
                        color={colors[tank.product] ?? "var(--viz-axis)"}
                    />
                    <span className="text-right tabular-nums">
                        {tank.fill}%
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** A tank drawn as a vertical gauge, with its figures beside it. */
function TankGauge({
    tank,
    color,
    money,
}: {
    tank: Overview["tanks"][number];
    color: string;
    money: (minor: number) => string;
}) {
    const fill = Math.min(100, Math.max(0, tank.fill));

    return (
        <div className="flex gap-3 border p-3">
            <div
                className="relative h-24 w-10 shrink-0 overflow-hidden rounded-md border bg-muted"
                role="img"
                aria-label={`${tank.code} ${fill}% full`}
            >
                <div
                    className="absolute inset-x-0 bottom-0"
                    style={{ height: `${fill}%`, background: color }}
                />
                {[25, 50, 75].map((mark) => (
                    <div
                        key={mark}
                        className="absolute inset-x-0 border-t border-background/70"
                        style={{ bottom: `${mark}%` }}
                        aria-hidden
                    />
                ))}
            </div>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{tank.product}</p>
                <p className="text-xs text-muted-foreground">{tank.code}</p>
                <p className="mt-2 text-xl font-semibold tabular-nums">
                    {fill}%
                </p>
                <p className="text-xs text-muted-foreground tabular-nums">
                    {tank.quantity} of {tank.capacity} L
                </p>
                <p className="text-xs text-muted-foreground tabular-nums">
                    {money(tank.valueMinor)} at cost
                </p>
                {tank.low && (
                    <p className="mt-1 flex items-center gap-1 text-xs font-medium text-amber-700">
                        <AlertTriangle className="size-3.5" aria-hidden />
                        Low, reorder
                    </p>
                )}
            </div>
        </div>
    );
}

/** A thin meter: the fill in the series colour on a muted track. */
function Meter({ value, color }: { value: number; color: string }) {
    return (
        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
            <div
                className="h-full rounded-full"
                style={{
                    width: `${Math.min(100, Math.max(0, value))}%`,
                    background: color,
                }}
            />
        </div>
    );
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join("");
}
