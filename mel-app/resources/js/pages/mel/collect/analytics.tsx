import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Clock, Inbox, Undo2, Users } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import type { TooltipContentProps } from 'recharts';
import { ChartPanel, Meter } from '@/components/charts';
import { selectClass } from '@/mel/components/fields';
import { formatDate } from '@/mel/lib/mel';
import { analytics } from '@/routes/mel/collect';
import { index as submissions } from '@/routes/mel/collect/submissions';

/*
 * Data collection at a glance: what came in, how its review stands, by form
 * and by collector, and each assignment against its target. Colours are the
 * product's --viz-* ramp (workflow stages), every series is also named in
 * a legend, and every chart has a table view.
 */

type Outcome = 'awaiting' | 'correction' | 'rejected' | 'accepted';
type Counts = Record<Outcome, number>;

type Props = {
    filters: { days: number; form: string | null };
    unit: 'day' | 'week' | 'month';
    totals: Counts & {
        received: number;
        collectors: number;
        acceptanceRate: number | null;
    };
    trend: (Counts & { date: string })[];
    byForm: (Counts & { name: string })[];
    byCollector: { name: string; count: number }[];
    targets: {
        id: string;
        person: string;
        form: string;
        expected: number;
        sent: number;
        endsOn: string | null;
    }[];
    forms: { value: string; label: string }[];
};

const OUTCOMES: { key: Outcome; label: string; fill: string }[] = [
    { key: 'awaiting', label: 'Awaiting review', fill: 'var(--viz-step-1)' },
    { key: 'correction', label: 'Sent back', fill: 'var(--viz-step-2)' },
    { key: 'rejected', label: 'Rejected', fill: 'var(--viz-step-3)' },
    { key: 'accepted', label: 'Accepted', fill: 'var(--viz-step-4)' },
];

const PERIODS = [
    { days: 30, label: '30 days' },
    { days: 90, label: '90 days' },
    { days: 365, label: '12 months' },
];

const AXIS = {
    tick: { fill: 'var(--muted-foreground)', fontSize: 11 },
    tickLine: false,
    axisLine: { stroke: 'var(--viz-baseline)' },
} as const;

const number = (n: number) => n.toLocaleString();

function bucketLabel(date: string, unit: Props['unit']): string {
    const d = new Date(`${date}T00:00:00`);
    if (unit === 'month') {
        return d.toLocaleDateString(undefined, {
            month: 'short',
            year: '2-digit',
        });
    }

    return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}

function Legend() {
    return (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 px-4 pt-3 text-xs text-muted-foreground">
            {OUTCOMES.map((o) => (
                <li key={o.key} className="flex items-center gap-1.5">
                    <span
                        className="size-2.5 rounded-sm"
                        style={{ background: o.fill }}
                        aria-hidden
                    />
                    {o.label}
                </li>
            ))}
        </ul>
    );
}

function OutcomeTooltip({
    active,
    payload,
    label,
}: Partial<TooltipContentProps<number, string>> & { label?: string }) {
    if (!active || !payload?.length) return null;
    const total = payload.reduce((sum, p) => sum + Number(p.value ?? 0), 0);

    return (
        <div className="min-w-40 rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-md">
            <p className="mb-1 font-medium">{label}</p>
            {[...payload].reverse().map((p) => (
                <p key={String(p.dataKey)} className="flex items-center gap-2">
                    <span
                        className="size-2 rounded-sm"
                        style={{ background: p.color }}
                        aria-hidden
                    />
                    <span className="text-muted-foreground">{p.name}</span>
                    <span className="ml-auto tabular-nums">
                        {number(Number(p.value))}
                    </span>
                </p>
            ))}
            <p className="mt-1 flex border-t pt-1 font-medium">
                Total{' '}
                <span className="ml-auto tabular-nums">{number(total)}</span>
            </p>
        </div>
    );
}

