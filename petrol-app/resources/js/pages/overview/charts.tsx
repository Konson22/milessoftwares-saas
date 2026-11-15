import { useState, type ReactNode } from "react";
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from "recharts";
import { cn } from "@miles/ui/lib/utils";

/**
 * The overview's charts. Colours come from the --viz-* tokens in app.css:
 * fuel grades take slots in name order, so a grade keeps its colour on
 * every chart, and text never wears a series colour.
 */

const SLOTS = ["var(--viz-1)", "var(--viz-2)", "var(--viz-3)", "var(--viz-4)"];
const OTHER = "var(--viz-axis)";

export type Day = {
    date: string;
    totalMinor: number;
    litres: string;
    grades?: { name: string; totalMinor: number }[];
};

export type Hour = { hour: number; totalMinor: number; count: number };

/** A colour per grade name, fixed by name order (never by rank). */
export function gradeColors(names: string[]): Record<string, string> {
    const sorted = [...new Set(names)].sort((a, b) => a.localeCompare(b));

    return Object.fromEntries(
        sorted.map((name, i) => [name, SLOTS[i] ?? OTHER]),
    );
}

/** "3 Nov" from "2026-11-03", read as a calendar date (no time zone shift). */
export function shortDate(date: string): string {
    const [y, m, d] = date.split("-").map(Number);

    return new Date(y, m - 1, d).toLocaleDateString([], {
        day: "numeric",
        month: "short",
    });
}

/** Axis ticks: 0 / 500K / 1.2M in major units. */
function compact(minor: number): string {
    return new Intl.NumberFormat(undefined, {
        notation: "compact",
        maximumFractionDigits: 1,
    }).format(minor / 100);
}

const axis = {
    stroke: "var(--viz-grid)",
    tick: { fill: "var(--viz-axis)", fontSize: 11 },
    tickLine: false,
} as const;

function TooltipBox({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <div className="min-w-44 border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md">
            <p className="mb-1 font-semibold">{title}</p>
            {children}
        </div>
    );
}

function TooltipRow({
    color,
    label,
    value,
    strong,
}: {
    color?: string;
    label: string;
    value: string;
    strong?: boolean;
}) {
    return (
        <p
            className={cn(
                "flex items-center justify-between gap-4 tabular-nums",
                strong && "mt-1 border-t pt-1 font-semibold",
            )}
        >
            <span className="flex items-center gap-1.5 text-muted-foreground">
                {color && (
                    <span
                        className="size-2 rounded-full"
                        style={{ background: color }}
                        aria-hidden
                    />
                )}
                {label}
            </span>
            <span>{value}</span>
        </p>
    );
}

export function Legend({
    items,
}: {
    items: { name: string; color: string }[];
}) {
    return (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {items.map((item) => (
                <li key={item.name} className="flex items-center gap-1.5">
                    <span
                        className="size-2.5 rounded-sm"
                        style={{ background: item.color }}
                        aria-hidden
                    />
                    {item.name}
                </li>
            ))}
        </ul>
    );
}

/** Chart or table: every chart has a table view for exact figures. */
export function ViewToggle({
    table,
    onChange,
}: {
    table: boolean;
    onChange: (table: boolean) => void;
}) {
    return (
        <div className="flex border text-xs" role="group" aria-label="View">
            {(["Chart", "Table"] as const).map((label) => {
                const on = (label === "Table") === table;

                return (
                    <button
                        key={label}
                        type="button"
                        aria-pressed={on}
                        onClick={() => onChange(label === "Table")}
                        className={cn(
                            "px-2 py-1 font-medium",
                            on
                                ? "bg-foreground text-background"
                                : "text-muted-foreground hover:bg-muted",
                        )}
                    >
                        {label}
                    </button>
                );
            })}
        </div>
    );
}

