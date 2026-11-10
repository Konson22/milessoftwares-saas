import { Form, Head, Link, router, usePoll } from '@inertiajs/react';
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
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge, type Tone } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { cn } from '@miles/ui/lib/utils';
import { formatMoney } from '@/lib/money';
import { update } from '@/routes/branch';
import { index, main } from '@/routes/overview';

type State = 'critical' | 'attention' | 'ok' | 'closed';

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
        tone: 'critical' | 'warning' | 'info' | 'neutral';
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
    series: { date: string; totalMinor: number }[];
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
    cash: 'Cash',
    mobile_money: 'Mobile money',
    bank_transfer: 'Bank transfer',
    credit: 'Customer credit',
};

const STATES: Record<State, { label: string; tone: Tone; dot: string }> = {
    critical: { label: 'Act now', tone: 'danger', dot: 'bg-red-500' },
    attention: { label: 'Attention', tone: 'warning', dot: 'bg-amber-500' },
    ok: { label: 'Running', tone: 'success', dot: 'bg-emerald-500' },
    closed: { label: 'Closed', tone: 'neutral', dot: 'bg-slate-400' },
};

const ISSUE_TONES: Record<Station['issues'][number]['tone'], string> = {
    critical: 'text-red-700',
    warning: 'text-amber-700',
    info: 'text-muted-foreground',
    neutral: 'text-muted-foreground',
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
    reportingCurrencies = ['SSP', 'USD'],
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
    const [shown, setShown] = useState<State | 'all'>('all');
    const today = presets[0]?.to;
    const live = to === today;
    usePoll(POLL_MS, {}, { autoStart: live, keepAlive: false });

    const counts = (['critical', 'attention', 'ok', 'closed'] as State[]).map(
        (state) => ({
            state,
            count: stations.filter((station) => station.state === state).length,
        }),
    );
    const board =
        shown === 'all'
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

    return (
        <>
            <Head title="Operations dashboard" />
            <PageHeader
                title="Operations dashboard"
                description={`Operations across ${kpis.branchCount} station${kpis.branchCount === 1 ? '' : 's'} · ${range} · ${currency}`}
            />

            {/* Range, station and currency */}
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2 border bg-card p-2">
                <div className="flex flex-wrap items-center gap-1">
                    {presets.map((preset) => (
                        <Button
                            key={preset.label}
                            variant={
                                activePreset?.label === preset.label
                                    ? 'default'
                                    : 'ghost'
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
                            defaultValue={branchId ?? 'all'}
                            aria-label="Station"
                            className="h-8 border bg-background px-2 text-sm"
                        >
                            <option value="all">All stations</option>
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.name}
                                    {branch.main ? ' (main)' : ''}
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

            {/* The day's figures across the stations */}
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Fuel sales"
                    value={money(kpis.salesMinor)}
                    hint={<SalesChange kpis={kpis} />}
                    icon={Fuel}
                    accent="blue"
                />
                <StatCard
                    label="Litres sold"
                    value={`${kpis.litres} L`}
                    hint={`${kpis.count} posted sale${kpis.count === 1 ? '' : 's'}`}
                    icon={Droplets}
                    accent="violet"
                />
                <StatCard
                    label="Gross profit"
                    value={money(kpis.profitMinor)}
                    hint="Sales minus fuel cost and expenses"
                    icon={Wallet}
                    accent="emerald"
                />
                <StatCard
                    label="Fuel on hand"
                    value={`${kpis.stockLitres} L`}
                    hint={`Worth ${money(kpis.stockValueMinor)} at cost`}
                    icon={Gauge}
                    accent="emerald"
                />
            </div>
            <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Shifts open"
                    value={`${kpis.openShifts} of ${kpis.activeStations}`}
                    hint={`${kpis.pendingApprovals} closing${kpis.pendingApprovals === 1 ? '' : 's'} to approve`}
                    icon={ClipboardList}
                    accent={
                        kpis.openShifts < kpis.activeStations
                            ? 'amber'
                            : 'slate'
                    }
                />
                <StatCard
                    label="On duty"
                    value={`${overview.accountability?.activeAttendants ?? 0} attendants`}
                    hint={`${staffed} of ${pumps} pumps staffed`}
                    icon={Users}
                    accent={staffed < pumps ? 'amber' : 'slate'}
                />
                <StatCard
                    label="Cash collected"
                    value={money(kpis.cashMinor)}
                    hint={`Credit outstanding ${money(kpis.creditMinor)}`}
                    icon={Wallet}
                    accent="blue"
                />
                <StatCard
                    label="Approved shortages"
                    value={money(kpis.shortageMinor)}
                    hint={
                        kpis.overageMinor > 0
                            ? `Over ${money(kpis.overageMinor)}. Open shifts are excluded.`
                            : 'From approved closings only'
                    }
                    icon={ShieldCheck}
                    accent={kpis.shortageMinor > 0 ? 'red' : 'slate'}
                />
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
                                on={shown === 'all'}
                                onClick={() => setShown('all')}
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
                                                'size-2 rounded-full',
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

            <div className="mt-4 grid gap-4 lg:grid-cols-5">
                <Panel className="lg:col-span-3">
                    <PanelHeader
                        title="Sales by day"
                        description={`${from} to ${to}`}
                    />
                    <PanelBody>
                        <SalesChart series={overview.series} money={money} />
                    </PanelBody>
                </Panel>
                <Panel className="lg:col-span-2">
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
                                                'mt-0.5 size-4 shrink-0',
                                                alert.severity === 'critical'
                                                    ? 'text-red-600'
                                                    : alert.severity ===
                                                        'warning'
                                                      ? 'text-amber-600'
                                                      : 'text-muted-foreground',
                                            )}
                                            aria-hidden
                                        />
                                        <span>
                                            <span className="font-medium">
                                                {alert.branch}.{' '}
                                            </span>
                                            {alert.text}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {overview.fuel && overview.accountability && (
                            <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 border-t pt-3 text-xs">
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
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Panel>
                    <PanelHeader title="Fuel sold" />
                    <PanelBody>
                        {overview.byProduct.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No fuel sold in this range.
                            </p>
                        ) : (
                            <ul className="grid gap-3 text-sm">
                                {overview.byProduct.map((product) => (
                                    <li key={product.name}>
                                        <div className="mb-1 flex justify-between gap-3">
                                            <span className="font-medium">
                                                {product.name}
                                            </span>
                                            <span className="tabular-nums">
                                                {money(product.totalMinor)}
                                            </span>
                                        </div>
                                        <Bar value={product.share} />
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {product.litres} L · {product.share}
                                            % of sales
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
                        description="Customer credit is money still owed, not cash in the drawer."
                    />
                    <PanelBody>
                        {overview.payments.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No payments in this range.
                            </p>
                        ) : (
                            <ul className="grid gap-3 text-sm">
                                {overview.payments.map((payment) => {
                                    const total = overview.payments.reduce(
                                        (sum, row) => sum + row.totalMinor,
                                        0,
                                    );
                                    const share =
                                        total > 0
                                            ? Math.round(
                                                  (payment.totalMinor * 100) /
                                                      total,
                                              )
                                            : 0;

                                    return (
                                        <li key={payment.kind}>
                                            <div className="mb-1 flex justify-between gap-3">
                                                <span className="font-medium">
                                                    {PAYMENT_LABELS[
                                                        payment.kind
                                                    ] ?? payment.kind}
                                                </span>
                                                <span className="tabular-nums">
                                                    {money(payment.totalMinor)}
                                                </span>
                                            </div>
                                            <Bar
                                                value={share}
                                                className={
                                                    payment.kind === 'credit'
                                                        ? 'bg-amber-500'
                                                        : undefined
                                                }
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="Tanks"
                    description={
                        kpis.varianceMilli !== 0 ||
                        kpis.unverifiedVarianceMilli !== 0
                            ? `Approved dip difference ${(kpis.varianceMilli / 1000).toFixed(3)} L · awaiting approval ${(kpis.unverifiedVarianceMilli / 1000).toFixed(3)} L`
                            : undefined
                    }
                />
                <PanelBody>
                    {overview.tanks.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No active tanks in this view.
                        </p>
                    ) : (
                        <div className="grid gap-4">
                            {groupTanks(overview.tanks, branches).map(
                                (group) => (
                                    <div key={group.branchId}>
                                        <p className="mb-2 text-xs font-semibold text-muted-foreground uppercase">
                                            {group.name}
                                        </p>
                                        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                            {group.tanks.map((tank) => (
                                                <div
                                                    key={`${tank.branchId}-${tank.code}`}
                                                    className={cn(
                                                        'border p-3',
                                                        tank.low &&
                                                            'border-amber-300 bg-amber-50/50',
                                                    )}
                                                >
                                                    <div className="flex items-baseline justify-between gap-2">
                                                        <p className="font-medium">
                                                            {tank.code} ·{' '}
                                                            {tank.product}
                                                        </p>
                                                        <p className="text-sm font-semibold tabular-nums">
                                                            {tank.fill}%
                                                        </p>
                                                    </div>
                                                    <Bar
                                                        value={tank.fill}
                                                        className={
                                                            tank.low
                                                                ? 'bg-amber-500'
                                                                : 'bg-emerald-600'
                                                        }
                                                    />
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {tank.quantity} L of{' '}
                                                        {tank.capacity} L ·{' '}
                                                        {money(tank.valueMinor)}
                                                    </p>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>
                    )}
                </PanelBody>
            </Panel>

            <Panel className="mt-4">
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
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="text-xs text-muted-foreground">
                                    <tr>
                                        <th className="py-2 font-medium">
                                            Station
                                        </th>
                                        <th className="py-2 font-medium">
                                            Shift
                                        </th>
                                        <th className="py-2 font-medium">
                                            Pump
                                        </th>
                                        <th className="py-2 font-medium">
                                            Attendant
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {[...overview.onDuty]
                                        .sort(
                                            (a, b) =>
                                                a.branch.localeCompare(
                                                    b.branch,
                                                ) ||
                                                a.nozzle.localeCompare(
                                                    b.nozzle,
                                                ),
                                        )
                                        .map((row) => (
                                            <tr
                                                key={`${row.shift}-${row.nozzle}-${row.attendant}`}
                                                className="border-t"
                                            >
                                                <td className="py-2">
                                                    {row.branch}
                                                </td>
                                                <td className="py-2">
                                                    {row.shift}
                                                </td>
                                                <td className="py-2">
                                                    {row.nozzle}
                                                </td>
                                                <td className="py-2">
                                                    {row.attendant}
                                                </td>
                                            </tr>
                                        ))}
                                </tbody>
                            </table>
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
                    hint={`${overview.operations.storeInTransit} store transfer${overview.operations.storeInTransit === 1 ? '' : 's'} on the road`}
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
                    hint={`${overview.operations.pendingTransfers} handover${overview.operations.pendingTransfers === 1 ? '' : 's'} waiting`}
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
                                    label={`Total in ${overview.operations.custodyEquivalent.currency}${overview.operations.custodyEquivalent.complete ? '' : ' (partial)'}`}
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
                'flex flex-col border bg-card',
                station.state === 'critical' && 'border-red-300',
                station.state === 'attention' && 'border-amber-300',
            )}
            aria-label={station.name}
        >
            <header className="flex items-start justify-between gap-2 border-b px-3 py-2">
                <div className="min-w-0">
                    <p className="flex items-center gap-2 font-semibold">
                        <span
                            className={cn(
                                'size-2.5 shrink-0 rounded-full',
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
                            : 'No shift open'}
                        {station.code ? ` · ${station.code}` : ''}
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
                                : ''}
                        </span>
                        <span className="tabular-nums">
                            {station.stockLitres} L · {station.fill}%
                        </span>
                    </div>
                    <Bar
                        value={station.fill}
                        className={
                            station.lowTanks > 0
                                ? 'bg-amber-500'
                                : 'bg-emerald-600'
                        }
                    />
                </div>
                <div className="flex flex-wrap justify-between gap-2 text-xs text-muted-foreground">
                    <span>
                        {station.attendants} attendant
                        {station.attendants === 1 ? '' : 's'} ·{' '}
                        {station.staffedPumps} of {station.pumps} pumps staffed
                    </span>
                    <span>
                        {station.count} sale{station.count === 1 ? '' : 's'}
                        {station.lastSaleAt
                            ? ` · last ${clock(station.lastSaleAt)}`
                            : ''}
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
                                    'flex gap-1.5',
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
                        'No approved shortage'
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

function SalesChart({
    series,
    money,
}: {
    series: Overview['series'];
    money: (minor: number) => string;
}) {
    if (series.every((day) => day.totalMinor === 0)) {
        return (
            <p className="text-sm text-muted-foreground">
                No posted sales in this range.
            </p>
        );
    }
    const peak = Math.max(1, ...series.map((day) => day.totalMinor));
    const best = series.reduce((top, day) =>
        day.totalMinor > top.totalMinor ? day : top,
    );

    return (
        <>
            <div
                className="flex h-44 items-end gap-1 border-b"
                role="img"
                aria-label={`Daily sales, best day ${best.date} at ${money(best.totalMinor)}`}
            >
                {series.map((day) => (
                    <div
                        key={day.date}
                        className="group relative flex h-full min-w-0 flex-1 flex-col justify-end"
                    >
                        <div
                            className="bg-primary/80 transition-colors group-hover:bg-primary"
                            style={{
                                height: `${Math.max(2, Math.round((day.totalMinor / peak) * 100))}%`,
                            }}
                            title={`${day.date}: ${money(day.totalMinor)}`}
                        />
                    </div>
                ))}
            </div>
            <div className="mt-1 flex justify-between text-xs text-muted-foreground">
                <span>{series[0]?.date}</span>
                <span>
                    Best {best.date} · {money(best.totalMinor)}
                </span>
                <span>{series[series.length - 1]?.date}</span>
            </div>
        </>
    );
}

function SalesChange({ kpis }: { kpis: Overview['kpis'] }) {
    if (kpis.salesChange === null) {
        return (
            <>
                {kpis.previousSalesMinor === 0
                    ? 'No sales in the previous period'
                    : 'Compared with the previous period'}
            </>
        );
    }
    const up = kpis.salesChange >= 0;
    const Icon = up ? TrendingUp : TrendingDown;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1',
                up ? 'text-emerald-700' : 'text-red-700',
            )}
        >
            <Icon className="size-3.5" aria-hidden />
            {up ? 'Up' : 'Down'} {Math.abs(kpis.salesChange)}% on the previous
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
                'inline-flex h-7 items-center gap-1.5 border px-2 text-xs font-medium',
                on
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'bg-background hover:bg-muted',
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
                    'text-right font-medium tabular-nums',
                    value > 0 && 'text-amber-700',
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
                className={cn('h-full bg-primary', className)}
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
        <tr className={cn('border-t', strong && 'font-semibold')}>
            <td className="py-2 text-left">{label}</td>
            <td className="py-2">{cell(row.attendant)}</td>
            <td className="py-2">{cell(row.cashier)}</td>
            <td className="py-2">{cell(row.safe)}</td>
            <td className="py-2">{cell(row.inTransit)}</td>
            <td className="py-2">{cell(row.confirmed)}</td>
            <td className={cn('py-2', row.shortage > 0 && 'text-red-700')}>
                {cell(row.shortage)}
            </td>
            <td className="py-2">{cell(row.overage)}</td>
        </tr>
    );
}

/** The tanks under their station's name, stations in name order. */
function groupTanks(
    tanks: Overview['tanks'],
    branches: { id: string; name: string }[],
) {
    const groups = new Map<
        string,
        { branchId: string; name: string; tanks: Overview['tanks'] }
    >();
    for (const tank of tanks) {
        const group = groups.get(tank.branchId) ?? {
            branchId: tank.branchId,
            name:
                branches.find((branch) => branch.id === tank.branchId)?.name ??
                'Station',
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
        hour: '2-digit',
        minute: '2-digit',
    });

    return at.toDateString() === new Date().toDateString()
        ? time
        : `${at.toLocaleDateString([], { day: 'numeric', month: 'short' })} ${time}`;
}
