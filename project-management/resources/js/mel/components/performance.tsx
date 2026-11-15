import { Link } from '@inertiajs/react';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { cn } from '@miles/ui/lib/utils';
import { ChartPanel } from '@/components/charts';
import {
    PERFORMANCE,
    formatFigure,
    formatNumber,
    formatTarget,
} from '@/mel/lib/mel';
import type { Actual, Assessment, Cell, PerformanceRow } from '@/mel/lib/mel';

export function PerformanceBadge({ assessment }: { assessment: Assessment }) {
    const status = PERFORMANCE[assessment.status];

    return <StatusBadge tone={status.tone}>{status.label}</StatusBadge>;
}

/**
 * An actual as reported, with how many units it covers when not all.
 */
export function ActualFigure({
    actual,
    row,
}: {
    actual: Actual | null;
    row: PerformanceRow;
}) {
    if (actual === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    if (actual.mixedVersions) {
        return (
            <span
                className="text-xs text-muted-foreground"
                title="Units reported under different versions of the definition, so they are not combined."
            >
                Not combined (definition changed)
            </span>
        );
    }

    return (
        <span className="tabular-nums">
            {actual.value === null ? (
                <span
                    className="text-xs text-muted-foreground"
                    title="The indicator's rules do not combine units, or a part is missing."
                >
                    Not combined
                </span>
            ) : (
                formatFigure(actual.value, row.indicator)
            )}
            {actual.reported < actual.expected && (
                <span
                    className="ml-1 text-xs text-muted-foreground"
                    title="Units with a verified result, of those expected"
                >
                    ({actual.reported} of {actual.expected})
                </span>
            )}
        </span>
    );
}

function numeric(value: string | null | undefined): number | null {
    return value === null || value === undefined ? null : Number(value);
}

/**
 * One indicator over its periods: actual as columns, target as a mark,
 * with every figure in the table view.
 */
export function PerformanceTrend({
    row,
    href,
}: {
    row: PerformanceRow;
    href?: string;
}) {
    const cells = row.periods;
    const values = cells.flatMap((cell) =>
        [
            numeric(cell.actual?.value),
            numeric(cell.target?.value ?? cell.target?.max),
        ].filter((value): value is number => value !== null),
    );
    const top = Math.max(...values, 0) * 1.1 || 1;
    const title = (
        <>
            {href ? (
                <Link href={href} className="hover:underline">
                    {row.indicator.code}
                </Link>
            ) : (
                row.indicator.code
            )}{' '}
            · {row.indicator.name}
        </>
    );

    return (
        <ChartPanel
            title={`${row.indicator.code} · ${row.indicator.name}`}
            description={
                row.baseline
                    ? `Baseline ${formatFigure(row.baseline.value, row.indicator)}`
                    : 'No baseline'
            }
            columns={['Period', 'Target', 'Actual', '% of target', 'Status']}
            rows={cells.map((cell) => [
                cell.period,
                formatTarget(cell.target, row.indicator),
                cell.actual?.value
                    ? formatFigure(cell.actual.value, row.indicator)
                    : '—',
                cell.assessment.achievement === null
                    ? '—'
                    : `${formatNumber(cell.assessment.achievement, 0)}%`,
                PERFORMANCE[cell.assessment.status].label,
            ])}
            empty={cells.length === 0 ? 'No periods yet.' : null}
        >
            <span className="sr-only">{title}</span>
            <div className="flex h-40 items-end gap-1 border-b border-[var(--viz-baseline)]">
                {cells.map((cell) => (
                    <TrendColumn
                        key={cell.periodId}
                        cell={cell}
                        row={row}
                        top={top}
                    />
                ))}
            </div>
            <div aria-hidden className="mt-1 flex gap-1">
                {cells.map((cell, index) => (
                    <div
                        key={cell.periodId}
                        className="min-w-0 flex-1 truncate text-center text-xs text-muted-foreground"
                    >
                        {(cells.length - 1 - index) % 2 === 0
                            ? cell.period
                            : ''}
                    </div>
                ))}
            </div>
            <p className="mt-2 flex gap-3 text-xs text-muted-foreground">
                <span className="flex items-center gap-1">
                    <span
                        aria-hidden
                        className="size-2.5 rounded-sm bg-primary"
                    />
                    Actual
                </span>
                <span className="flex items-center gap-1">
                    <span
                        aria-hidden
                        className="h-0.5 w-3 bg-[var(--viz-critical)]"
                    />
                    Target
                </span>
            </p>
        </ChartPanel>
    );
}

function TrendColumn({
    cell,
    row,
    top,
}: {
    cell: Cell;
    row: PerformanceRow;
    top: number;
}) {
    const actual = numeric(cell.actual?.value);
    const target = numeric(cell.target?.value);
    const min = numeric(cell.target?.min);
    const max = numeric(cell.target?.max);
    const label = `${cell.period}: actual ${cell.actual?.value ? formatFigure(cell.actual.value, row.indicator) : 'none'}, target ${formatTarget(cell.target, row.indicator)}, ${PERFORMANCE[cell.assessment.status].label}`;

    return (
        <div
            tabIndex={0}
            aria-label={label}
            title={label}
            className="group relative flex h-full min-w-0 flex-1 items-end justify-center hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
        >
            {min !== null && max !== null && (
                <div
                    aria-hidden
                    className="absolute inset-x-0 bg-[var(--viz-grid)]"
                    style={{
                        bottom: `${(min / top) * 100}%`,
                        height: `${((max - min) / top) * 100}%`,
                    }}
                />
            )}
            {actual !== null && actual > 0 && (
                <div
                    className={cn(
                        'relative w-full max-w-6 bg-primary',
                        cell.assessment.status === 'off_track' && 'opacity-60',
                    )}
                    style={{
                        borderRadius: '4px 4px 0 0',
                        height: `max(${(actual / top) * 100}%, 2px)`,
                    }}
                />
            )}
            {target !== null && (
                <div
                    aria-hidden
                    className="absolute inset-x-0.5 h-0.5 bg-[var(--viz-critical)]"
                    style={{ bottom: `${(target / top) * 100}%` }}
                />
            )}
        </div>
    );
}