function stackedBars(layout: 'horizontal' | 'vertical') {
    return OUTCOMES.map((o, i) => (
        <Bar
            key={o.key}
            dataKey={o.key}
            name={o.label}
            stackId="outcome"
            fill={o.fill}
            stroke="var(--card)"
            strokeWidth={1}
            maxBarSize={layout === 'horizontal' ? 28 : 18}
            radius={
                i === OUTCOMES.length - 1
                    ? layout === 'horizontal'
                        ? [4, 4, 0, 0]
                        : [0, 4, 4, 0]
                    : 0
            }
            isAnimationActive={false}
        />
    ));
}

export default function CollectAnalytics({
    filters,
    unit,
    totals,
    trend,
    byForm,
    byCollector,
    targets,
    forms,
}: Props) {
    const go = (changes: Partial<Props['filters']>) =>
        router.get(
            analytics.url({
                query: {
                    days: changes.days ?? filters.days,
                    form:
                        'form' in changes
                            ? (changes.form ?? undefined)
                            : (filters.form ?? undefined),
                },
            }),
            {},
            { preserveScroll: true, preserveState: true },
        );

    const trendRows = trend.map((row) => ({
        ...row,
        label: bucketLabel(row.date, unit),
    }));
    const mix = OUTCOMES.map((o) => ({ ...o, value: totals[o.key] })).filter(
        (o) => o.value > 0,
    );
    const noData = totals.received === 0;
    const period = PERIODS.find((p) => p.days === filters.days)?.label;

    return (
        <>
            <Head title="Collection analytics" />
            <PageHeader
                title="Collection analytics"
                description="What came in from the field, how its review stands, and who is on target."
                actions={
                    <div className="flex flex-wrap gap-2">
                        <select
                            className={selectClass}
                            value={filters.form ?? ''}
                            aria-label="Form"
                            onChange={(e) =>
                                go({ form: e.target.value || null })
                            }
                        >
                            <option value="">All forms</option>
                            {forms.map((f) => (
                                <option key={f.value} value={f.value}>
                                    {f.label}
                                </option>
                            ))}
                        </select>
                        <div
                            role="group"
                            aria-label="Period"
                            className="inline-flex rounded-md border p-0.5"
                        >
                            {PERIODS.map((p) => (
                                <button
                                    key={p.days}
                                    type="button"
                                    aria-pressed={filters.days === p.days}
                                    onClick={() => go({ days: p.days })}
                                    className={`rounded px-2.5 py-1 text-sm ${filters.days === p.days ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'}`}
                                >
                                    {p.label}
                                </button>
                            ))}
                        </div>
                    </div>
                }
            />

            <div className="grid gap-3">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                    <StatCard
                        label="Submissions"
                        value={number(totals.received)}
                        hint={`Last ${period}`}
                        icon={Inbox}
                        href={submissions({ query: { view: 'all' } })}
                    />
                    <StatCard
                        label="Accepted"
                        value={number(totals.accepted)}
                        hint={
                            totals.acceptanceRate === null
                                ? 'None reviewed yet'
                                : `${totals.acceptanceRate}% of those reviewed`
                        }
                        icon={CheckCircle2}
                        accent="emerald"
                        href={submissions({ query: { view: 'accepted' } })}
                    />
                    <StatCard
                        label="Awaiting review"
                        value={number(totals.awaiting)}
                        icon={Clock}
                        accent="amber"
                        href={submissions({ query: { view: 'to_review' } })}
                    />
                    <StatCard
                        label="Sent back or rejected"
                        value={number(totals.correction + totals.rejected)}
                        hint={`${number(totals.correction)} sent back · ${number(totals.rejected)} rejected`}
                        icon={Undo2}
                        accent="red"
                    />
                    <StatCard
                        label="Collectors"
                        value={number(totals.collectors)}
                        hint="Sent at least one"
                        icon={Users}
                        accent="violet"
                    />
                </div>

                <div className="grid gap-3 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <ChartPanel
                        title="Submissions over time"
                        description={`Per ${unit}, by where their review stands.`}
                        columns={
                            [
                                unit === 'day'
                                    ? 'Day'
                                    : unit === 'week'
                                      ? 'Week of'
                                      : 'Month',
                                ...OUTCOMES.map((o) => o.label),
                            ] as [string, string, ...string[]]
                        }
                        rows={trendRows.map((r) => [
                            r.label,
                            ...OUTCOMES.map((o) => number(r[o.key])),
                        ])}
                        empty={
                            noData
                                ? 'Nothing came in during this period.'
                                : null
                        }
                    >
                        <Legend />
                        <div className="h-72 px-2 pt-2 pb-3">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={trendRows}
                                    margin={{ top: 8, right: 8, left: -12 }}
                                >
                                    <CartesianGrid
                                        vertical={false}
                                        stroke="var(--viz-grid)"
                                    />
                                    <XAxis
                                        dataKey="label"
                                        {...AXIS}
                                        interval="preserveStartEnd"
                                        minTickGap={16}
                                    />
                                    <YAxis
                                        {...AXIS}
                                        axisLine={false}
                                        allowDecimals={false}
                                        width={40}
                                    />
                                    <Tooltip
                                        cursor={{ fill: 'var(--muted)' }}
                                        content={<OutcomeTooltip />}
                                    />
                                    {stackedBars('horizontal')}
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </ChartPanel>

                    <ChartPanel
                        title="Review status"
                        description="Everything that came in during the period."
                        columns={['Status', 'Submissions', 'Share']}
                        rows={OUTCOMES.map((o) => [
                            o.label,
                            number(totals[o.key]),
                            `${totals.received ? Math.round((totals[o.key] / totals.received) * 100) : 0}%`,
                        ])}
                        empty={
                            noData
                                ? 'Nothing came in during this period.'
                                : null
                        }
                    >
                        <div className="relative h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={mix}
                                        dataKey="value"
                                        nameKey="label"
                                        innerRadius="62%"
                                        outerRadius="88%"
                                        paddingAngle={mix.length > 1 ? 2 : 0}
                                        cornerRadius={4}
                                        stroke="var(--card)"
                                        strokeWidth={2}
                                        isAnimationActive={false}
                                    >
                                        {mix.map((o) => (
                                            <Cell key={o.key} fill={o.fill} />
                                        ))}
                                    </Pie>
                                    <Tooltip
                                        formatter={(v) => number(Number(v))}
                                        contentStyle={{
                                            background: 'var(--popover)',
                                            border: '1px solid var(--border)',
                                            borderRadius: 6,
                                            fontSize: 12,
                                        }}
                                    />
                                </PieChart>
                            </ResponsiveContainer>
                            <div className="pointer-events-none absolute inset-0 grid place-content-center text-center">
                                <span className="text-2xl font-semibold tabular-nums">
                                    {totals.acceptanceRate === null
                                        ? '—'
                                        : `${totals.acceptanceRate}%`}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    accepted
                                </span>
                            </div>
                        </div>
                        <ul className="grid gap-1.5 px-4 pb-4 text-sm">
                            {OUTCOMES.map((o) => (
                                <li
                                    key={o.key}
                                    className="flex items-center gap-2"
                                >
                                    <span
                                        className="size-2.5 rounded-sm"
                                        style={{ background: o.fill }}
                                        aria-hidden
                                    />
                                    <span className="text-muted-foreground">
                                        {o.label}
                                    </span>
                                    <span className="ml-auto tabular-nums">
                                        {number(totals[o.key])}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </ChartPanel>
                </div>

                <div className="grid gap-3 xl:grid-cols-2">
                    <ChartPanel
                        title="By form"
                        description="The forms with the most submissions in the period."
                        columns={
                            ['Form', ...OUTCOMES.map((o) => o.label)] as [
                                string,
                                string,
                                ...string[],
                            ]
                        }
                        rows={byForm.map((r) => [
                            r.name,
                            ...OUTCOMES.map((o) => number(r[o.key])),
                        ])}
                        empty={
                            byForm.length === 0
                                ? 'No forms with submissions in this period.'
                                : null
                        }
                    >
                        <Legend />
                        <div
                            className="px-2 pt-2 pb-3"
                            style={{ height: 48 + byForm.length * 36 }}
                        >
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={byForm}
                                    layout="vertical"
                                    margin={{ top: 4, right: 16, left: 8 }}
                                >
                                    <CartesianGrid
                                        horizontal={false}
                                        stroke="var(--viz-grid)"
                                    />
                                    <XAxis
                                        type="number"
                                        {...AXIS}
                                        allowDecimals={false}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="name"
                                        {...AXIS}
                                        width={130}
                                        tickFormatter={(v: string) =>
                                            v.length > 18
                                                ? `${v.slice(0, 17)}…`
                                                : v
                                        }
                                    />
                                    <Tooltip
                                        cursor={{ fill: 'var(--muted)' }}
                                        content={<OutcomeTooltip />}
                                    />
                                    {stackedBars('vertical')}
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </ChartPanel>

                    <ChartPanel
                        title="Most active collectors"
                        description="Submissions sent in the period."
                        columns={['Collector', 'Submissions']}
                        rows={byCollector.map((r) => [r.name, number(r.count)])}
                        empty={
                            byCollector.length === 0
                                ? 'Nobody sent anything in this period.'
                                : null
                        }
                    >
                        <div
                            className="px-2 pt-3 pb-3"
                            style={{ height: 32 + byCollector.length * 34 }}
                        >
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={byCollector}
                                    layout="vertical"
                                    margin={{ top: 4, right: 40, left: 8 }}
                                >
                                    <XAxis
                                        type="number"
                                        hide
                                        allowDecimals={false}
                                    />
                                    <YAxis
                                        type="category"
                                        dataKey="name"
                                        {...AXIS}
                                        axisLine={false}
                                        width={130}
                                    />
                                    <Tooltip
                                        cursor={{ fill: 'var(--muted)' }}
                                        formatter={(v) => [
                                            number(Number(v)),
                                            'Submissions',
                                        ]}
                                        contentStyle={{
                                            background: 'var(--popover)',
                                            border: '1px solid var(--border)',
                                            borderRadius: 6,
                                            fontSize: 12,
                                        }}
                                    />
                                    <Bar
                                        dataKey="count"
                                        fill="var(--viz-step-3)"
                                        radius={[0, 4, 4, 0]}
                                        maxBarSize={18}
                                        isAnimationActive={false}
                                        label={{
                                            position: 'right',
                                            fill: 'var(--foreground)',
                                            fontSize: 11,
                                        }}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    </ChartPanel>
                </div>

                <Panel>
                    <PanelHeader
                        title="Assignments against their target"
                        description="Live assignments with an expected number of submissions, furthest behind first. Counts everything sent except rejected, at any time."
                    />
                    <PanelBody>
                        {targets.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No live assignment has an expected number of
                                submissions. Set one on{' '}
                                <Link
                                    className="underline"
                                    href="/mel/collect/assignments"
                                >
                                    Field assignments
                                </Link>
                                .
                            </p>
                        ) : (
                            <ul className="grid gap-3 md:grid-cols-2">
                                {targets.map((t) => {
                                    const percent = Math.round(
                                        (t.sent / t.expected) * 100,
                                    );

                                    return (
                                        <li key={t.id} className="grid gap-1.5">
                                            <div className="flex items-baseline gap-2 text-sm">
                                                <span className="truncate font-medium">
                                                    {t.person}
                                                </span>
                                                <span className="truncate text-xs text-muted-foreground">
                                                    {t.form}
                                                    {t.endsOn &&
                                                        ` · until ${formatDate(t.endsOn)}`}
                                                </span>
                                                <span className="ml-auto shrink-0 tabular-nums">
                                                    {number(t.sent)} /{' '}
                                                    {number(t.expected)}
                                                </span>
                                            </div>
                                            <Meter
                                                percent={percent}
                                                label={`${t.person}: ${t.sent} of ${t.expected}`}
                                                fill={
                                                    percent >= 100
                                                        ? 'var(--viz-step-4)'
                                                        : 'var(--viz-step-2)'
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
        </>
    );
}
