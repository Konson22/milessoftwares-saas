import {
    Bar,
    BarChart,
    CartesianGrid,
    LabelList,
    Line,
    LineChart,
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
    STATUS,
    shortDate,
    tickStyle,
} from '@/components/dashboard/chart-kit';
import { BarList } from '@/components/dashboard/bar-list';
import { HorizontalBars } from '@/components/dashboard/charts';

/** The Tasks page's chart data, as TaskController sends it. */
export type TaskCharts = {
    flow: { date: string; created: number; completed: number }[];
    due: { date: string | null; label: string | null; count: number }[];
    workload: { name: string; open: number; overdue: number }[];
    priority: { urgent: number; high: number; normal: number; low: number };
};

/** Two series, validated together for colour-blind readers. */
const CREATED = '#3b82c4';
const COMPLETED = CHART.series;

/**
 * Tasks created and completed per day over two weeks: two 2px lines,
 * a legend, a crosshair tooltip with both values.
 */
export function TaskFlowChart({ data }: { data: TaskCharts['flow'] }) {
    const created = data.reduce((s, d) => s + d.created, 0);
    const completed = data.reduce((s, d) => s + d.completed, 0);

    return (
        <figure>
            <ul className="mb-2 flex justify-end gap-4">
                <LegendItem colour={CREATED} label="Created" />
                <LegendItem colour={COMPLETED} label="Completed" />
            </ul>
            <div className="h-56">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart
                        data={data}
                        margin={{ top: 8, right: 12, bottom: 0, left: -12 }}
                    >
                        <CartesianGrid stroke={CHART.grid} vertical={false} />
                        <XAxis
                            dataKey="date"
                            tickFormatter={shortDate}
                            tick={tickStyle}
                            axisLine={{ stroke: CHART.axis }}
                            tickLine={false}
                            minTickGap={24}
                        />
                        <YAxis
                            allowDecimals={false}
                            tick={tickStyle}
                            axisLine={false}
                            tickLine={false}
                            width={40}
                        />
                        <Tooltip
                            cursor={{ stroke: CHART.axis, strokeWidth: 1 }}
                            content={({ active, payload, label }) => (
                                <ChartTooltip
                                    active={active}
                                    label={
                                        typeof label === 'string'
                                            ? shortDate(label)
                                            : undefined
                                    }
                                    rows={(payload ?? []).map((p) => ({
                                        name: String(p.name),
                                        value: String(p.value ?? 0),
                                        colour: String(p.color ?? CREATED),
                                    }))}
                                />
                            )}
                        />
                        <Line
                            type="monotone"
                            dataKey="created"
                            name="Created"
                            stroke={CREATED}
                            strokeWidth={2}
                            dot={false}
                            activeDot={{
                                r: 4,
                                stroke: '#ffffff',
                                strokeWidth: 2,
                            }}
                            isAnimationActive={false}
                        />
                        <Line
                            type="monotone"
                            dataKey="completed"
                            name="Completed"
                            stroke={COMPLETED}
                            strokeWidth={2}
                            dot={false}
                            activeDot={{
                                r: 4,
                                stroke: '#ffffff',
                                strokeWidth: 2,
                            }}
                            isAnimationActive={false}
                        />
                    </LineChart>
                </ResponsiveContainer>
            </div>
            <table
                className="sr-only"
                aria-label={`Tasks per day: ${created} created, ${completed} completed`}
            >
                <thead>
                    <tr>
                        <th scope="col">Day</th>
                        <th scope="col">Created</th>
                        <th scope="col">Completed</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((d) => (
                        <tr key={d.date}>
                            <th scope="row">{shortDate(d.date)}</th>
                            <td>{d.created}</td>
                            <td>{d.completed}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}

/** A day under its bar on two lines ("Fri" over "20"), so 7 fit a narrow card. */
function DayTick({
    x,
    y,
    payload,
}: {
    x?: number;
    y?: number;
    payload?: { value: string };
}) {
    const d = new Date(`${payload?.value ?? ''}T12:00:00`);

    return (
        <text x={x} y={y} textAnchor="middle" fill={CHART.tick} fontSize={12}>
            <tspan x={x} dy="0.9em">
                {d.toLocaleDateString(undefined, { weekday: 'short' })}
            </tspan>
            <tspan x={x} dy="1.25em">
                {d.getDate()}
            </tspan>
        </text>
    );
}

/**
 * Open tasks by due day over the next week, each bar with its count;
 * overdue work stands above the chart in the critical colour.
 */
export function DueSoonChart({ data }: { data: TaskCharts['due'] }) {
    const overdue = data.find((d) => d.date === null)?.count ?? 0;
    const days = data.flatMap((d) =>
        d.date ? [{ date: d.date, count: d.count }] : [],
    );

    return (
        <figure>
            <p className="mb-2 flex items-center gap-2 text-sm">
                <span
                    className="inline-block size-3 rounded-sm"
                    style={{ backgroundColor: STATUS.critical }}
                    aria-hidden
                />
                <span className="text-muted-foreground">Already overdue</span>
                <span className="ml-auto font-medium tabular-nums">
                    {overdue}
                </span>
            </p>
            <div className="h-56">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={days}
                        margin={{ top: 20, right: 4, bottom: 4, left: -16 }}
                        barCategoryGap="24%"
                    >
                        <CartesianGrid stroke={CHART.grid} vertical={false} />
                        <XAxis
                            dataKey="date"
                            tick={<DayTick />}
                            height={36}
                            axisLine={{ stroke: CHART.axis }}
                            tickLine={false}
                            interval={0}
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
                                            ? new Date(
                                                  `${label}T12:00:00`,
                                              ).toLocaleDateString(undefined, {
                                                  weekday: 'long',
                                                  day: 'numeric',
                                                  month: 'short',
                                              })
                                            : undefined
                                    }
                                    rows={(payload ?? []).map((p) => ({
                                        name: 'Open tasks due',
                                        value: String(p.value ?? 0),
                                        colour: CHART.series,
                                    }))}
                                />
                            )}
                        />
                        <Bar
                            dataKey="count"
                            fill={CHART.series}
                            activeBar={{ fill: CHART.seriesHover }}
                            radius={[4, 4, 0, 0]}
                            maxBarSize={36}
                            isAnimationActive={false}
                        >
                            <LabelList
                                dataKey="count"
                                position="top"
                                fill={CHART.tick}
                                fontSize={12}
                                formatter={(v: unknown) =>
                                    Number(v) > 0 ? String(v) : ''
                                }
                            />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <DataTable
                caption="Open tasks: overdue, then by due day over the next 7 days"
                columns={['Due', 'Tasks']}
                rows={[
                    ['Overdue', overdue],
                    ...days.map(
                        (d) => [shortDate(d.date), d.count] as [string, number],
                    ),
                ]}
            />
        </figure>
    );
}

