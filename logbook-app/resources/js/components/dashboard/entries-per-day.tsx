import { ArrowDown, ArrowUp } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    CHART,
    ChartTooltip,
    DataTable,
    LegendItem,
    shortDate,
    tickStyle,
} from '@/components/dashboard/chart-kit';
import type { Charts } from '@/components/dashboard/types';

/** The earlier week: a light step of the same teal (solid, no transparency). */
const EARLIER = '#a7dccb';

function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <dt className="truncate text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-lg leading-7 font-semibold tabular-nums">
                {children}
            </dd>
        </div>
    );
}

/**
 * Submitted entries per day over two weeks: the figures that matter on
 * top, then one bar a day, this week in teal against the week before in
 * a light teal, with the daily average as a dashed line.
 */
export function EntriesPerDayChart({ data }: { data: Charts['perDay'] }) {
    const total = data.reduce((sum, d) => sum + d.entries, 0);
    const average = data.length > 0 ? total / data.length : 0;
    const split = Math.max(0, data.length - 7);
    const thisWeek = data.slice(split).reduce((s, d) => s + d.entries, 0);
    const lastWeek = data.slice(0, split).reduce((s, d) => s + d.entries, 0);
    const busiest = data.reduce<Charts['perDay'][number] | null>(
        (best, d) => (best === null || d.entries > best.entries ? d : best),
        null,
    );
    const change =
        lastWeek > 0
            ? Math.round(((thisWeek - lastWeek) / lastWeek) * 100)
            : null;
    const today = data.at(-1)?.date;
    const rows = data.map((d, i) => ({ ...d, recent: i >= split }));

    return (
        <figure className="@container">
            <dl className="grid grid-cols-2 gap-x-6 gap-y-3 @lg:grid-cols-4">
                <Figure label="Total">{total}</Figure>
                <Figure label="Daily average">
                    {average.toFixed(average < 10 ? 1 : 0)}
                </Figure>
                <Figure label="Busiest day">
                    {busiest && busiest.entries > 0 ? (
                        <>
                            {busiest.entries}
                            <span className="ml-1.5 text-xs font-normal whitespace-nowrap text-muted-foreground">
                                {shortDate(busiest.date)}
                            </span>
                        </>
                    ) : (
                        '—'
                    )}
                </Figure>
                <Figure label="This week vs last">
                    {change === null ? (
                        '—'
                    ) : (
                        <span
                            className={`inline-flex items-center gap-0.5 ${
                                change > 0
                                    ? 'text-[#137a4b]'
                                    : change < 0
                                      ? 'text-[#c01c1c]'
                                      : ''
                            }`}
                        >
                            {change > 0 && (
                                <ArrowUp className="size-4" aria-hidden />
                            )}
                            {change < 0 && (
                                <ArrowDown className="size-4" aria-hidden />
                            )}
                            {change > 0 ? '+' : ''}
                            {change}%
                        </span>
                    )}
                </Figure>
            </dl>

            <ul className="mt-5 mb-1 flex justify-end gap-4">
                <LegendItem colour={EARLIER} label="Previous 7 days" />
                <LegendItem colour={CHART.series} label="Last 7 days" />
            </ul>
            <div className="h-56">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={rows}
                        margin={{ top: 8, right: 4, bottom: 0, left: -16 }}
                        barCategoryGap="22%"
                    >
                        <CartesianGrid stroke={CHART.grid} vertical={false} />
                        <XAxis
                            dataKey="date"
                            tickFormatter={shortDate}
                            tick={tickStyle}
                            axisLine={{ stroke: CHART.axis }}
                            tickLine={false}
                            minTickGap={16}
                        />
                        <YAxis
                            allowDecimals={false}
                            tick={tickStyle}
                            axisLine={false}
                            tickLine={false}
                            width={40}
                        />
                        <Tooltip
                            cursor={{ fill: CHART.cursor }}
                            content={({ active, payload, label }) => (
                                <ChartTooltip
                                    active={active}
                                    label={
                                        typeof label === 'string'
                                            ? `${new Date(
                                                  `${label}T12:00:00`,
                                              ).toLocaleDateString(undefined, {
                                                  weekday: 'short',
                                                  day: 'numeric',
                                                  month: 'short',
                                              })}${label === today ? ' (today, so far)' : ''}`
                                            : undefined
                                    }
                                    rows={(payload ?? []).map((p) => ({
                                        name: 'Entries',
                                        value: String(p.value ?? 0),
                                        colour: (
                                            p.payload as { recent: boolean }
                                        ).recent
                                            ? CHART.series
                                            : EARLIER,
                                    }))}
                                />
                            )}
                        />
                        {average > 0 && (
                            <ReferenceLine
                                y={average}
                                stroke={CHART.tick}
                                strokeDasharray="4 4"
                                strokeWidth={1}
                                label={{
                                    value: `avg ${average.toFixed(1)}`,
                                    position: 'insideTopRight',
                                    fill: CHART.tick,
                                    fontSize: 11,
                                }}
                            />
                        )}
                        <Bar
                            dataKey="entries"
                            radius={[4, 4, 0, 0]}
                            maxBarSize={32}
                            isAnimationActive={false}
                        >
                            {rows.map((r) => (
                                <Cell
                                    key={r.date}
                                    fill={r.recent ? CHART.series : EARLIER}
                                    className={
                                        r.recent
                                            ? 'hover:fill-[#0b7259]'
                                            : 'hover:fill-[#86cdb7]'
                                    }
                                />
                            ))}
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <DataTable
                caption={`Entries submitted per day: ${total} in ${data.length} days`}
                columns={['Day', 'Entries']}
                rows={data.map((d) => [shortDate(d.date), d.entries])}
            />
        </figure>
    );
}
