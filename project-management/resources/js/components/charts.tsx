import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { cn } from '@miles/ui/lib/utils';
import { BarChart3, Table2 } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { useState } from 'react';

/*
 * The product's charts, in plain HTML: thin marks, recessive grid, one
 * tooltip per mark on hover and on keyboard focus, and every value also in
 * a table. Colours come from the --viz-* tokens in app.css.
 */

const ROUND_TOP: CSSProperties = { borderRadius: '4px 4px 0 0' };
const ROUND_ALL: CSSProperties = { borderRadius: 4 };

/** Clean axis steps: the top tick and the ticks up to it, integers only. */
function axisTicks(max: number): number[] {
    if (max <= 0) {
        return [0, 1];
    }

    const raw = max / 4;
    const power = 10 ** Math.floor(Math.log10(raw));
    const step = Math.max(
        1,
        ([1, 2, 2.5, 5, 10].find(
            (candidate) =>
                candidate * power >= raw && Number.isInteger(candidate * power),
        ) ?? 10) * power,
    );
    const count = Math.ceil(max / step);

    return Array.from({ length: count + 1 }, (_, index) => index * step);
}

function Tooltip({
    value,
    label,
    className,
    style,
}: {
    value: ReactNode;
    label: ReactNode;
    className?: string;
    style?: CSSProperties;
}) {
    return (
        <div
            aria-hidden
            style={style}
            className={cn(
                'pointer-events-none absolute z-10 hidden border bg-popover px-2 py-1 whitespace-nowrap text-popover-foreground group-hover:block group-focus-visible:block',
                className,
            )}
        >
            <div className="text-sm font-semibold tabular-nums">{value}</div>
            <div className="text-xs font-normal text-muted-foreground">
                {label}
            </div>
        </div>
    );
}

/**
 * A panel holding a chart, with its numbers a click away as a table.
 */