/** Daily sales, stacked by fuel grade. */
export function SalesTrend({
    series,
    colors,
    money,
    from,
}: {
    series: Day[];
    colors: Record<string, string>;
    money: (minor: number) => string;
    from: string;
}) {
    const [table, setTable] = useState(false);
    const names = Object.keys(colors);
    const rows = series.map((day) => ({
        ...day,
        inRange: day.date >= from,
        ...Object.fromEntries(
            (day.grades ?? []).map((grade) => [grade.name, grade.totalMinor]),
        ),
    }));
    const empty = series.every((day) => day.totalMinor === 0);
    const days = series.filter((day) => day.totalMinor > 0);
    const average = days.length
        ? Math.round(
              days.reduce((sum, day) => sum + day.totalMinor, 0) / days.length,
          )
        : 0;

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <Legend
                    items={names.map((name) => ({ name, color: colors[name] }))}
                />
                <div className="flex items-center gap-3">
                    {!empty && (
                        <span className="text-xs text-muted-foreground tabular-nums">
                            Average day {money(average)}
                        </span>
                    )}
                    <ViewToggle table={table} onChange={setTable} />
                </div>
            </div>
            {empty ? (
                <Empty>No posted sales in these days.</Empty>
            ) : table ? (
                <div className="max-h-72 overflow-auto">
                    <table className="w-full text-right text-sm tabular-nums">
                        <thead className="sticky top-0 bg-card text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1.5 text-left font-medium">
                                    Day
                                </th>
                                {names.map((name) => (
                                    <th
                                        key={name}
                                        className="py-1.5 font-medium"
                                    >
                                        {name}
                                    </th>
                                ))}
                                <th className="py-1.5 font-medium">Litres</th>
                                <th className="py-1.5 font-medium">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {[...series].reverse().map((day) => (
                                <tr key={day.date} className="border-t">
                                    <td className="py-1.5 text-left">
                                        {shortDate(day.date)}
                                    </td>
                                    {names.map((name) => (
                                        <td key={name} className="py-1.5">
                                            {money(
                                                day.grades?.find(
                                                    (g) => g.name === name,
                                                )?.totalMinor ?? 0,
                                            )}
                                        </td>
                                    ))}
                                    <td className="py-1.5">{day.litres} L</td>
                                    <td className="py-1.5 font-medium">
                                        {money(day.totalMinor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div
                    className="h-72"
                    role="img"
                    aria-label={`Daily fuel sales by grade, ${shortDate(series[0].date)} to ${shortDate(series[series.length - 1].date)}`}
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
                                {...axis}
                            />
                            <YAxis
                                tickFormatter={compact}
                                width={44}
                                axisLine={false}
                                {...axis}
                            />
                            <Tooltip
                                cursor={{ fill: "var(--muted)", opacity: 0.6 }}
                                content={({ active, payload }) => {
                                    const day = payload?.[0]?.payload as
                                        | (Day & { inRange: boolean })
                                        | undefined;
                                    if (!active || !day) {
                                        return null;
                                    }

                                    return (
                                        <TooltipBox title={shortDate(day.date)}>
                                            {names.map((name) => (
                                                <TooltipRow
                                                    key={name}
                                                    color={colors[name]}
                                                    label={name}
                                                    value={money(
                                                        day.grades?.find(
                                                            (g) =>
                                                                g.name === name,
                                                        )?.totalMinor ?? 0,
                                                    )}
                                                />
                                            ))}
                                            <TooltipRow
                                                label={`Total · ${day.litres} L`}
                                                value={money(day.totalMinor)}
                                                strong
                                            />
                                        </TooltipBox>
                                    );
                                }}
                            />
                            {names.map((name, i) => (
                                <Bar
                                    key={name}
                                    dataKey={name}
                                    stackId="sales"
                                    fill={colors[name]}
                                    stroke="var(--viz-surface)"
                                    strokeWidth={1}
                                    maxBarSize={24}
                                    radius={
                                        i === names.length - 1
                                            ? [4, 4, 0, 0]
                                            : [0, 0, 0, 0]
                                    }
                                    isAnimationActive={false}
                                />
                            ))}
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            )}
        </div>
    );
}

/** Sales by hour of day across the range: one series, so no legend. */
export function BusyHours({
    hours,
    money,
}: {
    hours: Hour[];
    money: (minor: number) => string;
}) {
    const [table, setTable] = useState(false);
    const open = hours.filter((hour) => hour.count > 0);
    if (open.length === 0) {
        return <Empty>No posted sales in this range.</Empty>;
    }
    const first = open[0].hour;
    const last = open[open.length - 1].hour;
    const shown = hours.filter(
        (hour) => hour.hour >= first && hour.hour <= last,
    );
    const peak = shown.reduce((top, hour) =>
        hour.totalMinor > top.totalMinor ? hour : top,
    );
    const label = (hour: number) => `${String(hour).padStart(2, "0")}:00`;

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground">
                    Busiest{" "}
                    <span className="font-medium text-foreground">
                        {label(peak.hour)} to {label(peak.hour + 1)}
                    </span>{" "}
                    · {money(peak.totalMinor)} from {peak.count} sales
                </p>
                <ViewToggle table={table} onChange={setTable} />
            </div>
            {table ? (
                <div className="max-h-56 overflow-auto">
                    <table className="w-full text-right text-sm tabular-nums">
                        <thead className="sticky top-0 bg-card text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1.5 text-left font-medium">
                                    Hour
                                </th>
                                <th className="py-1.5 font-medium">Sales</th>
                                <th className="py-1.5 font-medium">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((hour) => (
                                <tr key={hour.hour} className="border-t">
                                    <td className="py-1.5 text-left">
                                        {label(hour.hour)}
                                    </td>
                                    <td className="py-1.5">{hour.count}</td>
                                    <td className="py-1.5">
                                        {money(hour.totalMinor)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div
                    className="h-56"
                    role="img"
                    aria-label={`Sales by hour; busiest ${label(peak.hour)}`}
                >
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart
                            data={shown}
                            margin={{ top: 8, right: 4, bottom: 0, left: 0 }}
                            barCategoryGap="18%"
                        >
                            <CartesianGrid
                                vertical={false}
                                stroke="var(--viz-grid)"
                            />
                            <XAxis
                                dataKey="hour"
                                tickFormatter={(hour: number) =>
                                    String(hour).padStart(2, "0")
                                }
                                interval={0}
                                {...axis}
                            />
                            <YAxis
                                tickFormatter={compact}
                                width={44}
                                axisLine={false}
                                {...axis}
                            />
                            <Tooltip
                                cursor={{ fill: "var(--muted)", opacity: 0.6 }}
                                content={({ active, payload }) => {
                                    const hour = payload?.[0]?.payload as
                                        Hour | undefined;
                                    if (!active || !hour) {
                                        return null;
                                    }

                                    return (
                                        <TooltipBox
                                            title={`${label(hour.hour)} to ${label(hour.hour + 1)}`}
                                        >
                                            <TooltipRow
                                                label="Sales"
                                                value={String(hour.count)}
                                            />
                                            <TooltipRow
                                                label="Amount"
                                                value={money(hour.totalMinor)}
                                                strong
                                            />
                                        </TooltipBox>
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

/** A 14-point trend under a stat tile; the last point is the accent. */
export function Sparkline({
    values,
    label,
}: {
    values: number[];
    label: string;
}) {
    if (values.length < 2 || values.every((value) => value === 0)) {
        return null;
    }
    const data = values.map((value, i) => ({ i, value }));

    return (
        <div className="mt-2 h-9" role="img" aria-label={label}>
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart
                    data={data}
                    margin={{ top: 4, right: 4, bottom: 2, left: 4 }}
                >
                    <Area
                        type="monotone"
                        dataKey="value"
                        stroke="var(--viz-1)"
                        strokeWidth={2}
                        fill="var(--viz-1)"
                        fillOpacity={0.1}
                        dot={false}
                        activeDot={false}
                        isAnimationActive={false}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}

/**
 * One whole split into parts (how sales were settled), with a legend
 * list carrying names and values.
 */
export function SegmentedBar({
    parts,
    money,
}: {
    parts: { name: string; totalMinor: number; color: string }[];
    money: (minor: number) => string;
}) {
    const total = parts.reduce((sum, part) => sum + part.totalMinor, 0);
    if (total === 0) {
        return <Empty>No payments in this range.</Empty>;
    }

    return (
        <div>
            <div
                className="flex h-3 gap-0.5"
                role="img"
                aria-label="Share of sales by payment method"
            >
                {parts
                    .filter((part) => part.totalMinor > 0)
                    .map((part, i, shown) => (
                        <div
                            key={part.name}
                            className={cn(
                                i === 0 && "rounded-l",
                                i === shown.length - 1 && "rounded-r",
                            )}
                            style={{
                                background: part.color,
                                width: `${(part.totalMinor * 100) / total}%`,
                            }}
                            title={`${part.name}: ${money(part.totalMinor)}`}
                        />
                    ))}
            </div>
            <ul className="mt-3 grid gap-2 text-sm">
                {parts.map((part) => (
                    <li
                        key={part.name}
                        className="flex items-center justify-between gap-3"
                    >
                        <span className="flex items-center gap-2">
                            <span
                                className="size-2.5 rounded-sm"
                                style={{ background: part.color }}
                                aria-hidden
                            />
                            {part.name}
                        </span>
                        <span className="flex items-baseline gap-2 tabular-nums">
                            <span className="text-xs text-muted-foreground">
                                {Math.round((part.totalMinor * 100) / total)}%
                            </span>
                            <span className="font-medium">
                                {money(part.totalMinor)}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="grid h-32 place-items-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}
