import type { ReactNode } from 'react';

/**
 * What every dashboard chart shares (Recharts): solid colours from the
 * Logbook theme, a recessive grid and axes, and one tooltip look. Text
 * wears text colours; a coloured mark beside it carries the identity.
 */
export const CHART = {
    /** One series: Logbook's teal, a darker step on hover. */
    series: '#12a083',
    seriesHover: '#0b7259',
    /** The light teal under the line of an area chart (a solid colour, no transparency). */
    seriesFill: '#dff3ec',
    grid: '#eef1f5',
    axis: '#c9d2dc',
    tick: '#5b6776',
    cursor: '#f3f5f8',
};

/** Status colours (good, warning, critical, neutral): always shown with a label. */
export const STATUS = {
    good: '#0ca30c',
    warning: '#fab219',
    serious: '#ec835a',
    critical: '#d03b3b',
    neutral: '#94a3b8',
};

export const tickStyle = { fill: CHART.tick, fontSize: 12 };

/** The tooltip card: the label, then each value with its colour key. */
export function ChartTooltip({
    active,
    label,
    rows,
}: {
    active?: boolean;
    label?: ReactNode;
    rows: { name: string; value: ReactNode; colour: string }[];
}) {
    if (!active || rows.length === 0) {
        return null;
    }

    return (
        <div className="rounded-md border bg-card px-3 py-2 text-xs shadow-sm">
            {label !== undefined && (
                <p className="mb-1 font-medium text-foreground">{label}</p>
            )}
            <ul className="grid gap-0.5">
                {rows.map((row) => (
                    <li key={row.name} className="flex items-center gap-2">
                        <span
                            className="inline-block size-2.5 rounded-sm"
                            style={{ backgroundColor: row.colour }}
                            aria-hidden
                        />
                        <span className="text-muted-foreground">
                            {row.name}
                        </span>
                        <span className="ml-auto pl-3 font-medium text-foreground tabular-nums">
                            {row.value}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** A legend item: the colour key and its label, optionally with a value. */
export function LegendItem({
    colour,
    label,
    value,
}: {
    colour: string;
    label: string;
    value?: ReactNode;
}) {
    return (
        <li className="flex items-center gap-2 text-sm">
            <span
                className="inline-block size-3 shrink-0 rounded-sm"
                style={{ backgroundColor: colour }}
                aria-hidden
            />
            <span className="text-muted-foreground">{label}</span>
            {value !== undefined && (
                <span className="ml-auto font-medium tabular-nums">
                    {value}
                </span>
            )}
        </li>
    );
}

/** The figures behind a chart, for screen readers. */
export function DataTable({
    caption,
    columns,
    rows,
}: {
    caption: string;
    columns: [string, string];
    rows: [string, ReactNode][];
}) {
    return (
        <table className="sr-only" aria-label={caption}>
            <thead>
                <tr>
                    <th scope="col">{columns[0]}</th>
                    <th scope="col">{columns[1]}</th>
                </tr>
            </thead>
            <tbody>
                {rows.map(([name, value]) => (
                    <tr key={name}>
                        <th scope="row">{name}</th>
                        <td>{value}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** "14 Nov". */
export function shortDate(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
    });
}
