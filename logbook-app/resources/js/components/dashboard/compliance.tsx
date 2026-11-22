import { Link } from '@inertiajs/react';
import { ChevronRight, TriangleAlert } from 'lucide-react';
import { Cell, Pie, PieChart, Tooltip } from 'recharts';
import {
    ChartTooltip,
    DataTable,
    STATUS,
} from '@/components/dashboard/chart-kit';
import type { Charts } from '@/components/dashboard/types';
import { index as schedules } from '@/routes/schedules';

type Part = {
    key: string;
    label: string;
    value: number;
    colour: string;
    /** The schedules list filtered to this outcome, when it has one. */
    state?: string;
};

/**
 * How the week's expected entries went: a ring with the on-time rate in
 * the middle, each outcome's count and share beside it, and a way
 * straight to the missed ones.
 */
export function ComplianceChart({ data }: { data: Charts['compliance'] }) {
    const parts: Part[] = [
        {
            key: 'onTime',
            label: 'On time',
            value: data.onTime,
            colour: STATUS.good,
            state: 'completed',
        },
        {
            key: 'late',
            label: 'Late',
            value: data.late,
            colour: STATUS.warning,
            state: 'late',
        },
        {
            key: 'missed',
            label: 'Missed',
            value: data.missed,
            colour: STATUS.critical,
            state: 'missed',
        },
        {
            key: 'open',
            label: 'Still open',
            value: data.open,
            colour: STATUS.neutral,
            state: 'due',
        },
        {
            key: 'excused',
            label: 'Excused',
            value: data.excused,
            colour: '#cbd5e1',
            state: 'excused',
        },
    ];
    const total = parts.reduce((sum, p) => sum + p.value, 0);
    const settled = data.onTime + data.late + data.missed;
    const rate = settled > 0 ? Math.round((data.onTime / settled) * 100) : null;
    const share = (n: number) =>
        total > 0 ? Math.round((n / total) * 100) : 0;

    if (total === 0) {
        return (
            <p className="flex h-56 items-center justify-center text-center text-sm text-muted-foreground">
                No scheduled entries were due in the last 7 days.
            </p>
        );
    }

    return (
        <figure className="flex h-full flex-col">
            <div className="flex flex-wrap items-center gap-x-6 gap-y-4">
                {/* The ring, with the rate in its middle */}
                <div className="relative size-36 shrink-0">
                    <PieChart width={144} height={144}>
                        <Pie
                            data={parts.filter((p) => p.value > 0)}
                            dataKey="value"
                            nameKey="label"
                            cx="50%"
                            cy="50%"
                            innerRadius={52}
                            outerRadius={70}
                            startAngle={90}
                            endAngle={-270}
                            stroke="#ffffff"
                            strokeWidth={2}
                            isAnimationActive={false}
                        >
                            {parts
                                .filter((p) => p.value > 0)
                                .map((p) => (
                                    <Cell key={p.key} fill={p.colour} />
                                ))}
                        </Pie>
                        <Tooltip
                            content={({ active, payload }) => (
                                <ChartTooltip
                                    active={active}
                                    rows={(payload ?? []).map((p) => {
                                        const part = p.payload as Part;

                                        return {
                                            name: part.label,
                                            value: `${part.value} · ${share(part.value)}%`,
                                            colour: part.colour,
                                        };
                                    })}
                                />
                            )}
                        />
                    </PieChart>
                    <div className="pointer-events-none absolute inset-0 grid place-content-center text-center">
                        <span className="text-[1.75rem] leading-8 font-semibold tracking-tight tabular-nums">
                            {rate !== null ? `${rate}%` : '—'}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            on time
                        </span>
                    </div>
                </div>

                {/* Each outcome: colour key, label, count, share */}
                <ul className="grid min-w-[10rem] flex-1 gap-1">
                    {parts.map((p) => (
                        <li key={p.key}>
                            <Link
                                href={
                                    schedules({
                                        query: { state: p.state ?? '' },
                                    }).url
                                }
                                className="-mx-2 flex items-center gap-2 rounded px-2 py-1 text-sm hover:bg-muted"
                            >
                                <span
                                    className="inline-block size-2.5 shrink-0 rounded-sm"
                                    style={{ backgroundColor: p.colour }}
                                    aria-hidden
                                />
                                <span className="min-w-0 flex-1 truncate text-muted-foreground">
                                    {p.label}
                                </span>
                                <span className="font-medium tabular-nums">
                                    {p.value}
                                </span>
                                <span className="w-9 text-right text-xs text-muted-foreground tabular-nums">
                                    {share(p.value)}%
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>

            <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
                {total} expected · {settled} settled
                {rate !== null && ` · ${data.onTime} of ${settled} on time`}
            </p>

            {data.missed > 0 && (
                <Link
                    href={schedules({ query: { state: 'missed' } }).url}
                    className="mt-3 flex items-center gap-2 rounded-md border border-[#f3c4c4] bg-[#fdf0f0] px-3 py-2 text-sm text-[#a51d1d] hover:bg-[#fbe4e4]"
                >
                    <TriangleAlert className="size-4 shrink-0" aria-hidden />
                    <span className="flex-1">
                        {data.missed} {data.missed === 1 ? 'entry' : 'entries'}{' '}
                        missed
                    </span>
                    <span className="inline-flex items-center font-medium">
                        Review
                        <ChevronRight className="size-4" aria-hidden />
                    </span>
                </Link>
            )}

            <DataTable
                caption="Scheduled entries in the last 7 days"
                columns={['Outcome', 'Count']}
                rows={parts.map((p) => [p.label, p.value])}
            />
        </figure>
    );
}
