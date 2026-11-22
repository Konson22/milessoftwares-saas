import type { ReactNode } from 'react';
import { DataTable } from '@/components/dashboard/chart-kit';

/** The track behind a bar: a solid light grey, no transparency. */
const TRACK = '#eef1f5';

export type BarListRow = {
    label: string;
    /** One segment, or several stacked (e.g. on schedule + overdue). */
    segments: { name: string; value: number; colour: string }[];
};

/**
 * Horizontal bars as plain HTML: the label and value on a line, the bar
 * underneath. Labels wrap or truncate with the card instead of running
 * into each other, at any width.
 */
export function BarList({
    rows,
    caption,
    empty,
    legend,
}: {
    rows: BarListRow[];
    caption: string;
    empty?: ReactNode;
    legend?: ReactNode;
}) {
    const totals = rows.map((r) => r.segments.reduce((s, x) => s + x.value, 0));
    const max = Math.max(1, ...totals);

    if (empty && totals.every((t) => t === 0)) {
        return (
            <p className="flex h-32 items-center justify-center text-center text-sm text-muted-foreground">
                {empty}
            </p>
        );
    }

    return (
        <figure>
            {legend && (
                <ul className="mb-3 flex flex-wrap justify-end gap-x-4 gap-y-1">
                    {legend}
                </ul>
            )}
            <ul className="grid gap-3.5">
                {rows.map((row, i) => {
                    const total = totals[i];
                    const tip = row.segments
                        .map((s) => `${s.name}: ${s.value}`)
                        .join(' · ');

                    return (
                        <li key={row.label} title={`${row.label} — ${tip}`}>
                            <div className="flex items-baseline justify-between gap-3 text-sm">
                                <span className="min-w-0 truncate text-muted-foreground">
                                    {row.label}
                                </span>
                                <span className="shrink-0 font-medium tabular-nums">
                                    {total}
                                </span>
                            </div>
                            <div
                                className="mt-1.5 flex h-2 overflow-hidden rounded-full"
                                style={{ backgroundColor: TRACK }}
                                aria-hidden
                            >
                                <div
                                    className="flex h-full gap-0.5 overflow-hidden rounded-full"
                                    style={{
                                        width: `${(total / max) * 100}%`,
                                        minWidth: total > 0 ? 4 : 0,
                                    }}
                                >
                                    {row.segments
                                        .filter((s) => s.value > 0)
                                        .map((s) => (
                                            <span
                                                key={s.name}
                                                className="h-full"
                                                style={{
                                                    flexGrow: s.value,
                                                    backgroundColor: s.colour,
                                                }}
                                            />
                                        ))}
                                </div>
                            </div>
                        </li>
                    );
                })}
            </ul>
            <DataTable
                caption={caption}
                columns={['', 'Count']}
                rows={rows.map((r, i) => [r.label, totals[i]])}
            />
        </figure>
    );
}

/** One coloured bar per row: the common case. */
export function simpleRows(
    rows: { label: string; value: number; colour: string }[],
): BarListRow[] {
    return rows.map((r) => ({
        label: r.label,
        segments: [{ name: r.label, value: r.value, colour: r.colour }],
    }));
}
