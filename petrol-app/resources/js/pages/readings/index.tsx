import { Head, Link, router } from "@inertiajs/react";
import {
    AlertTriangle,
    ArrowRight,
    CircleCheck,
    Droplets,
    FlaskConical,
    Gauge,
    PencilLine,
    Receipt,
    RefreshCw,
} from "lucide-react";
import { useState, type ReactNode } from "react";
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
import { index } from "@/routes/readings";
import { show } from "@/routes/shifts";
import {
    Empty,
    gradeColors,
    Legend,
    shortDate,
    ViewToggle,
} from "../overview/charts";

type Meter = {
    id: string;
    code: string;
    name: string;
    product: string | null;
    pump: string | null;
    status: string;
    reading: string | null;
    readAt: string | null;
    kind: string | null;
    soldThisShift: string | null;
};

type Line = {
    nozzleId: string;
    code: string | null;
    nozzle: string | null;
    product: string | null;
    opening: string;
    closing: string | null;
    metered: string | null;
    sold: string;
    tested: string;
    varianceMilli: number | null;
    corrected: boolean;
    meterChanged: boolean;
};

type ShiftRow = {
    id: string;
    number: string;
    status: string;
    businessDate: string;
    schedule: string | null;
    openedAt: string;
    lines: Line[];
};

type Day = {
    date: string;
    meteredMilli: number;
    soldMilli: number;
    varianceMilli: number;
};

type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const STATUS: Record<
    string,
    { label: string; tone: "info" | "warning" | "success" | "danger" }
> = {
    open: { label: "Open", tone: "info" },
    closing_submitted: { label: "Submitted", tone: "warning" },
    under_review: { label: "Under review", tone: "warning" },
    approved: { label: "Approved", tone: "success" },
    rejected: { label: "Rejected", tone: "danger" },
};