/** Open work per person: on schedule and overdue stacked, with a legend. */
export function WorkloadChart({ data }: { data: TaskCharts['workload'] }) {
    return (
        <BarList
            caption="Open tasks per person"
            empty="No open tasks here."
            legend={
                <>
                    <LegendItem colour={CHART.series} label="On schedule" />
                    <LegendItem colour={STATUS.critical} label="Overdue" />
                </>
            }
            rows={data.map((d) => ({
                label: d.name,
                segments: [
                    {
                        name: 'On schedule',
                        value: d.open,
                        colour: CHART.series,
                    },
                    {
                        name: 'Overdue',
                        value: d.overdue,
                        colour: STATUS.critical,
                    },
                ],
            }))}
        />
    );
}

/** Open tasks by priority, in the priority's own colour. */
export function PriorityChart({ data }: { data: TaskCharts['priority'] }) {
    return (
        <HorizontalBars
            caption="Open tasks by priority"
            rows={[
                {
                    label: 'Urgent',
                    value: data.urgent,
                    colour: STATUS.critical,
                },
                { label: 'High', value: data.high, colour: STATUS.serious },
                { label: 'Normal', value: data.normal, colour: STATUS.warning },
                { label: 'Low', value: data.low, colour: STATUS.good },
            ]}
        />
    );
}