export function ChartPanel({
    title,
    description,
    columns,
    rows,
    empty,
    className,
    children,
}: {
    title: string;
    description?: ReactNode;
    /** The table's column headings: the first names the row. */
    columns: [string, string, ...string[]];
    rows: ReactNode[][];
    /** Shown instead of the chart when there is nothing to plot. */
    empty?: string | null;
    className?: string;
    children: ReactNode;
}) {
    const [asTable, setAsTable] = useState(false);
    const Icon = asTable ? BarChart3 : Table2;

    return (
        <Panel className={cn('flex flex-col overflow-visible', className)}>
            <PanelHeader
                title={title}
                description={description}
                actions={
                    !empty && (
                        <button
                            type="button"
                            onClick={() => setAsTable(!asTable)}
                            className="inline-flex items-center gap-1 px-1.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <Icon className="size-3.5" aria-hidden />
                            {asTable ? 'Chart' : 'Table'}
                        </button>
                    )
                }
            />
            {empty ? (
                <p className="flex flex-1 items-center justify-center p-6 text-center text-sm text-muted-foreground">
                    {empty}
                </p>
            ) : asTable ? (
                <div className="max-h-72 flex-1 overflow-auto">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-card">
                            <tr className="border-b text-xs text-muted-foreground">
                                {columns.map((column, index) => (
                                    <th
                                        key={column}
                                        scope="col"
                                        className={cn(
                                            'px-3 py-1.5 font-medium',
                                            index === 0
                                                ? 'text-left'
                                                : 'text-right',
                                        )}
                                    >
                                        {column}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.map((row, rowIndex) => (
                                <tr key={rowIndex}>
                                    {row.map((cell, index) => (
                                        <td
                                            key={index}
                                            className={cn(
                                                'px-3 py-1.5',
                                                index > 0 &&
                                                    'text-right tabular-nums',
                                            )}
                                        >
                                            {cell}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="flex-1 p-3">{children}</div>
            )}
        </Panel>
    );
}

export type Column = {
    key: string;
    /** On the axis: short. */
    label: string;
    /** In the tooltip: in full. */
    title: string;
    value: number;
    valueText: string;
};

/**
 * One measure over time, as columns. Only the peak and the latest carry their
 * value on the cap; the axis and the tooltip give the rest.
 */
export function ColumnChart({
    data,
    label,
    axis = (value) => value.toLocaleString(),
}: {
    data: Column[];
    label: string;
    axis?: (value: number) => string;
}) {
    const ticks = axisTicks(Math.max(...data.map((item) => item.value)));
    const top = ticks[ticks.length - 1];
    const peak = data.reduce(
        (best, item, index) => (item.value > data[best].value ? index : best),
        0,
    );

    return (
        <div role="group" aria-label={label}>
            <div className="flex">
                <div
                    aria-hidden
                    className="relative mt-5 h-44 w-11 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                >
                    {ticks.map((tick) => (
                        <span
                            key={tick}
                            className="absolute right-2 translate-y-1/2"
                            style={{ bottom: `${(tick / top) * 100}%` }}
                        >
                            {axis(tick)}
                        </span>
                    ))}
                </div>
                <div className="relative mt-5 h-44 min-w-0 flex-1 border-b border-[var(--viz-baseline)]">
                    {ticks.slice(1).map((tick) => (
                        <div
                            key={tick}
                            aria-hidden
                            className="absolute inset-x-0 border-t border-[var(--viz-grid)]"
                            style={{ bottom: `${(tick / top) * 100}%` }}
                        />
                    ))}
                    <ul className="absolute inset-0 flex">
                        {data.map((item, index) => {
                            const height = (item.value / top) * 100;

                            return (
                                <li
                                    key={item.key}
                                    tabIndex={0}
                                    aria-label={`${item.title}: ${item.valueText}`}
                                    className="group relative flex h-full min-w-0 flex-1 items-end justify-center px-px hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
                                >
                                    {item.value > 0 && (
                                        <div
                                            className="relative w-full max-w-6 bg-primary transition-opacity group-hover:opacity-85"
                                            style={{
                                                ...ROUND_TOP,
                                                height: `max(${height}%, 2px)`,
                                            }}
                                        >
                                            {(index === peak ||
                                                index === data.length - 1) && (
                                                <span
                                                    aria-hidden
                                                    className={cn(
                                                        'absolute bottom-full mb-0.5 text-xs font-medium whitespace-nowrap tabular-nums group-hover:invisible group-focus-visible:invisible',
                                                        index ===
                                                            data.length - 1
                                                            ? 'right-0'
                                                            : 'left-1/2 -translate-x-1/2',
                                                    )}
                                                >
                                                    {axis(item.value)}
                                                </span>
                                            )}
                                        </div>
                                    )}
                                    <Tooltip
                                        value={item.valueText}
                                        label={item.title}
                                        className={
                                            index < data.length / 2
                                                ? 'left-1/2'
                                                : 'right-1/2'
                                        }
                                        // Kept inside the plot, over a tall column.
                                        style={{
                                            bottom: `calc(${Math.min(height, 66)}% + 6px)`,
                                        }}
                                    />
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </div>
            <div aria-hidden className="mt-1 flex pl-11">
                {data.map((item, index) => (
                    <div
                        key={item.key}
                        className="relative h-4 min-w-0 flex-1 text-center text-xs text-muted-foreground"
                    >
                        {(data.length - 1 - index) % 3 === 0 && (
                            <span className="absolute left-1/2 -translate-x-1/2 whitespace-nowrap">
                                {item.label}
                            </span>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}

export type Part = {
    key: string;
    label: string;
    value: number;
    /** A CSS colour for the block. */
    fill: string;
    /** A CSS colour for the number printed on the block. */
    ink: string;
    /** For a status: a colour is never its only sign. */
    icon?: LucideIcon;
};

/**
 * One whole split into parts: a tall bar with each part's number on its block
 * where it fits, and a legend naming them in the same order.
 */
export function SegmentedBar({
    parts,
    label,
}: {
    parts: Part[];
    label: string;
}) {
    const total = parts.reduce((sum, part) => sum + part.value, 0);
    const shown = parts.filter((part) => part.value > 0);
    let before = 0;

    return (
        <div>
            <ul aria-label={label} className="flex h-7 gap-0.5">
                {shown.map((part) => {
                    const share = (part.value / total) * 100;
                    const middle = before + share / 2;
                    before += share;

                    return (
                        <li
                            key={part.key}
                            tabIndex={0}
                            aria-label={`${part.label}: ${part.value}, ${Math.round(share)}%`}
                            className="group relative flex min-w-1 items-center px-1.5 text-xs font-medium tabular-nums transition-opacity hover:opacity-85 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            style={{
                                ...ROUND_ALL,
                                flexGrow: part.value,
                                flexBasis: 0,
                                background: part.fill,
                                color: part.ink,
                            }}
                        >
                            {share >= 12 && (
                                <span aria-hidden>{part.value}</span>
                            )}
                            <Tooltip
                                value={`${part.value} · ${Math.round(share)}%`}
                                label={part.label}
                                className={cn(
                                    'top-full mt-1',
                                    middle < 50 ? 'left-0' : 'right-0',
                                )}
                            />
                        </li>
                    );
                })}
            </ul>
            <ul className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
                {parts.map((part) => {
                    const Icon = part.icon;

                    return (
                        <li key={part.key} className="flex items-center gap-1">
                            {Icon ? (
                                <Icon
                                    className="size-3.5"
                                    style={{ color: part.fill }}
                                    aria-hidden
                                />
                            ) : (
                                <span
                                    aria-hidden
                                    className="size-2.5"
                                    style={{
                                        borderRadius: 2,
                                        background: part.fill,
                                    }}
                                />
                            )}
                            {part.label}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

/**
 * How much of a whole is used. The fill carries the severity; past the whole
 * it stays full.
 */
export function Meter({
    percent,
    label,
    fill = 'var(--primary)',
}: {
    percent: number;
    label: string;
    fill?: string;
}) {
    return (
        <div
            role="meter"
            aria-label={label}
            aria-valuenow={percent}
            aria-valuemin={0}
            aria-valuemax={Math.max(100, percent)}
            className="h-2 overflow-hidden rounded-full bg-muted"
        >
            <div
                className="h-full rounded-full"
                style={{
                    width: `${Math.min(100, Math.max(0, percent))}%`,
                    background: fill,
                }}
            />
        </div>
    );
}