export default function Readings({
    from,
    to,
    today,
    nozzle,
    meters,
    openShift,
    summary,
    days,
    shifts,
}: {
    from: string;
    to: string;
    today: string;
    nozzle: string;
    meters: Meter[];
    openShift: { id: string; number: string } | null;
    summary: {
        metered: string;
        sold: string;
        tested: string;
        varianceMilli: number;
        missing: number;
        corrected: number;
        shifts: number;
    };
    days: Day[];
    shifts: Page<ShiftRow>;
}) {
    const colors = gradeColors(meters.map((meter) => meter.product ?? ""));

    const go = (change: { from?: string; to?: string; nozzle?: string }) => {
        const query = { from, to, nozzle, ...change };
        router.get(
            index.url(),
            Object.fromEntries(
                Object.entries(query).filter(([, value]) => value),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };
    const back = (count: number) => {
        const [y, m, d] = today.split("-").map(Number);
        go({
            from: new Date(Date.UTC(y, m - 1, d - count + 1))
                .toISOString()
                .slice(0, 10),
            to: today,
        });
    };
    const span =
        Math.round((Date.parse(to) - Date.parse(from)) / 86_400_000) + 1;

    return (
        <>
            <Head title="Meter readings" />
            <PageHeader
                title="Meter readings"
                description="What each meter moved against what was sold. Litres the meter moved that no sale or test explains need a look."
            />

            {/* Every meter as it stands */}
            <Panel>
                <PanelHeader
                    title="Meters now"
                    description={
                        openShift
                            ? `Last reading per nozzle. Shift ${openShift.number} is open: litres sold on it so far are shown under each meter.`
                            : "Last reading per nozzle. No shift is open."
                    }
                    actions={
                        openShift ? (
                            <Button asChild size="sm" variant="outline">
                                <Link href={show(openShift.id)}>
                                    Shift {openShift.number}
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />
                <PanelBody>
                    {meters.length === 0 ? (
                        <Empty>No nozzles at this station yet.</Empty>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5">
                            {meters.map((meter) => (
                                <MeterCard
                                    key={meter.id}
                                    meter={meter}
                                    color={colors[meter.product ?? ""]}
                                />
                            ))}
                        </div>
                    )}
                </PanelBody>
            </Panel>

            {/* Which days */}
            <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border bg-card p-2">
                <div className="flex flex-wrap items-center gap-1">
                    <RangeButton
                        on={from === today && to === today}
                        onClick={() => go({ from: today, to: today })}
                    >
                        Today
                    </RangeButton>
                    <RangeButton
                        on={to === today && span === 7}
                        onClick={() => back(7)}
                    >
                        7 days
                    </RangeButton>
                    <RangeButton
                        on={to === today && span === 31}
                        onClick={() => back(31)}
                    >
                        31 days
                    </RangeButton>
                    <Input
                        type="date"
                        value={from}
                        max={today}
                        aria-label="From"
                        className="h-8 w-auto"
                        onChange={(event) =>
                            event.target.value &&
                            go({ from: event.target.value })
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
                <select
                    aria-label="Nozzle"
                    value={nozzle}
                    onChange={(event) => go({ nozzle: event.target.value })}
                    className={cn(
                        "h-8 border bg-background px-2 text-sm",
                        nozzle && "border-primary text-primary",
                    )}
                >
                    <option value="">All nozzles</option>
                    {meters.map((meter) => (
                        <option key={meter.id} value={meter.id}>
                            {meter.code} · {meter.product}
                        </option>
                    ))}
                </select>
            </div>

            {/* What the meters said against the books */}
            <div className="mt-3 grid grid-cols-2 border bg-card lg:grid-cols-3 xl:grid-cols-6 [&>*]:border-r [&>*]:border-b">
                <Pulse
                    icon={Gauge}
                    label="Meters moved"
                    value={`${litres(summary.metered)} L`}
                    note={`${summary.shifts} shifts`}
                />
                <Pulse
                    icon={Receipt}
                    label="Recorded as sales"
                    value={`${litres(summary.sold)} L`}
                />
                <Pulse
                    icon={FlaskConical}
                    label="Test draws"
                    value={`${litres(summary.tested)} L`}
                    note="Returned to the tank or approved"
                />
                <Pulse
                    icon={
                        summary.varianceMilli === 0
                            ? CircleCheck
                            : AlertTriangle
                    }
                    label="Not explained"
                    value={signedLitres(summary.varianceMilli)}
                    note={
                        summary.varianceMilli === 0
                            ? "Every litre accounted for"
                            : summary.varianceMilli > 0
                              ? "Moved but not sold"
                              : "Sold more than metered"
                    }
                    bad={summary.varianceMilli !== 0}
                    good={summary.varianceMilli === 0}
                />
                <Pulse
                    icon={Droplets}
                    label="Closing missing"
                    value={String(summary.missing)}
                    note={
                        summary.missing > 0 ? "Open or unsubmitted" : "All read"
                    }
                    warn={summary.missing > 0}
                />
                <Pulse
                    icon={PencilLine}
                    label="Readings corrected"
                    value={String(summary.corrected)}
                    note="Changed after entry, with a reason"
                    warn={summary.corrected > 0}
                />
            </div>

            <Panel className="mt-4">
                <PanelHeader
                    title="Litres by day"
                    description="What the meters moved, split into sold and not explained."
                />
                <PanelBody>
                    <DayChart days={days} />
                </PanelBody>
            </Panel>

            {/* Shift by shift */}
            <div className="mt-4 grid gap-4">
                {shifts.data.length === 0 ? (
                    <Panel>
                        <PanelBody>
                            <Empty>No readings in these days.</Empty>
                        </PanelBody>
                    </Panel>
                ) : (
                    shifts.data.map((shift) => (
                        <ShiftBlock
                            key={shift.id}
                            shift={shift}
                            colors={colors}
                        />
                    ))
                )}
            </div>

            {shifts.last_page > 1 && (
                <div className="mt-3 flex items-center justify-end gap-2 text-xs text-muted-foreground">
                    <PageLink href={shifts.prev_page_url}>Newer</PageLink>
                    <span className="tabular-nums">
                        Page {shifts.current_page} of {shifts.last_page} ·{" "}
                        {shifts.total} shifts
                    </span>
                    <PageLink href={shifts.next_page_url}>Older</PageLink>
                </div>
            )}
        </>
    );
}

/** One nozzle's meter: the totalizer as a counter, its grade and pump. */
function MeterCard({ meter, color }: { meter: Meter; color?: string }) {
    const off = meter.status !== "active";

    return (
        <div className={cn("border p-3", off && "opacity-60")}>
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="flex items-center gap-2 text-sm font-semibold">
                        <span
                            className="size-2.5 shrink-0 rounded-sm"
                            style={{ background: color ?? "var(--viz-axis)" }}
                            aria-hidden
                        />
                        {meter.code}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {meter.product}
                        {meter.pump ? ` · ${meter.pump}` : ""}
                    </p>
                </div>
                {off && (
                    <StatusBadge tone="neutral">Out of service</StatusBadge>
                )}
            </div>
            <p className="mt-3 rounded-sm bg-foreground px-2 py-1.5 text-right font-mono text-lg tracking-wider text-background tabular-nums">
                {meter.reading === null
                    ? "—"
                    : Number(meter.reading).toLocaleString(undefined, {
                          minimumFractionDigits: 3,
                      })}
            </p>
            <p className="mt-1.5 flex justify-between gap-2 text-xs text-muted-foreground">
                <span>
                    {meter.readAt
                        ? `${meter.kind === "closing" ? "Closed" : "Opened"} ${when(meter.readAt)}`
                        : "Never read"}
                </span>
                {meter.soldThisShift !== null && (
                    <span className="font-medium text-foreground tabular-nums">
                        +{litres(meter.soldThisShift)} L
                    </span>
                )}
            </p>
        </div>
    );
}

function DayChart({ days }: { days: Day[] }) {
    const [table, setTable] = useState(false);
    if (days.length === 0) {
        return <Empty>No closed readings in these days.</Empty>;
    }
    const rows = days.map((day) => ({
        ...day,
        sold: day.soldMilli / 1000,
        unexplained: Math.max(0, day.varianceMilli) / 1000,
    }));

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <Legend
                    items={[
                        { name: "Recorded as sales", color: "var(--viz-1)" },
                        { name: "Not explained", color: "var(--viz-bad)" },
                    ]}
                />
                <ViewToggle table={table} onChange={setTable} />
            </div>
            {table ? (
                <div className="max-h-64 overflow-auto">
                    <table className="w-full text-right text-sm tabular-nums">
                        <thead className="sticky top-0 bg-card text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1.5 text-left font-medium">
                                    Day
                                </th>
                                <th className="py-1.5 font-medium">Metered</th>
                                <th className="py-1.5 font-medium">Sold</th>
                                <th className="py-1.5 font-medium">
                                    Not explained
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {[...days].reverse().map((day) => (
                                <tr key={day.date} className="border-t">
                                    <td className="py-1.5 text-left">
                                        {shortDate(day.date)}
                                    </td>
                                    <td className="py-1.5">
                                        {litres(day.meteredMilli / 1000)} L
                                    </td>
                                    <td className="py-1.5">
                                        {litres(day.soldMilli / 1000)} L
                                    </td>
                                    <td className="py-1.5">
                                        <Variance milli={day.varianceMilli} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div
                    className="h-60"
                    role="img"
                    aria-label="Litres metered by day, sold and not explained"
                >
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart
                            data={rows}
                            margin={{ top: 8, right: 4, bottom: 0, left: 0 }}
                            barCategoryGap="22%"
                        >
                            <CartesianGrid
                                vertical={false}
                                stroke="var(--viz-grid)"
                            />
                            <XAxis
                                dataKey="date"
                                tickFormatter={shortDate}
                                minTickGap={24}
                                stroke="var(--viz-grid)"
                                tick={{ fill: "var(--viz-axis)", fontSize: 11 }}
                                tickLine={false}
                            />
                            <YAxis
                                tickFormatter={(value: number) =>
                                    new Intl.NumberFormat(undefined, {
                                        notation: "compact",
                                        maximumFractionDigits: 1,
                                    }).format(value)
                                }
                                width={44}
                                axisLine={false}
                                tickLine={false}
                                tick={{ fill: "var(--viz-axis)", fontSize: 11 }}
                            />
                            <Tooltip
                                cursor={{ fill: "var(--muted)", opacity: 0.6 }}
                                content={({ active, payload }) => {
                                    const day = payload?.[0]?.payload as
                                        Day | undefined;
                                    if (!active || !day) {
                                        return null;
                                    }

                                    return (
                                        <div className="min-w-48 border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md">
                                            <p className="mb-1 font-semibold">
                                                {shortDate(day.date)}
                                            </p>
                                            <TipRow
                                                label="Metered"
                                                value={`${litres(day.meteredMilli / 1000)} L`}
                                            />
                                            <TipRow
                                                label="Sold"
                                                value={`${litres(day.soldMilli / 1000)} L`}
                                                color="var(--viz-1)"
                                            />
                                            <TipRow
                                                label="Not explained"
                                                value={signedLitres(
                                                    day.varianceMilli,
                                                )}
                                                color="var(--viz-bad)"
                                                strong
                                            />
                                        </div>
                                    );
                                }}
                            />
                            <Bar
                                dataKey="sold"
                                stackId="l"
                                fill="var(--viz-1)"
                                maxBarSize={24}
                                isAnimationActive={false}
                            />
                            <Bar
                                dataKey="unexplained"
                                stackId="l"
                                fill="var(--viz-bad)"
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

/** One shift's readings, nozzle by nozzle. */
function ShiftBlock({
    shift,
    colors,
}: {
    shift: ShiftRow;
    colors: Record<string, string>;
}) {
    const status = STATUS[shift.status] ?? {
        label: shift.status,
        tone: "info" as const,
    };
    const off = shift.lines.filter(
        (line) => line.varianceMilli !== null && line.varianceMilli !== 0,
    ).length;

    return (
        <Panel>
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-3 py-2">
                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href={show(shift.id)}
                        className="font-semibold hover:underline"
                    >
                        {shift.number}
                    </Link>
                    <span className="text-sm text-muted-foreground">
                        {longDate(shift.businessDate)}
                        {shift.schedule ? ` · ${shift.schedule}` : ""}
                    </span>
                    <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
                </div>
                <span
                    className={cn(
                        "flex items-center gap-1 text-xs",
                        off > 0 ? "text-red-700" : "text-emerald-700",
                    )}
                >
                    {off > 0 ? (
                        <AlertTriangle className="size-3.5" aria-hidden />
                    ) : (
                        <CircleCheck className="size-3.5" aria-hidden />
                    )}
                    {off > 0
                        ? `${off} nozzle${off === 1 ? "" : "s"} not balanced`
                        : "Balanced"}
                </span>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-muted/40 text-xs text-muted-foreground">
                        <tr>
                            <Th>Nozzle</Th>
                            <Th right>Opening</Th>
                            <Th right>Closing</Th>
                            <Th right>Metered</Th>
                            <Th right>Sold</Th>
                            <Th right>Tests</Th>
                            <Th right>Not explained</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {shift.lines.map((line) => (
                            <tr key={line.nozzleId} className="border-t">
                                <Td>
                                    <span className="flex items-center gap-2">
                                        <span
                                            className="size-2.5 shrink-0 rounded-sm"
                                            style={{
                                                background:
                                                    colors[
                                                        line.product ?? ""
                                                    ] ?? "var(--viz-axis)",
                                            }}
                                            aria-hidden
                                        />
                                        <span className="font-mono text-xs">
                                            {line.code}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {line.product}
                                        </span>
                                        {line.corrected && (
                                            <span
                                                title="A reading was corrected after entry"
                                                className="inline-flex items-center gap-0.5 text-xs text-amber-700"
                                            >
                                                <PencilLine
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                                Corrected
                                            </span>
                                        )}
                                        {line.meterChanged && (
                                            <span
                                                title="An approved meter replacement or reset"
                                                className="inline-flex items-center gap-0.5 text-xs text-muted-foreground"
                                            >
                                                <RefreshCw
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                                Meter changed
                                            </span>
                                        )}
                                    </span>
                                </Td>
                                <Td right mono>
                                    {reading(line.opening)}
                                </Td>
                                <Td right mono>
                                    {line.closing === null ? (
                                        <span className="text-muted-foreground">
                                            not read
                                        </span>
                                    ) : (
                                        reading(line.closing)
                                    )}
                                </Td>
                                <Td right>
                                    {line.metered === null
                                        ? "—"
                                        : `${litres(line.metered)} L`}
                                </Td>
                                <Td right>{litres(line.sold)} L</Td>
                                <Td right>
                                    {Number(line.tested) > 0
                                        ? `${litres(line.tested)} L`
                                        : "—"}
                                </Td>
                                <Td right>
                                    <Variance milli={line.varianceMilli} />
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}

/** Unexplained litres with an icon and sign, never colour alone. */
function Variance({ milli }: { milli: number | null }) {
    if (milli === null) {
        return <span className="text-muted-foreground">—</span>;
    }
    if (milli === 0) {
        return (
            <span className="inline-flex items-center gap-1 text-emerald-700">
                <CircleCheck className="size-3.5" aria-hidden />
                None
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1 font-medium text-red-700 tabular-nums">
            <AlertTriangle className="size-3.5" aria-hidden />
            {signedLitres(milli)}
        </span>
    );
}

function Pulse({
    icon: Icon,
    label,
    value,
    note,
    warn,
    bad,
    good,
}: {
    icon: typeof Gauge;
    label: string;
    value: string;
    note?: string;
    warn?: boolean;
    bad?: boolean;
    good?: boolean;
}) {
    const tone = bad
        ? "text-red-700"
        : warn
          ? "text-amber-700"
          : good
            ? "text-emerald-700"
            : "text-muted-foreground";

    return (
        <div className="min-w-0 px-3 py-2.5">
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon
                    className={cn(
                        "size-3.5 shrink-0",
                        (bad || warn || good) && tone,
                    )}
                    aria-hidden
                />
                <span className="truncate">{label}</span>
            </p>
            <p className="mt-0.5 truncate text-lg font-semibold tabular-nums">
                {value}
            </p>
            {note && <p className={cn("truncate text-xs", tone)}>{note}</p>}
        </div>
    );
}

function TipRow({
    label,
    value,
    color,
    strong,
}: {
    label: string;
    value: string;
    color?: string;
    strong?: boolean;
}) {
    return (
        <p
            className={cn(
                "flex items-center justify-between gap-4 tabular-nums",
                strong && "mt-1 border-t pt-1 font-semibold",
            )}
        >
            <span className="flex items-center gap-1.5 font-normal text-muted-foreground">
                {color && (
                    <span
                        className="size-2 rounded-full"
                        style={{ background: color }}
                        aria-hidden
                    />
                )}
                {label}
            </span>
            {value}
        </p>
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
    children,
}: {
    href: string | null;
    children: ReactNode;
}) {
    return href ? (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} preserveScroll>
                {children}
            </Link>
        </Button>
    ) : (
        <Button variant="outline" size="sm" disabled>
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

function Td({
    children,
    right,
    mono,
}: {
    children: ReactNode;
    right?: boolean;
    mono?: boolean;
}) {
    return (
        <td
            className={cn(
                "px-3 py-2 whitespace-nowrap",
                right && "text-right tabular-nums",
                mono && "font-mono text-xs",
            )}
        >
            {children}
        </td>
    );
}

/** "1,234.5" from a litres string or number. */
function litres(value: string | number): string {
    return Number(value).toLocaleString(undefined, {
        maximumFractionDigits: 1,
    });
}

function signedLitres(milli: number): string {
    return `${milli > 0 ? "+" : ""}${litres(milli / 1000)} L`;
}

/** A totalizer value with its three decimals. */
function reading(value: string): string {
    return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
}

function longDate(date: string): string {
    const [y, m, d] = date.split("-").map(Number);

    return new Date(y, m - 1, d).toLocaleDateString([], {
        weekday: "short",
        day: "numeric",
        month: "short",
    });
}

/** "14:05" today, "3 Nov 14:05" another day. */
function when(iso: string): string {
    const at = new Date(iso);
    const time = at.toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
    });

    return at.toDateString() === new Date().toDateString()
        ? time
        : `${at.toLocaleDateString([], { day: "numeric", month: "short" })} ${time}`;
}
